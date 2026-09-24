<?php

namespace App\Services;

class HistoricalElectionArchive
{
    private function entries(string $kind): array
    {
        $entries = app(ElectionArchive::class)->catalogue()[$kind];
        if ($kind === 'ac') {
            $entries = array_map(fn ($entry) => [$entry[0].' Uttar Pradesh', $entry[1]], $entries);
            $national = json_decode(file_get_contents(database_path('fixtures/eci-assembly-national.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach ($national['entries'] as $entry) {
                $entries[] = [$entry['label'].' '.$entry['state'], $entry['url']];
            }
        }

        return collect($entries)->unique(fn ($entry) => $entry[1])->values()->all();
    }

    public function editions(string $kind = 'pc'): array
    {
        return collect($this->entries($kind))
            ->map(fn (array $entry): array => ['id' => substr(hash('sha256', $entry[1]), 0, 24), 'label' => $entry[0], 'year' => (int) substr($entry[0], 0, 4)])
            ->filter(fn (array $entry): bool => app(ArchiveFiles::class)->exists('election-archive/'.$entry['id'].'/extraction.json'))
            ->sortByDesc('year')->values()->all();
    }

    public function load(string $archive, ElectionArchive $service): array
    {
        $entries = array_merge($this->entries('ac'), $this->entries('pc'));
        $entry = collect($entries)->first(fn (array $row): bool => substr(hash('sha256', $row[1]), 0, 24) === $archive);
        abort_unless($entry, 404);
        $collection = $service->collection($entry[1]);
        $disk = app(ArchiveFiles::class);
        $root = 'election-archive/'.$archive.'/';
        abort_unless($disk->exists($root.'extraction.json'), 404);
        $data = json_decode($disk->get($root.'extraction.json'), true, 512, JSON_THROW_ON_ERROR);
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
}
