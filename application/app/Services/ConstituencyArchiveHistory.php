<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConstituencyArchiveHistory
{
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
            $key = 'constituency-source-history-v2:'.hash('sha256', json_encode([$file->sha256, $kind, $state, $name]));
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
                foreach ($data['records'] ?? [] as $record) {
                    $recordName = $record['constituency_name'] ?? $record['name'] ?? '';
                    $recordState = $record['state_name'] ?? ($edition['state'] ?? ($kind === 'ac' ? 'Uttar Pradesh' : ($edition['year'] >= 1977 ? ElectionPlaceIdentity::state($record['state_code'] ?? '') : '')));
                    // Old state codes have different meanings. Use original state names, never today's code list.
                    if (($state !== null && $this->stateName($recordState) !== $this->stateName($state)) || ($name !== null && $this->seatName($recordName) !== $this->seatName($name))) {
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
        $value = preg_replace('/\((?:sc|st)\)/i', '', mb_strtolower(trim($value)));
        $parts = explode('/', $value);

        return preg_replace('/[^\pL\pN]+/u', '', end($parts));
    }
}
