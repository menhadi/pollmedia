<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

class ImportArchiveJsonPackage extends Command
{
    protected $signature = 'archive:import-json {package : Verified data-only ZIP}
        {--sha256= : Expected package SHA-256} {--check : Validate without database writes}';

    protected $description = 'Import preserved historical election JSON and raw tables into PostgreSQL';

    public function handle(): int
    {
        $path = $this->argument('package');
        $expected = $this->option('sha256');
        if (! is_file($path) || ! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/', $expected)
            || ! hash_equals($expected, hash_file('sha256', $path))) {
            $this->error('Package is missing or SHA-256 differs.');

            return self::FAILURE;
        }
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            $this->error('Package ZIP cannot be opened.');

            return self::FAILURE;
        }
        try {
            $manifestBody = $archive->getFromName('manifest.json');
            if ($manifestBody === false || strlen($manifestBody) > 4000000) {
                throw new RuntimeException('Package manifest is missing or too large.');
            }
            $manifest = json_decode($manifestBody, true, 512, JSON_THROW_ON_ERROR);
            $category = $manifest['category'] ?? null;
            $bucket = $manifest['bucket'] ?? null;
            $buckets = $manifest['buckets'] ?? null;
            $files = $manifest['files'] ?? null;
            if (($manifest['version'] ?? null) !== 1
                || ! in_array($category, ['election-archive', 'election-by-elections'], true)
                || ! is_int($bucket) || ! is_int($buckets) || $buckets < 1 || $buckets > 64
                || $bucket < 0 || $bucket >= $buckets || ! is_array($files)
                || count($files) < 1 || count($files) > 10000 || $archive->numFiles !== count($files) + 1) {
                throw new RuntimeException('Unexpected archive package manifest.');
            }
            $names = ['manifest.json' => true];
            foreach ($files as $entry) {
                $name = $entry['path'] ?? null;
                if (! is_string($name) || ! preg_match('~^(election-archive|election-by-elections)/[A-Za-z0-9_./-]+\.json$~', $name)
                    || str_contains($name, '..') || ! str_starts_with($name, $category.'/') || isset($names[$name])
                    || ord(hash('sha256', $name, true)[0]) % $buckets !== $bucket
                    || ! preg_match('/^[a-f0-9]{64}$/', $entry['sha256'] ?? '')
                    || ! is_int($entry['bytes'] ?? null) || $entry['bytes'] < 2 || $entry['bytes'] > 32000000) {
                    throw new RuntimeException('Unsafe or inconsistent archive entry.');
                }
                $names[$name] = true;
                $stat = $archive->statName($name);
                if ($stat === false || $stat['size'] !== $entry['bytes']) {
                    throw new RuntimeException('Archived entry size differs: '.$name);
                }
            }
            for ($index = 0; $index < $archive->numFiles; $index++) {
                if (! isset($names[$archive->getNameIndex($index)])) {
                    throw new RuntimeException('Unexpected file in archive package.');
                }
            }
            $imported = $existing = 0;
            DB::transaction(function () use ($archive, $files, $category, &$imported, &$existing): void {
                foreach ($files as $entry) {
                    $name = $entry['path'];
                    $body = $archive->getFromName($name);
                    if ($body === false || strlen($body) !== $entry['bytes']
                        || ! hash_equals($entry['sha256'], hash('sha256', $body))) {
                        throw new RuntimeException('Archived entry checksum differs: '.$name);
                    }
                    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    if ($this->option('check')) {
                        $existing++;

                        continue;
                    }
                    $pathHash = hash('sha256', $name);
                    $prior = DB::table('archive_json_files')->where('path_hash', $pathHash)->first();
                    if ($prior) {
                        if ($prior->path !== $name || $prior->sha256 !== $entry['sha256']
                            || (int) $prior->bytes !== $entry['bytes']) {
                            throw new RuntimeException('Existing archived JSON conflicts: '.$name);
                        }
                        $existing++;

                        continue;
                    }
                    $sourceUrl = is_array($data) ? ($data['source_url'] ?? $data['url'] ?? null) : null;
                    DB::table('archive_json_files')->insert([
                        'path_hash' => $pathHash, 'path' => $name, 'category' => $category,
                        'sha256' => $entry['sha256'], 'bytes' => $entry['bytes'],
                        'source_url' => is_string($sourceUrl) && preg_match('~^https?://~', $sourceUrl) ? $sourceUrl : null,
                        'body' => $body, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $imported++;
                }
            });
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $archive->close();
        }

        $this->info($this->option('check') ? "Verified {$existing} archived JSON files."
            : "Imported {$imported} archived JSON files; {$existing} already present.");

        return self::SUCCESS;
    }
}
