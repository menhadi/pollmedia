<?php

namespace App\Console\Commands;

use App\Services\OfficialDownload;
use App\Services\OfficialImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CollectCensusSources extends Command
{
    protected $signature = 'imports:collect-census {--saved : Reuse the last checksummed local source file} {--source=* : Collect only these configured source keys}';

    protected $description = 'Archive configured national Census sources and stage supported workbooks for review';

    public function handle(OfficialImport $importer, OfficialDownload $download): int
    {
        if (array_diff($this->option('source'), array_keys(config('census-sources'))) !== []) {
            $this->error('Unknown Census source key.');

            return self::FAILURE;
        }
        $lock = Cache::lock('census-national-collection', 3600);
        if (! $lock->get()) {
            $this->error('Census collection is already running.');

            return self::FAILURE;
        }
        $failed = false;
        try {
            foreach (config('census-sources') as $key => $source) {
                if ($this->option('source') && ! in_array($key, $this->option('source'), true)) {
                    continue;
                }
                try {
                    if ($source['archive_only'] ?? false) {
                        $manifestPath = 'census-archive/'.$key.'/manifest.json';
                        if ($this->option('saved')) {
                            $manifest = json_decode(Storage::disk('local')->get($manifestPath) ?? '{}', true);
                            $path = $manifest['path'] ?? '';
                            if (! $path || ! Storage::disk('local')->exists($path) || hash('sha256', Storage::disk('local')->get($path)) !== $manifest['sha256']) {
                                throw new \RuntimeException('No intact archived file available. Collect the source first.');
                            }
                        } else {
                            $body = $download->get($source['url']);
                            $signature = $source['format'] === 'pdf' ? '%PDF-' : hex2bin('d0cf11e0a1b11ae1');
                            if (! str_starts_with($body, $signature)) {
                                throw new \RuntimeException('Archived source format signature does not match.');
                            }
                            $hash = hash('sha256', $body);
                            $path = 'census-archive/'.$key.'/'.$hash.'.'.$source['format'];
                            if (! Storage::disk('local')->put($path, $body) || ! Storage::disk('local')->put($manifestPath, json_encode($source + ['path' => $path, 'sha256' => $hash, 'bytes' => strlen($body), 'retrieved_at' => now()->toIso8601String(), 'status' => 'archived_requires_mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
                                throw new \RuntimeException('Could not save archived source.');
                            }
                        }
                        $this->info($key.': archived; source-specific historical mapping required');

                        continue;
                    }
                    $options = json_encode([
                        'header_row' => 1, 'sheet' => $source['sheet'] ?? 'Sheet1', 'max_rows' => $source['max_rows'] ?? 20000,
                        'filter_column' => $source['filter_column'] ?? null, 'filter_value' => $source['filter_value'] ?? null,
                        'scope_note' => $source['scope_note'] ?? null,
                        'key_columns' => $source['key_columns'] ?? ['State', 'District', 'Subdistt', 'Town/Village', 'Ward', 'EB', 'Level', 'TRU'],
                    ]);
                    $id = DB::table('import_connectors')->where('url', $source['url'])->where('name', $source['name'])->value('id');
                    if (! $id) {
                        $id = DB::table('import_connectors')->insertGetId([
                            'name' => $source['name'], 'url' => $source['url'], 'format' => $source['format'],
                            'record_key' => 'source_record_key', 'options' => $options, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    DB::table('import_connectors')->where('id', $id)->whereNull('created_by')->whereNull('accepted_run_id')
                        ->where('record_key', 'source_record_key')->update(['options' => $options]);
                    $saved = null;
                    if ($this->option('saved')) {
                        $previous = DB::table('import_runs')->where('source_url', $source['url'])->whereNotNull('raw_path')->orderByDesc('id')->first();
                        $saved = $previous ? Storage::disk('local')->path($previous->raw_path) : null;
                        if (! $saved || ! is_file($saved) || ! hash_equals($previous->sha256, hash_file('sha256', $saved))) {
                            throw new \RuntimeException('No intact archived file available. Collect the source first.');
                        }
                    }
                    $runId = $importer->run($id, $saved);
                    $run = DB::table('import_runs')->find($runId);
                    $failed = $failed || $run->status === 'failed';
                    $this->line($key.': '.$run->status.' (run '.$runId.')'.($run->error ? ' — '.$run->error : ''));
                } catch (Throwable $error) {
                    $failed = true;
                    $this->error($key.': '.$error->getMessage());
                }
            }
        } finally {
            $lock->release();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
