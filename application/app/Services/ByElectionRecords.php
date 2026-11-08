<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ByElectionRecords
{
    private const ROOT = 'election-by-elections/structured/';

    public function __construct(private ArchiveFiles $files) {}

    public function index(): Collection
    {
        $path = self::ROOT.'index.json';
        if (! $this->files->exists($path)) {
            return collect();
        }
        $index = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);

        return collect($index['records']);
    }

    public function record(array $entry): array
    {
        abort_unless(preg_match('/^[a-f0-9]{24}-[a-f0-9]{16}\.json$/', $entry['file']), 503);
        $path = $this->files->path(self::ROOT.$entry['file']);
        abort_unless(is_file($path) && filesize($path) <= 4000000 && hash_equals($entry['sha256'], hash_file('sha256', $path)), 503, 'Result source integrity check failed.');
        $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        abort_unless($record['id'] === $entry['id'], 503, 'Result identifier differs.');

        return $record;
    }
}
