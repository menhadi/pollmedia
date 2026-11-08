<?php

namespace App\Services;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;

class HistoricalElectionArchive
{
    private const RECORD_CACHE_TTL = 2592000;

    private function entries(string $kind): array
    {
        $entries = app(ElectionArchive::class)->catalogue()[$kind];
        if ($kind === 'ac') {
            $entries = array_map(fn ($entry) => [$entry[0].' Uttar Pradesh', $entry[1]], $entries);
            foreach (app(ElectionArchive::class)->nationalAssemblyEntries() as $entry) {
                $entries[] = [$entry['label'].' '.$entry['state'], $entry['url']];
            }
        }

        return collect($entries)->unique(fn ($entry) => $entry[1])->values()->all();
    }

    public function editions(string $kind = 'pc'): array
    {
        return collect($this->entries($kind))
            ->map(fn (array $entry): array => ['id' => substr(hash('sha256', $entry[1]), 0, 24), 'label' => $entry[0], 'year' => (int) substr($entry[0], 0, 4)])
            ->reject(fn (array $entry): bool => $entry['id'] === ElectionArchive::PC_2019_SUPERSEDED)
            ->filter(fn (array $entry): bool => app(ArchiveFiles::class)->exists('election-archive/'.$entry['id'].'/extraction.json'))
            ->sortByDesc('year')->values()->all();
    }

    public function load(string $archive, ElectionArchive $service, ?int $code = null): array
    {
        $entries = array_merge($this->entries('ac'), $this->entries('pc'));
        $entry = collect($entries)->first(fn (array $row): bool => substr(hash('sha256', $row[1]), 0, 24) === $archive);
        abort_unless($entry, 404);
        $collection = $service->collection($entry[1]);
        $disk = app(ArchiveFiles::class);
        $root = 'election-archive/'.$archive.'/';
        abort_unless($disk->exists($root.'extraction.json'), 404);
        $data = null;
        if ($code !== null) {
            $fingerprint = $disk->verifiedJsonDigest($root.'extraction.json');
            if ($fingerprint !== null) {
                $data = $this->cachedRecordData($archive, $fingerprint, $code);
            }
        }
        if ($data === null) {
            $body = $disk->get($root.'extraction.json');
            $data = $code === null ? json_decode($body, true, 512, JSON_THROW_ON_ERROR)
                : $this->recordData($archive, $body, $code, $entry);
        }
        abort_unless(($data['source_url'] ?? null) === $entry[1], 409);
        abort_unless(($data['year'] ?? null) === (int) substr($entry[0], 0, 4), 409);
        $source = collect($collection['files'])->firstWhere('file', $data['source_file']);
        abort_unless($source && basename($source['file']) === $source['file'], 409);
        abort_unless(hash_equals($source['sha256'], $data['source_sha256']), 409, 'Extraction source identity differs.');
        $unavailableOriginals = 0;
        if ($disk->exists($root.$source['file'])) {
            abort_unless($disk->verify($root.$source['file'], $source['sha256']), 409, 'Extraction source integrity check failed.');
        } else {
            $unavailableOriginals++;
        }

        foreach ($data['additional_sources'] ?? [] as $additional) {
            $official = collect($collection['files'])->firstWhere('file', $additional['file']);
            abort_unless($official && basename($official['file']) === $official['file'], 409);
            abort_unless(hash_equals($official['sha256'], $additional['sha256']), 409, 'Additional source identity differs.');
            if ($disk->exists($root.$official['file'])) {
                abort_unless($disk->verify($root.$official['file'], $official['sha256']), 409, 'Additional source integrity check failed.');
            } else {
                $unavailableOriginals++;
            }
        }

        $data['unavailable_original_count'] = $unavailableOriginals;

        return [$data, $source];
    }

    /**
     * Stable archive/seat keys bound cache growth; the current source-byte fingerprint
     * invalidates derived records immediately. Original integrity checks remain in load().
     */
    private function cachedRecordData(string $archive, string $fingerprint, int $code): ?array
    {
        $prefix = 'historical-seat-record-v2:'.$archive.':';
        $metadata = Cache::get($prefix.'metadata');
        if (is_array($metadata) && ($metadata['fingerprint'] ?? null) === $fingerprint) {
            if (($metadata['complete'] ?? false) && ! isset($metadata['codes'][$code])) {
                return $metadata['data'] + ['records' => []];
            }
            $cached = Cache::get($prefix.$code);
            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
                return $metadata['data'] + ['records' => $cached['records']];
            }
        }

        return null;
    }

    private function recordData(string $archive, string $body, int $code, array $entry): array
    {
        $fingerprint = hash('sha256', $body);
        $prefix = 'historical-seat-record-v2:'.$archive.':';
        $metadata = Cache::get($prefix.'metadata');
        if (is_array($metadata) && ($metadata['fingerprint'] ?? null) === $fingerprint) {
            if (($metadata['complete'] ?? false) && ! isset($metadata['codes'][$code])) {
                return $metadata['data'] + ['records' => []];
            }
            $cached = Cache::get($prefix.$code);
            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
                return $metadata['data'] + ['records' => $cached['records']];
            }
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($data['source_url'] ?? null) === $entry[1], 409);
        abort_unless(($data['year'] ?? null) === (int) substr($entry[0], 0, 4), 409);
        $record = collect($data['records'] ?? [])->firstWhere('code', $code);
        $records = $data['records'] ?? [];
        unset($data['records']);
        $codes = [];
        $complete = is_array($records);
        foreach (is_array($records) ? $records : [] as $row) {
            if (! is_array($row) || ! is_int($row['code'] ?? null)) {
                $complete = false;
                break;
            }
        }
        $selected = $data + ['records' => $record === null ? [] : [$record]];
        $lock = Cache::lock($prefix.'build', 30);
        if (! $lock->get()) {
            return $selected;
        }
        try {
            $current = Cache::get($prefix.'metadata');
            if (is_array($current) && ($current['fingerprint'] ?? null) === $fingerprint && ($current['complete'] ?? false)) {
                $cached = Cache::get($prefix.$code);
                if (! isset($current['codes'][$code]) || (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint)) {
                    return $selected;
                }
            }
            $write = function () use ($complete, $records, $prefix, $fingerprint, $record, $code, $data, $codes): void {
                $batch = [];
                if ($complete) {
                    foreach ($records as $row) {
                        if (isset($codes[$row['code']])) {
                            continue;
                        }
                        $codes[$row['code']] = true;
                        $batch[$prefix.$row['code']] = ['fingerprint' => $fingerprint, 'records' => [$row]];
                        if (count($batch) === 250) {
                            Cache::putMany($batch, self::RECORD_CACHE_TTL);
                            $batch = [];
                        }
                    }
                    if ($batch !== []) {
                        Cache::putMany($batch, self::RECORD_CACHE_TTL);
                    }
                } else {
                    Cache::put($prefix.$code, ['fingerprint' => $fingerprint, 'records' => $record === null ? [] : [$record]], self::RECORD_CACHE_TTL);
                }
                Cache::put($prefix.'metadata', ['fingerprint' => $fingerprint, 'data' => $data, 'codes' => $codes, 'complete' => $complete], self::RECORD_CACHE_TTL);
            };
            $store = Cache::getStore();
            if ($store instanceof DatabaseStore) {
                $store->getConnection()->transaction($write);
            } else {
                $write();
            }
        } finally {
            $lock->release();
        }

        return $data + ['records' => $record === null ? [] : [$record]];
    }
}
