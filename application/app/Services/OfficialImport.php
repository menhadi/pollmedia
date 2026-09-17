<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class OfficialImport
{
    public function run(int $connectorId, ?string $upload = null): int
    {
        $lock = Cache::lock('official-import:'.$connectorId, 450);
        if (! $lock->get()) {
            throw new RuntimeException('This source is already being imported.');
        }
        try {
            $connector = DB::table('import_connectors')->find($connectorId);
            if (! $connector) {
                throw new RuntimeException('Import source not found.');
            }
            $values = ['import_connector_id' => $connectorId, 'origin' => $upload ? 'upload' : 'url', 'source_url' => $connector->url,
                'base_run_id' => $connector->accepted_run_id, 'created_at' => now(), 'status' => 'failed'];
            try {
                $body = $upload ? file_get_contents($upload) : app(OfficialDownload::class)->get($connector->url);
                if ($body === false || strlen($body) > config('imports.max_bytes')) {
                    throw new RuntimeException('Input is unavailable or exceeds the 20 MB limit.');
                }
                $hash = hash('sha256', $body);
                $options = json_decode($connector->options, true, 512, JSON_THROW_ON_ERROR);
                $optionsHash = hash('sha256', json_encode($options, JSON_THROW_ON_ERROR));
                $previous = DB::table('import_runs')->where('import_connector_id', $connectorId)->whereNotNull('extracted')->orderByDesc('id')->first();
                if ($previous && $previous->sha256 === $hash
                    && (json_decode($previous->summary ?? '{}', true)['extraction_options_sha256'] ?? null) === $optionsHash
                    && ($previous->base_run_id === $connector->accepted_run_id || $previous->id === $connector->accepted_run_id)) {
                    $values += ['sha256' => $hash, 'summary' => json_encode(['same_as_run' => $previous->id])];
                    $values['status'] = 'unchanged';
                } else {
                    $rawPath = 'official-imports/'.Str::ulid().'.'.$connector->format;
                    if (! Storage::disk('local')->put($rawPath, $body)) {
                        throw new RuntimeException('Could not archive the official file.');
                    }
                    $values += ['sha256' => $hash, 'raw_path' => $rawPath];
                    $extracted = $this->extract(Storage::disk('local')->path($rawPath), $connector->format, $options);
                    $base = $connector->accepted_run_id ? DB::table('import_runs')->find($connector->accepted_run_id) : null;
                    $summary = $this->compare($extracted, $base ? json_decode($base->extracted, true) : null, $connector->record_key);
                    $summary['extraction_options_sha256'] = $optionsHash;
                    if (! empty($options['identifier'])) {
                        [$namespace, $version] = explode('|', $options['identifier'], 2);
                        $identifiers = DB::table('place_identifiers')->where('namespace', $namespace)->where('version', $version)->pluck('place_id', 'code');
                        $summary['matched_places'] = collect($extracted['rows'])->filter(fn ($row) => $identifiers->has($row[$connector->record_key] ?? ''))->count();
                        $summary['identifier_scope'] = $options['identifier'];
                    }
                    $values['status'] = 'needs_review';
                    $values['extracted'] = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $values['summary'] = json_encode($summary, JSON_THROW_ON_ERROR);
                }
            } catch (Throwable $error) {
                $values['error'] = $error instanceof RuntimeException ? $error->getMessage() : 'Download or extraction failed. Check the source URL, format and extraction settings.';
            }
            $id = DB::table('import_runs')->insertGetId($values);
            DB::table('import_connectors')->where('id', $connectorId)->update(['next_check_at' => now()->timezone('Asia/Kolkata')->addDay()->startOfDay()->setTime(6, 0)->utc(), 'updated_at' => now()]);

            return $id;
        } finally {
            $lock->release();
        }
    }

    public function extract(string $path, string $format, array $options): array
    {
        $output = tempnam(storage_path('app/private'), 'extract-');
        if ($output === false) {
            throw new RuntimeException('Could not allocate extraction output.');
        }
        $process = new Process([config('imports.python'), config('imports.extractor'), $path, $format, $output]);
        $process->setInput(json_encode($options, JSON_THROW_ON_ERROR));
        $process->setTimeout(! empty($options['filter_column']) ? 300 : 75);
        try {
            $process->run();
            $data = json_decode($process->isSuccessful() ? file_get_contents($output) : $process->getOutput(), true);
        } finally {
            unlink($output);
        }
        if (! $process->isSuccessful() || ! is_array($data) || isset($data['error'])) {
            throw new RuntimeException($data['error'] ?? 'Extraction failed or exceeded its time limit. Check Python extraction dependencies.');
        }
        if (! isset($data['headers'], $data['rows'], $data['scope']) || count($data['rows']) > config('imports.max_rows')) {
            throw new RuntimeException('Extraction returned an invalid or oversized table.');
        }

        return $data;
    }

    public function compare(array $data, ?array $base, string $key): array
    {
        $missing = 0;
        $duplicates = 0;
        $indexed = [];
        foreach ($data['rows'] as $row) {
            $code = (string) ($row[$key] ?? '');
            if ($code === '') {
                $missing++;
            } elseif (isset($indexed[$code])) {
                $duplicates++;
            }
            $indexed[$code] = $row;
        }
        $old = collect($base['rows'] ?? [])->keyBy($key)->all();
        $added = array_keys(array_diff_key($indexed, $old));
        $removed = array_keys(array_diff_key($old, $indexed));
        $changed = [];
        foreach (array_intersect_key($indexed, $old) as $code => $row) {
            if ($row != $old[$code]) {
                $changed[] = (string) $code;
            }
        }

        return ['rows' => count($data['rows']), 'missing_keys' => $missing, 'duplicate_keys' => $duplicates,
            'key_column_present' => in_array($key, $data['headers'], true), 'schema_changed' => $base && $data['headers'] !== $base['headers'],
            'added' => count($added), 'changed' => count($changed), 'removed' => count($removed),
            'added_codes' => array_slice($added, 0, 100), 'changed_codes' => array_slice($changed, 0, 100), 'removed_codes' => array_slice($removed, 0, 100)];
    }
}
