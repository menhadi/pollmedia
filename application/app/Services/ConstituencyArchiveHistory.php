<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConstituencyArchiveHistory
{
    /** General-category annotations are metadata; preserve the original source labels in results. */
    public static function generalCategoryNames(string $name): array
    {
        $base = preg_replace('/\s*\(gen\)$/i', '', mb_strtolower(trim($name)));

        return [$base, $base.' (gen)', $base.'(gen)'];
    }

    /**
     * Exact official spelling variation, not fuzzy matching or a boundary equivalence claim.
     * 2022 district notice: https://imphaleast.nic.in/document/notice-of-election-in-respect-of-4-kshetrigao-ac/
     * CEO result sheet: https://ceomanipur.nic.in/ResultSheets/StateLegislativeAssembly/2022/04.pdf
     * Additional alias records must also have AC code 4; original-name matches remain unchanged.
     */
    public static function manipurAssemblyAliasNames(string $kind, ?string $state, string $name): array
    {
        if ($kind !== 'ac' || mb_strtolower(trim($state ?? '')) !== 'manipur') {
            return [];
        }
        $base = self::generalCategoryNames($name)[0];
        $alias = match ($base) {
            'kshetrigao' => 'khetrigao',
            'khetrigao' => 'kshetrigao',
            default => null,
        };

        return $alias === null ? [] : self::generalCategoryNames($alias);
    }

    /**
     * Explicit historical spelling links; raw labels and year-specific boundaries remain unchanged.
     * Naoriya is corroborated by official summaries (PDF p31) and candidate continuity:
     * https://old.eci.gov.in/files/file/3704-manipur-1974/
     * https://old.eci.gov.in/files/file/3705-manipur-1980/
     * Nambol/Nanbol is corroborated by candidate continuity in those reports and:
     * https://old.eci.gov.in/files/file/3703-manipur-1972/
     * Bishenpur directly links to Bishnupur in the same official 2017 election:
     * https://ceomanipur.nic.in/Affidavits/AssemblyElection/2017/ACList.html
     * https://ceomanipur.nic.in/Affidavits/AssemblyElection/2017/SE/26/AC26.htm
     * Historical identity inference is not a delimitation crosswalk.
     *
     * @return list<array{names: array, code: int, edition?: string, after?: int}>
     */
    public static function historyAliasRules(string $kind, ?string $state, string $name): array
    {
        $names = self::manipurAssemblyAliasNames($kind, $state, $name);
        if ($names !== []) {
            return [['names' => $names, 'code' => 4]];
        }
        if ($kind !== 'ac' || mb_strtolower(trim($state ?? '')) !== 'manipur') {
            return [];
        }

        return match (self::generalCategoryNames($name)[0]) {
            'bishenpur' => [['names' => self::generalCategoryNames('bishnupur'), 'code' => 26]],
            'bishnupur' => [['names' => self::generalCategoryNames('bishenpur'), 'code' => 26]],
            'nambol' => [['names' => self::generalCategoryNames('nanbol'), 'code' => 24, 'edition' => '48bac24675875f468956cf9e']],
            'nanbol' => [
                ['names' => self::generalCategoryNames('nambol'), 'code' => 24, 'after' => 1974],
                ['names' => self::generalCategoryNames('nambol'), 'code' => 25, 'edition' => '496d7edbfe44e6b6cf4b312b'],
            ],
            'naoriya pakhanglakpa' => [['names' => self::generalCategoryNames('naoriya pakanglakpa'), 'code' => 21, 'edition' => '48bac24675875f468956cf9e']],
            'naoriya pakanglakpa' => [['names' => self::generalCategoryNames('naoriya pakhanglakpa'), 'code' => 21, 'after' => 1974]],
            default => [],
        };
    }

    /** Read preserved imported editions omitted from the search index; never write or extract data. */
    public function missingEntries(string $kind, ?string $state, ?string $name, Collection $indexed, ?string $editionOnly = null): Collection
    {
        if (! Schema::hasTable('archive_json_files')) {
            return collect();
        }
        $files = DB::table('archive_json_files')->where('category', 'election-archive')->where('path', 'like', 'election-archive/%/extraction.json')
            ->when($editionOnly, fn ($query) => $query->where('path_hash', hash('sha256', 'election-archive/'.$editionOnly.'/extraction.json')))->get(['path', 'sha256']);
        if ($files->isEmpty()) {
            return collect();
        }
        $catalogue = app(ElectionArchive::class);
        $editions = [];
        foreach ($catalogue->catalogue()[$kind] as [$label, $url]) {
            $editions[substr(hash('sha256', $url), 0, 24)] = ['url' => $url, 'year' => (int) substr($label, 0, 4), 'label' => $label];
        }
        if ($kind === 'ac') {
            foreach ($catalogue->nationalAssemblyEntries() as $source) {
                $editions[substr(hash('sha256', $source['url']), 0, 24)] = ['url' => $source['url'], 'year' => (int) substr($source['label'], 0, 4), 'label' => $source['label'], 'state' => $source['state']];
            }
        }
        $known = $indexed->pluck('edition_id')->all();
        $knownRows = $indexed->map(fn ($row) => $row->edition_id.':'.$row->record_code)->all();
        $result = collect();
        foreach ($files as $file) {
            if (! preg_match('~^election-archive/([a-f0-9]{24})/extraction\.json$~', $file->path, $match)) {
                continue;
            }
            $id = $match[1];
            $edition = $editions[$id] ?? null;
            if (! $edition || ($name !== null && in_array($id, $known, true))) {
                continue;
            }
            $key = 'constituency-source-history-v7:'.hash('sha256', json_encode([$file->sha256, $kind, $state, $name]));
            $rows = Cache::remember($key, 900, function () use ($file, $kind, $state, $name, $id, $edition): array {
                $disk = app(ArchiveFiles::class);
                $body = $disk->get($file->path);
                abort_unless(hash_equals($file->sha256, hash('sha256', $body)), 409, 'Historical extraction checksum differs.');
                $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                abort_unless(($data['source_url'] ?? null) === $edition['url'] && ($data['kind'] ?? null) === $kind && ($data['year'] ?? null) === $edition['year'], 409, 'Historical extraction edition differs.');
                $manifest = json_decode($disk->get('election-archive/'.$id.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                $hashes = collect($manifest['files'] ?? [])->pluck('sha256', 'file');
                abort_unless(($manifest['url'] ?? null) === $edition['url'] && preg_match('/^[a-f0-9]{64}$/', $data['source_sha256'] ?? '') && $hashes->get($data['source_file'] ?? null) === $data['source_sha256'], 409, 'Historical source manifest differs.');
                foreach ($data['additional_sources'] ?? [] as $source) {
                    abort_unless($hashes->get($source['file'] ?? null) === ($source['sha256'] ?? null), 409, 'Historical additional source differs.');
                }
                $matches = [];
                $wantedState = $state !== null ? $this->stateName($state) : null;
                $wantedSeat = $name !== null ? $this->seatName($name) : null;
                $aliases = $name !== null ? self::historyAliasRules($kind, $state, $name) : [];
                $stateNames = [];
                $stateCodes = [];
                foreach ($data['records'] ?? [] as $record) {
                    $recordName = $record['constituency_name'] ?? $record['name'] ?? '';
                    $code = $record['state_code'] ?? '';
                    $recordState = $record['state_name'] ?? ($edition['state'] ?? ($kind === 'ac' ? 'Uttar Pradesh' : ($edition['year'] >= 1977 ? ($stateCodes[$code] ??= ElectionPlaceIdentity::state($code)) : '')));
                    // Old state codes have different meanings. Use original state names, never today's code list.
                    $aliasMatches = collect($aliases)->contains(fn (array $rule): bool => (int) ($record['code'] ?? 0) === $rule['code']
                        && (! isset($rule['edition']) || $id === $rule['edition'])
                        && (! isset($rule['after']) || $edition['year'] > $rule['after'])
                        && in_array(mb_strtolower(trim($recordName)), $rule['names'], true));
                    if (($wantedState !== null && ($stateNames[$recordState] ??= $this->stateName($recordState)) !== $wantedState) || ($wantedSeat !== null && $this->seatName($recordName) !== $wantedSeat && ! $aliasMatches)) {
                        continue;
                    }
                    $matches[] = ['edition_id' => $id, 'record_code' => (int) $record['code'], 'kind' => $kind, 'year' => $edition['year'], 'edition_label' => $edition['label'], 'state_label' => $recordState, 'constituency_name' => $recordName, 'status' => $record['status'] ?? 'needs_review', 'has_warning' => ($record['status'] ?? '') !== 'validated', 'candidate_count' => count($record['candidates'] ?? []), 'extraction_sha256' => $file->sha256];
                }

                return $matches;
            });
            foreach ($rows as $row) {
                if (! in_array($id.':'.$row['record_code'], $knownRows, true)) {
                    $result->push((object) $row);
                }
            }
        }

        return $result;
    }

    private function stateName(string $value): string
    {
        return str_replace('&', 'and', mb_strtolower(ElectionPlaceIdentity::state($value)));
    }

    private function seatName(string $value): string
    {
        $value = self::generalCategoryNames($value)[0];
        $value = preg_replace('/\((?:sc|st)\)/i', '', mb_strtolower(trim($value)));
        $parts = explode('/', $value);

        return preg_replace('/[^\pL\pN]+/u', '', end($parts));
    }
}
