<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ElectionCatalogueMonitor
{
    public const URL = 'https://www.eci.gov.in/eci-backend/public/api/get-election-data?page_seo_name=statistical-reports';

    private const ROOT = 'election-catalogue-monitor';

    public function check(): array
    {
        $bundle = config('source-monitor.ca_bundle');
        $response = Http::connectTimeout(10)->timeout(30)->withOptions([
            'allow_redirects' => false,
            'verify' => $bundle && is_file($bundle) ? $bundle : true,
        ])->get(self::URL);
        $body = $response->body();
        if (! $response->successful() || strlen($body) > 2000000) {
            throw new RuntimeException('The official ECI catalogue could not be checked.');
        }

        $entries = $this->entries($body);
        $disk = Storage::disk('local');
        $sha256 = hash('sha256', $body);
        $rawPath = self::ROOT.'/raw/'.$sha256.'.json';
        if (! $disk->exists($rawPath)) {
            $disk->put($rawPath, $body);
        } elseif (! hash_equals($sha256, hash('sha256', $disk->get($rawPath)))) {
            throw new RuntimeException('The saved ECI catalogue has a checksum conflict.');
        }

        $baselinePath = self::ROOT.'/baseline.json';
        $previousCheck = $this->latest();
        if (! $disk->exists($baselinePath)) {
            $disk->put($baselinePath, json_encode(['source_sha256' => $sha256, 'by_election_urls' => array_values(array_map(
                fn (array $entry): string => $entry['url'],
                array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'be')
            ))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $baseline = json_decode($disk->get($baselinePath), true, 512, JSON_THROW_ON_ERROR);
        if (! isset($baseline['source_sha256']) && $previousCheck && $disk->exists($previousCheck['raw_path'])) {
            $previousEntries = $this->entries($disk->get($previousCheck['raw_path']));
            $baseline = ['source_sha256' => $previousCheck['source_sha256'], 'by_election_urls' => array_values(array_map(
                fn (array $entry): string => $entry['url'],
                array_filter($previousEntries, fn (array $entry): bool => $entry['kind'] === 'be')
            ))];
            $disk->put($baselinePath, json_encode($baseline, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $approved = $this->approvedUrls();
        $pending = array_values(array_filter($entries, fn (array $entry): bool => ! in_array(
            $entry['url'],
            $entry['kind'] === 'be' ? ($baseline['by_election_urls'] ?? []) : $approved[$entry['kind']],
            true
        )));
        $summary = [
            'checked_at' => now()->toIso8601String(),
            'source_url' => self::URL,
            'source_sha256' => $sha256,
            'raw_path' => $rawPath,
            'counts' => array_count_values(array_column($entries, 'kind')),
            'pending_count' => count($pending),
            'pending' => $pending,
        ];
        $disk->put(self::ROOT.'/latest.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $summary;
    }

    public function latest(): ?array
    {
        $disk = Storage::disk('local');
        $path = self::ROOT.'/latest.json';

        return $disk->exists($path) ? json_decode($disk->get($path), true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function entries(string $body): array
    {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $html = $payload['cmsPagesData']['page_content'] ?? null;
        if (! is_string($html) || strlen($html) > 2000000) {
            throw new RuntimeException('The ECI catalogue has no expected page content.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $entries = [];
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $url = $this->officialUrl($anchor->getAttribute('href'));
            if ($url === null) {
                continue;
            }
            $label = trim(preg_replace('/\s+/u', ' ', $anchor->textContent));
            $path = parse_url($url, PHP_URL_PATH);
            $kind = null;
            $state = null;
            if (preg_match('~/(?:general-election-to-loksabha-\d{4}-statistical-reports|files/(?:category|file)/\d+-general-election-\d{4}(?:-|/))~i', $path)) {
                $kind = 'pc';
            } elseif (preg_match('~/statistical-report/be/~i', $path)
                || ($this->byElectionTable($xpath, $anchor) && preg_match('/(?:19|20)\d{2}/', $label))) {
                $kind = 'be';
            } else {
                $row = $xpath->query('ancestor::tr[1]', $anchor)->item(0);
                $cell = $row ? $xpath->query('./td[1]', $row)->item(0) : null;
                $state = $cell ? trim(preg_replace('/\s+/u', ' ', $cell->textContent)) : null;
                if ($state && ! preg_match('/\d|general election|lok sabha|parliament/i', $state)
                    && preg_match('/^(?:19|20)\d{2}(?:\s*\(.*\))?$/', $label)) {
                    $kind = 'ac';
                }
            }
            if ($kind === null) {
                continue;
            }
            preg_match('/(?:19|20)\d{2}/', $label.' '.$path, $match);
            $entry = ['kind' => $kind, 'year' => isset($match[0]) ? (int) $match[0] : null,
                'label' => $label, 'url' => $url];
            if ($kind === 'ac') {
                $entry['state_label'] = $state;
            }
            $entries[$kind.'|'.($state ?? '').'|'.$url] = $entry;
        }
        $counts = array_count_values(array_column($entries, 'kind'));
        if (($counts['ac'] ?? 0) === 0 || ($counts['pc'] ?? 0) === 0 || ($counts['be'] ?? 0) === 0) {
            throw new RuntimeException('The ECI catalogue structure changed; PC, AC or by-election links were not found.');
        }

        return array_values($entries);
    }

    private function officialUrl(string $href): ?string
    {
        if ($href === '' || $href === '#') {
            return null;
        }
        $url = str_starts_with($href, '/') ? 'https://www.eci.gov.in'.$href : $href;
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! in_array(strtolower($parts['host'] ?? ''), ['www.eci.gov.in', 'old.eci.gov.in'], true)) {
            return null;
        }

        return $url;
    }

    private function byElectionTable(DOMXPath $xpath, \DOMNode $anchor): bool
    {
        $table = $xpath->query('ancestor::table[1]', $anchor)->item(0);

        return $table !== null && $xpath->query('.//a[contains(@href, "/statistical-report/be/")]', $table)->length > 0;
    }

    private function approvedUrls(): array
    {
        $assembly = json_decode(file_get_contents(database_path('fixtures/eci-assembly-national.json')), true, 512, JSON_THROW_ON_ERROR);
        $elections = json_decode(file_get_contents(database_path('fixtures/eci-election-archive.json')), true, 512, JSON_THROW_ON_ERROR);

        $runtime = app(ElectionRuntimeCatalogue::class)->entries();

        return [
            'ac' => array_merge(array_column($assembly['entries'], 'url'), array_column(array_filter($runtime, fn (array $entry): bool => $entry['kind'] === 'ac'), 'url')),
            'pc' => array_column($elections['pc'], 1),
        ];
    }
}
