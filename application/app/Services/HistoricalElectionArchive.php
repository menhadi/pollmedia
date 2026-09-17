<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class HistoricalElectionArchive
{
    public function editions(string $kind = 'pc'): array
    {
        return collect(app(ElectionArchive::class)->catalogue()[$kind])
            ->map(fn (array $entry): array => ['id' => substr(hash('sha256', $entry[1]), 0, 24), 'label' => $entry[0], 'year' => (int) substr($entry[0], 0, 4)])
            ->filter(fn (array $entry): bool => Storage::disk('local')->exists('election-archive/'.$entry['id'].'/extraction.json'))
            ->sortByDesc('year')->values()->all();
    }

    public function load(string $archive, ElectionArchive $service): array
    {
        $entries = array_merge($service->catalogue()['ac'], $service->catalogue()['pc']);
        $entry = collect($entries)->first(fn (array $row): bool => substr(hash('sha256', $row[1]), 0, 24) === $archive);
        abort_unless($entry, 404);
        $collection = $service->collection($entry[1]);
        $disk = Storage::disk('local');
        $root = 'election-archive/'.$archive.'/';
        abort_unless($disk->exists($root.'extraction.json'), 404);
        $data = json_decode($disk->get($root.'extraction.json'), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($data['source_url'] ?? null) === $entry[1], 409);
        abort_unless(($data['year'] ?? null) === (int) substr($entry[0], 0, 4), 409);
        $source = collect($collection['files'])->firstWhere('file', $data['source_file']);
        abort_unless($source && basename($source['file']) === $source['file'], 409);
        $path = $disk->path($root.$source['file']);
        abort_unless(is_file($path) && hash_equals($source['sha256'], $data['source_sha256']) && hash_equals($source['sha256'], hash_file('sha256', $path)), 409, 'Extraction source integrity check failed.');

        foreach ($data['additional_sources'] ?? [] as $additional) {
            $official = collect($collection['files'])->firstWhere('file', $additional['file']);
            abort_unless($official && basename($official['file']) === $official['file'], 409);
            $extraPath = $disk->path($root.$official['file']);
            abort_unless(is_file($extraPath) && hash_equals($official['sha256'], $additional['sha256']) && hash_equals($official['sha256'], hash_file('sha256', $extraPath)), 409, 'Additional source integrity check failed.');
        }

        return [$data, $source];
    }
}
