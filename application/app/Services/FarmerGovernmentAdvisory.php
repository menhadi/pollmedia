<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class FarmerGovernmentAdvisory
{
    private const BASE = 'https://mausam.imd.gov.in/responsive/';

    public function collect(array $location): array
    {
        $district = $this->district($location);
        if ($district['status'] === 'available') {
            return $district + ['coverage_level' => 'district'];
        }

        return $this->state($location, $district);
    }

    private function district(array $location): array
    {
        $scopes = collect($location['scopes'] ?? []);
        $state = $scopes->firstWhere('kind', 'state')['name'] ?? null;
        $district = $scopes->firstWhere('kind', 'district')['name'] ?? null;
        $result = ['kind' => 'government_advisory', 'status' => 'unavailable', 'provider' => 'IMD / GKMS', 'scope' => 'District bulletin, not a field measurement', 'state' => $state, 'district' => $district, 'retrieved_at' => now()->toIso8601String(), 'source_url' => self::BASE.'agromet_adv_ser_district_current_en.php'];
        if (! $state || ! $district) {
            return $result + ['note' => 'Confirmed state and district identity is missing.'];
        }
        try {
            $stateName = $this->match($this->options($this->get($result['source_url'])), $state);
            if (! $stateName) {
                return $result + ['note' => 'No unique state match in the official bulletin directory.'];
            }
            $directory = self::BASE.'agrometinformation/district_current_en_get.php?'.http_build_query(['s' => $stateName, 'step1' => 'true']);
            $districtName = $this->match($this->options($this->get($directory)), $district);
            if (! $districtName) {
                return $result + ['note' => 'No unique district match within the confirmed state in the official bulletin directory.'];
            }
            $source = 'https://imdagrimet.gov.in/Services/DistrictBulletin.php?'.http_build_query(['state' => $stateName, 'district' => $districtName, 'language' => 'English']);
            $result['source_url'] = $source;
            $cached = Cache::remember('farmer-imd-pdf-v1:'.hash('sha256', $source), now()->addMinutes(15), function () use ($source): array {
                $pdf = $this->get($source);
                if (! str_starts_with($pdf, '%PDF-')) {
                    throw new \RuntimeException('No readable district bulletin.');
                }

                return ['text' => $this->extract($pdf), 'sha256' => hash('sha256', $pdf), 'retrieved_at' => now()->toIso8601String()];
            });
            $result['retrieved_at'] = $cached['retrieved_at'];

            return $this->validateBulletin($cached['text'], $districtName, $result + ['sha256' => $cached['sha256']]);
        } catch (\Throwable) {
            return $result + ['note' => 'The official service has no readable district bulletin or could not be reached. Retry later.'];
        }
    }

    public function validateBulletin(string $text, string $district, array $result): array
    {
        $text = preg_replace('/[\\t ]+/', ' ', str_replace("\r", '', $text));
        if (! preg_match('/\\b'.preg_quote($district, '/').'\\b/iu', mb_substr($text, 0, 2000))) {
            return $result + ['note' => 'Bulletin district does not match the confirmed district.'];
        }
        if (! preg_match('/(?:Issued\\s+On|Date)\\s*:?\\s*(\\d{4}-\\d{2}-\\d{2}|\\d{2}\\.\\d{2}\\.\\d{4})/i', $text, $date)) {
            return $result + ['note' => 'Bulletin issue date could not be verified.'];
        }
        $issue = CarbonImmutable::createFromFormat(str_contains($date[1], '-') ? '!Y-m-d' : '!d.m.Y', $date[1], 'Asia/Kolkata');
        $until = null;
        if (preg_match('/Valid Till 08:30 IST of the next 5 days/i', $text)) {
            $until = $issue->addDays(5)->setTime(8, 30);
        } elseif (preg_match('/valid until\\s+(\\d{2})[.:](\\d{2})\\s+hrs\\s+of\\s+(\\d{2}\\.\\d{2}\\.\\d{4})/i', $text, $valid)) {
            $until = CarbonImmutable::createFromFormat('!d.m.Y', $valid[3], 'Asia/Kolkata')->setTime((int) $valid[1], (int) $valid[2]);
        } elseif (preg_match('/Valid for the period from\s+(\d{2}\.\d{2}\.\d{4})\s*\(\d{4}\s+IST\)\s*to\s+(\d{2}\.\d{2}\.\d{4})\s*\((\d{4})\s+IST\)/i', $text, $valid)) {
            $until = CarbonImmutable::createFromFormat('!d.m.YHi', $valid[2].$valid[3], 'Asia/Kolkata');
        }
        $result['issued_at'] = $issue->toDateString();
        $result['valid_until'] = $until?->toIso8601String();
        if (! $until || $issue->isFuture() || $issue->lt(now()->subDays(7)->startOfDay()) || $until->isPast() || $until->gt($issue->addDays(7))) {
            return $result + ['note' => 'Bulletin is expired or its validity could not be verified.'];
        }
        if ($issue->lt(now('Asia/Kolkata')->startOfDay())) {
            $text = preg_replace('/Weather Warnings\\s*\\(Valid Till.*?(?=General Advisory:)/si', '', $text);
            $text = preg_replace('/Forecast Summary:.*?24 hours.*?(?=General Advisory:|Weather Warnings)/si', '', $text);
        }
        $result['status'] = 'available';
        $result['text'] = mb_substr(preg_replace('/\\s+/u', ' ', $text), 0, 24000);
        $result['note'] = 'Published district weather forecast and crop advice. Apply only to matching crop, stage and local conditions. AI translation, not government endorsed. Saved report is a dated snapshot.';

        return $result;
    }

    private function state(array $location, array $districtResult): array
    {
        $state = $districtResult['state'];
        if (! $state) {
            return $districtResult;
        }
        try {
            $page = self::BASE.'agromet_adv_ser_state_current.php';
            $name = $this->match($this->options($this->get($page)), $state);
            if (! $name) {
                return $districtResult + ['fallback_note' => 'No unique state bulletin directory match.'];
            }
            $links = $this->get(self::BASE.'agrometinformation/getimageenglish_state.php?'.http_build_query(['s' => $name]));
            $doc = new \DOMDocument;
            @$doc->loadHTML($links);
            $sources = [];
            foreach ($doc->getElementsByTagName('a') as $link) {
                $url = preg_replace('/^http:/', 'https:', $link->getAttribute('href'));
                if (str_starts_with($url, 'https://mausam.imd.gov.in/imd_latest/contents/agromet/agromet-data/state/current/english-pdf/')) {
                    $sources[] = str_replace(' ', '%20', $url);
                }
            }
            if ($name === 'Uttar Pradesh') {
                $sources[] = 'https://mausam.imd.gov.in/lucknow/mcdata/agromet.pdf';
            }
            foreach (array_unique($sources) as $source) {
                try {
                    $cached = Cache::remember('farmer-state-pdf-v1:'.hash('sha256', $source), now()->addMinutes(15), function () use ($source): array {
                        $pdf = $this->get($source);
                        if (! str_starts_with($pdf, '%PDF-')) {
                            throw new \RuntimeException('No readable state bulletin.');
                        }

                        return ['text' => $this->extract($pdf), 'sha256' => hash('sha256', $pdf), 'retrieved_at' => now()->toIso8601String()];
                    });
                    $raw = $cached['text'];
                    if ($name === 'Uttar Pradesh' && preg_match('/UTTAR\s+PRADESH\s+COMPOSITE\s+STATE/i', $raw, $header, PREG_OFFSET_CAPTURE)) {
                        $raw = substr($raw, $header[0][1]);
                    }
                    $result = array_replace($districtResult, ['coverage_level' => 'state', 'scope' => 'State composite bulletin; shared advisory group, not an independent district or field forecast', 'source_url' => $source, 'sha256' => $cached['sha256'], 'retrieved_at' => $cached['retrieved_at'], 'district_unavailable_reason' => $districtResult['note'] ?? null]);
                    unset($result['note'], $result['issued_at'], $result['valid_until'], $result['text']);
                    $result = $this->validateBulletin($raw, $name, $result);
                    if ($result['status'] !== 'available' && isset($result['issued_at'], $result['valid_until']) && CarbonImmutable::parse($result['valid_until'])->isPast() && CarbonImmutable::parse($result['valid_until'])->gte(now()->subDays(2)) && CarbonImmutable::parse($result['issued_at'])->gte(now()->subDays(7)->startOfDay())) {
                        $result['status'] = 'reference_only';
                        $result['reference_only'] = true;
                    }
                    if (! in_array($result['status'], ['available', 'reference_only'], true)) {
                        continue;
                    }
                    if ($name === 'Uttar Pradesh') {
                        preg_match_all('/NODAL OFFICER.*?(?=NODAL OFFICER|\z)/si', $raw, $groups);
                        $hits = array_values(array_filter($groups[0], fn ($group) => preg_match('/\b'.preg_quote($districtResult['district'], '/').'\b/i', substr($group, 0, 700))));
                        if (count($hits) !== 1) {
                            continue;
                        }
                        $result['text'] = preg_replace('/\s+/u', ' ', trim($hits[0]));
                        $result['state_advice_text'] = $result['text'];
                        $result['advisory_group'] = trim(strtok($hits[0], "\n"));
                        $result['weather_status'] = 'No independent district forecast; state zone-table values are not assigned to this field.';
                    }
                    $result['coverage_notice'] = 'State-level coverage: '.$state.'. The district bulletin for '.$districtResult['district'].' is unavailable. Advice comes from the state composite bulletin'.(isset($result['advisory_group']) ? ' and its shared advisory group explicitly listing this district' : '').'. It is not an independent district forecast or a field measurement.';
                    if ($result['reference_only'] ?? false) {
                        $result['coverage_notice'] .= ' Latest published reference: its stated validity ended '.$result['valid_until'].'. General crop-planning context only; not current weather or time-sensitive advice. Check the latest bulletin before acting.';
                    }
                    $result['note'] = $result['coverage_notice'];

                    return $result;
                } catch (\Throwable) {
                    continue;
                }
            }

            return $districtResult + ['fallback_note' => 'No current, readable and corroborated state bulletin was available.'];
        } catch (\Throwable) {
            return $districtResult + ['fallback_note' => 'The state bulletin service could not be reached.'];
        }
    }

    private function get(string $url): string
    {
        return Cache::remember('farmer-imd-http-v1:'.hash('sha256', $url), now()->addMinutes(10), function () use ($url): string {
            $timeout = str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf') ? 45 : 15;
            $response = Http::connectTimeout(5)->timeout($timeout)->withOptions(['allow_redirects' => false])->get($url);
            if (! $response->successful() || strlen($response->body()) > 8 * 1024 * 1024) {
                throw new \RuntimeException('Government service unavailable.');
            }

            return $response->body();
        });
    }

    private function options(string $html): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML($html);
        $values = [];
        foreach ($doc->getElementsByTagName('option') as $option) {
            $value = trim($option->getAttribute('value'));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function match(array $values, string $name): ?string
    {
        $normalize = fn (string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower(str_replace('&', 'and', $s)));
        $hits = array_values(array_filter($values, fn ($value) => $normalize($value) === $normalize($name)));

        return count($hits) === 1 ? $hits[0] : null;
    }

    protected function extract(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'farmer-imd-');
        try {
            file_put_contents($path, $pdf);
            chmod($path, 0600);
            $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
            $process->setTimeout(10)->mustRun();

            return $process->getOutput();
        } finally {
            @unlink($path);
        }
    }
}
