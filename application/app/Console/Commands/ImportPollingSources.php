<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportPollingSources extends Command
{
    protected $signature = 'polling:import {--root= : Preserved polling-source directory} {--state= : One state or territory}
        {--limit= : Maximum source documents} {--receipts= : JSONL verified R2 receipts} {--profile= : Tested R2 profile id}';

    protected $description = 'Import verified polling source metadata, raw tables, rows and OCR into the database';

    public function handle(): int
    {
        $root = $this->option('root') ?: storage_path('app/private/polling-station-sources');
        $index = $this->readJson($root.'/index.json', null, 64000000);
        $state = $this->option('state');
        $limit = $this->option('limit') !== null ? filter_var($this->option('limit'), FILTER_VALIDATE_INT) : null;
        if ($limit !== null && $limit < 1) {
            $this->error('--limit must be positive.');

            return self::FAILURE;
        }
        $receiptsPath = $this->option('receipts');
        $profileId = $this->option('profile') !== null ? filter_var($this->option('profile'), FILTER_VALIDATE_INT) : null;
        if (($receiptsPath === null) !== ($profileId === null)) {
            $this->error('Use --receipts and --profile together.');

            return self::FAILURE;
        }
        $profile = null;
        if ($profileId !== null) {
            $profile = DB::table('pdf_storage_profiles')->where('id', $profileId)->whereNotNull('tested_at')->first();
            if (! $profile || $profile->provider !== 'r2') {
                $this->error('Choose a tested R2 storage profile.');

                return self::FAILURE;
            }
        }
        $receipts = $this->readReceipts($receiptsPath);
        foreach ($index['states'] as $entry) {
            if ($state && $entry['state'] !== $state) {
                continue;
            }
            DB::table('polling_source_states')->upsert([[
                'name' => $entry['state'], 'metadata' => $this->encode($entry), 'created_at' => now(), 'updated_at' => now(),
            ]], ['name'], ['metadata', 'updated_at']);
        }
        $documents = 0;
        $pages = 0;
        $rows = 0;
        foreach ($index['sources'] as $source) {
            if ($state && $source['state'] !== $state) {
                continue;
            }
            if (! preg_match('/^[a-f0-9]{24}$/', $source['id']) || ! preg_match('/^[a-f0-9]{24}$/', $source['folder'])
                || ! preg_match('/^[a-f0-9]{64}$/', $source['sha256'])) {
                throw new RuntimeException('Unexpected polling source identifier.');
            }
            $folder = $root.'/'.$source['folder'];
            $pageEntries = $source['pages'] ?? [];
            if (isset($source['page_manifest'])) {
                $manifest = $source['page_manifest'];
                $this->safeFile($manifest['file']);
                $pageEntries = $this->readJson($folder.'/'.$manifest['file'], $manifest['sha256'], 64000000)['pages'];
            }
            $ocrEntries = [];
            $ocrIndex = $folder.'/'.$source['sha256'].'-ocr/index.json';
            if (is_file($ocrIndex)) {
                foreach ($this->readJson($ocrIndex, null, 64000000)['pages'] as $entry) {
                    $ocrEntries[$entry['page']] = $entry;
                }
            }
            DB::transaction(function () use ($source, $folder, $pageEntries, $ocrEntries, $receipts, $profileId, $profile): void {
                $metadata = $source;
                unset($metadata['pages']);
                DB::table('polling_source_documents')->upsert([[
                    'id' => $source['id'], 'state' => $source['state'], 'sha256' => $source['sha256'],
                    'metadata' => $this->encode($metadata), 'created_at' => now(), 'updated_at' => now(),
                ]], ['id'], ['state', 'sha256', 'metadata', 'updated_at']);
                if ($profileId !== null && str_ends_with($source['file'], '.pdf')) {
                    $receipt = $receipts[$source['id']] ?? null;
                    $expectedKey = $profile->prefix.'/polling-station-sources/'.$source['folder'].'/'.$source['file'];
                    if (! $receipt || $source['file'] !== $source['sha256'].'.pdf'
                        || $receipt['sha256'] !== $source['sha256'] || $receipt['bucket'] !== $profile->bucket
                        || ($receipt['endpoint'] ?? null) !== $profile->endpoint || $receipt['object_key'] !== $expectedKey
                        || ! is_int($receipt['bytes']) || $receipt['bytes'] < 5) {
                        throw new RuntimeException('Verified R2 receipt missing or inconsistent for '.$source['id']);
                    }
                    $path = 'polling-station-sources/'.$source['folder'].'/'.$source['file'];
                    $existing = DB::table('pdf_storage_files')->where('path_hash', hash('sha256', $path))->first();
                    if ($existing && ($existing->sha256 !== $receipt['sha256'] || ($existing->profile_id !== null
                        && ((int) $existing->profile_id !== $profileId || $existing->object_key !== $receipt['object_key'])))) {
                        throw new RuntimeException('PDF inventory conflicts with receipt for '.$source['id']);
                    }
                    DB::table('pdf_storage_files')->upsert([[
                        'path_hash' => hash('sha256', $path), 'path' => $path, 'sha256' => $receipt['sha256'],
                        'bytes' => $receipt['bytes'], 'profile_id' => $profileId, 'object_key' => $receipt['object_key'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]], ['path_hash'], ['path', 'sha256', 'bytes', 'profile_id', 'object_key', 'updated_at']);
                }
                $existingPages = DB::table('polling_source_pages')->where('source_id', $source['id'])
                    ->get(['page', 'sha256', 'ocr_sha256'])->keyBy('page');
                $batch = [];
                foreach ($pageEntries as $entry) {
                    $this->safeFile($entry['file']);
                    $ocrEntry = $ocrEntries[$entry['page']] ?? ($entry['ocr'] ?? null);
                    if ($ocrEntry) {
                        $this->safeFile($ocrEntry['file']);
                    }
                    $previous = $existingPages->get($entry['page']);
                    if ($previous && $previous->sha256 === $entry['sha256'] && $previous->ocr_sha256 === ($ocrEntry['sha256'] ?? null)) {
                        continue;
                    }
                    $payload = $this->readJson($folder.'/'.$source['sha256'].'-tables/'.$entry['file'], $entry['sha256']);
                    $ocr = $ocrEntry ? $this->readJson($folder.'/'.$source['sha256'].'-ocr/'.$ocrEntry['file'], $ocrEntry['sha256']) : null;
                    $batch[] = ['source_id' => $source['id'], 'page' => $entry['page'], 'sha256' => $entry['sha256'],
                        'metadata' => $this->encode($entry), 'payload' => $this->encode($payload),
                        'ocr_sha256' => $ocrEntry['sha256'] ?? null, 'ocr_payload' => $ocr === null ? null : $this->encode($ocr),
                        'created_at' => now(), 'updated_at' => now()];
                    if (count($batch) === 50) {
                        $this->storePages($batch);
                        $batch = [];
                    }
                }
                $this->storePages($batch);
            });
            $documents++;
            $pages += count($pageEntries);
            $rows += (int) ($source['polling_rows'] ?? 0);
            if ($documents % 100 === 0) {
                $this->line("Imported {$documents} documents, {$pages} pages, {$rows} indexed polling rows.");
            }
            if ($limit !== null && $documents >= $limit) {
                break;
            }
        }
        $this->info("Imported {$documents} documents, {$pages} pages, {$rows} indexed polling rows into the database.");

        return self::SUCCESS;
    }

    private function storePages(array $batch): void
    {
        if ($batch) {
            DB::table('polling_source_pages')->upsert($batch, ['source_id', 'page'],
                ['sha256', 'metadata', 'payload', 'ocr_sha256', 'ocr_payload', 'updated_at']);
        }
    }

    private function readJson(string $path, ?string $expected = null, int $maximum = 16000000): array
    {
        if (! is_file($path) || filesize($path) > $maximum) {
            throw new RuntimeException('Source JSON missing or too large: '.$path);
        }
        if ($expected !== null && ! hash_equals($expected, hash_file('sha256', $path))) {
            throw new RuntimeException('Source JSON checksum differs: '.$path);
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function safeFile(string $name): void
    {
        if ($name !== basename($name) || ! preg_match('/^[a-zA-Z0-9_.-]+$/', $name)) {
            throw new RuntimeException('Unsafe source filename.');
        }
    }

    private function readReceipts(?string $path): array
    {
        if ($path === null) {
            return [];
        }
        if (! is_file($path)) {
            throw new RuntimeException('Verified R2 receipts are missing.');
        }
        $receipts = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $receipts[$entry['source_id']] = $entry;
        }

        return $receipts;
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
