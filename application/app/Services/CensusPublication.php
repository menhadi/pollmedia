<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CensusPublication
{
    public const KEY = 'census-pilibhit-villages-2011';

    public const FIELDS = ['population' => 'TOT_P', 'households' => 'No_HH', 'literate' => 'P_LIT', 'population_0_6' => 'P_06'];

    public function current(): object
    {
        $source = DB::table('data_sources')->where('key', self::KEY)->first();
        abort_unless($source, 404, 'Census dataset unavailable.');
        $release = DB::table('source_releases')->where('data_source_id', $source->id)->where('status', 'accepted')->orderByDesc('id')->first();
        abort_unless($release, 404, 'Census dataset unavailable.');

        return $release;
    }

    public function preview(int $run): array
    {
        $record = DB::table('import_runs')->find($run);
        abort_unless($record, 404);
        $release = $this->current();
        $payload = json_decode($release->payload, true, 512, JSON_THROW_ON_ERROR);
        $connector = DB::table('import_connectors')->find($record->import_connector_id);
        $options = json_decode($connector->options, true);
        $errors = [];
        if ($record->source_url !== $payload['source_url'] || $connector->format !== 'xlsx' || ($options['sheet'] ?? '') !== $payload['sheet'] || $connector->record_key !== 'Town/Village') {
            $errors[] = 'This mapping requires the configured Pilibhit Census 2011 workbook URL, sheet and village-code column.';
        }
        if (! in_array($record->status, ['needs_review', 'accepted'], true)) {
            $errors[] = 'Only a successfully extracted, non-rejected snapshot can be mapped.';
        }
        if (! $record->raw_path || ! Storage::disk('local')->exists($record->raw_path) || ! hash_equals($record->sha256 ?? '', hash_file('sha256', Storage::disk('local')->path($record->raw_path)))) {
            $errors[] = 'The archived workbook is missing or its integrity check failed.';
        }
        $data = json_decode($record->extracted ?? '{}', true);
        $indexed = [];
        foreach ($data['rows'] ?? [] as $row) {
            $code = (string) ($row['Town/Village'] ?? '');
            if (! preg_match('/^[0-9]{6}$/', $code) || isset($indexed[$code])) {
                $errors[] = 'Missing, invalid or duplicate village codes.';
            }
            $indexed[$code] = $row;
        }
        $old = collect($payload['villages'])->keyBy('code')->all();
        $added = array_keys(array_diff_key($indexed, $old));
        $removed = array_keys(array_diff_key($old, $indexed));
        if ($added || $removed) {
            $errors[] = 'Village coverage changed. Added or missing codes require a separate geographic review.';
        }
        $changes = [];
        foreach ($payload['villages'] as &$village) {
            $code = $village['code'];
            $row = $indexed[$code] ?? null;
            if (! $row) {
                continue;
            }
            if (($row['State'] ?? '') !== '09' || ($row['District'] ?? '') !== '151' || ($row['Level'] ?? '') !== 'VILLAGE' || ($row['TRU'] ?? '') !== 'Rural' || ($row['Subdistt'] ?? '') !== $village['subdistrict_code'] || ($row['Name'] ?? '') !== $village['name']) {
                $errors[] = 'Identity or geographic scope differs for village '.$code.'.';

                continue;
            }
            $numbers = [];
            foreach (array_merge(array_values(self::FIELDS), ['TOT_M', 'TOT_F']) as $column) {
                $value = (string) ($row[$column] ?? '');
                if (! preg_match('/^[0-9]{1,9}$/', $value)) {
                    $errors[] = 'Missing or invalid '.$column.' for village '.$code.'.';
                } else {
                    $numbers[$column] = (int) $value;
                }
            }
            if (count($numbers) !== 6) {
                continue;
            }
            if ($numbers['TOT_P'] !== $numbers['TOT_M'] + $numbers['TOT_F'] || $numbers['P_06'] > $numbers['TOT_P'] || $numbers['P_LIT'] > $numbers['TOT_P'] - $numbers['P_06'] || $numbers['No_HH'] > $numbers['TOT_P'] || ($numbers['TOT_P'] > 0 && $numbers['No_HH'] === 0)) {
                $errors[] = 'Population, household or literacy totals do not reconcile for village '.$code.'.';

                continue;
            }
            foreach (self::FIELDS as $field => $column) {
                if ($village[$field] !== $numbers[$column]) {
                    $changes[] = ['code' => $code, 'name' => $village['name'], 'field' => $field, 'before' => $village[$field], 'after' => $numbers[$column]];
                    $village[$field] = $numbers[$column];
                }
            }
            unset($village['source_row']);
        }
        unset($village);
        $payload['sha256'] = $record->sha256;
        $payload['import_run_id'] = $run;
        $payload['publication_mapping'] = 'pilibhit-census-2011-v1';

        return compact('record', 'release', 'payload', 'errors', 'changes', 'added', 'removed');
    }

    public function publish(int $run, int $base, int $user): void
    {
        DB::transaction(function () use ($run, $base, $user): void {
            DB::table('data_sources')->where('key', self::KEY)->lockForUpdate()->first();
            $preview = $this->preview($run);
            abort_unless($preview['release']->id === $base, 409, 'Published data changed. Reload the preview.');
            abort_if($preview['errors'], 422, implode(' ', array_slice($preview['errors'], 0, 5)));
            abort_unless($preview['record']->status === 'accepted', 422, 'Accept the reviewed import baseline before publishing.');
            abort_unless($preview['changes'], 422, 'No mapped values changed; publication is unnecessary.');
            $release = $this->append($preview['release'], $preview['payload'], $preview['record']->created_at);
            DB::table('import_publications')->insert(['import_run_id' => $run, 'before_release_id' => $base, 'after_release_id' => $release, 'action' => 'publish', 'user_id' => $user, 'created_at' => now()]);
        });
    }

    public function restore(int $publication, int $base, int $user): void
    {
        DB::transaction(function () use ($publication, $base, $user): void {
            DB::table('data_sources')->where('key', self::KEY)->lockForUpdate()->first();
            $entry = DB::table('import_publications')->find($publication);
            abort_unless($entry && $entry->action === 'publish', 404);
            $current = $this->current();
            abort_unless($current->id === $base && $current->id === $entry->after_release_id, 409, 'Only the current publication can be rolled back. Reload the preview.');
            $previous = DB::table('source_releases')->find($entry->before_release_id);
            $release = $this->append($previous, json_decode($previous->payload, true), $previous->retrieved_at);
            DB::table('import_publications')->insert(['import_run_id' => $entry->import_run_id, 'before_release_id' => $current->id, 'after_release_id' => $release, 'action' => 'rollback', 'user_id' => $user, 'created_at' => now()]);
        });
    }

    private function append(object $source, array $payload, string $retrieved): int
    {
        return DB::table('source_releases')->insertGetId(['data_source_id' => $source->data_source_id, 'version_key' => 'publication-'.Str::ulid(), 'sha256' => $payload['sha256'], 'url' => $source->url, 'published_on' => $source->published_on, 'retrieved_at' => $retrieved, 'status' => 'accepted', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }
}
