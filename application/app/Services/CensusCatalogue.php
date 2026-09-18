<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CensusCatalogue
{
    public function prepare(int $runId): int
    {
        return DB::transaction(function () use ($runId): int {
            $run = DB::table('import_runs')->where('id', $runId)->lockForUpdate()->first();
            abort_unless($run && in_array($run->status, ['needs_review', 'accepted'], true), 422, 'Use a successfully extracted, non-rejected import.');
            $existing = DB::table('census_editions')->where('import_run_id', $runId)->value('id');
            if ($existing) {
                return $existing;
            }
            $connector = DB::table('import_connectors')->find($run->import_connector_id);
            $options = json_decode($connector->options, true);
            $key = collect(config('census-sources'))->keys()->first(function ($key) use ($run, $options): bool {
                $source = config('census-sources.'.$key);

                return ! ($source['archive_only'] ?? false) && $source['url'] === $run->source_url
                    && ($source['filter_value'] ?? null) === ($options['filter_value'] ?? null);
            });
            abort_unless($key, 422, 'No verified national Census mapping matches this source and scope.');
            $source = config('census-sources.'.$key);
            DB::table('census_publications')->insertOrIgnore(['source_key' => $key]);
            $year = $source['year'] ?? null;
            abort_unless(in_array($year, [2001, 2011], true), 422, 'This Census year needs a verified column mapping.');
            $this->verifyArchive($run);
            $data = json_decode($run->extracted, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(! empty($data['rows']) && count($data['rows']) <= 20000, 422, 'Census table is empty or exceeds the partition limit.');
            $columns = $year === 2001 ? ['STATE', 'DISTRICT', 'TAHSIL', 'TOWN_VILL', 'WARD', 'EB', 'LEVEL', 'NAME', 'TRU'] : ['State', 'District', 'Subdistt', 'Town/Village', 'Ward', 'EB', 'Level', 'Name', 'TRU'];
            abort_unless(array_slice($data['headers'], 0, 9) === $columns, 422, 'Census geography headers changed.');
            $fields = array_values(array_diff($data['headers'], [...$columns, 'source_record_key']));
            abort_unless(in_array('TOT_P', $fields, true) && in_array('TOT_M', $fields, true) && in_array('TOT_F', $fields, true), 422, 'Population columns are missing.');
            $edition = DB::table('census_editions')->insertGetId([
                'import_run_id' => $runId, 'source_key' => $key, 'name' => $source['name'], 'year' => $year,
                'sha256' => $run->sha256, 'source_url' => $run->source_url, 'landing_url' => $source['landing'],
                'scope' => $data['scope'].'; Census '.$year.' geography. Historical names and codes are not current administrative boundaries.',
                'fields' => json_encode($fields), 'row_count' => count($data['rows']), 'retrieved_at' => $run->created_at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $batch = [];
            $flagCount = 0;
            $seen = [];
            foreach ($data['rows'] as $index => $row) {
                $geo = array_intersect_key($row, array_flip($columns));
                $recordKey = hash('sha256', json_encode($geo));
                abort_if(isset($seen[$recordKey]), 422, 'Duplicate complete geographic identity at extracted row '.($index + 1));
                $seen[$recordKey] = true;
                $state = (string) $row[$columns[0]];
                $district = (string) $row[$columns[1]];
                $level = strtoupper($row[$columns[6]]);
                $residence = $row['TRU'];
                abort_unless(preg_match('/^\d{2}$/', $state) && preg_match('/^\d{2,3}$/', $district)
                    && in_array($level, ['INDIA', 'STATE', 'DISTRICT', 'SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL', 'TOWN', 'VILLAGE', 'WARD'], true)
                    && in_array($residence, ['Total', 'Rural', 'Urban'], true) && trim($row[$columns[7]]) !== '', 422, 'Invalid Census geography at extracted row '.($index + 1));
                abort_unless(! isset($source['filter_value']) || $residence === $source['filter_value'], 422, 'Residence differs from the configured source scope.');
                $values = [];
                foreach ($fields as $field) {
                    $value = trim((string) ($row[$field] ?? ''));
                    abort_unless($value === '' || preg_match('/^\d{1,13}(?:\.0+)?$/', $value), 422, 'Unrecognised numeric value in '.$field.' at extracted row '.($index + 1));
                    $values[$field] = $value === '' ? null : (int) $value;
                }
                $flags = [];
                if ($values['TOT_P'] === null || $values['TOT_M'] === null || $values['TOT_F'] === null) {
                    $flags[] = 'Population components are missing in the source.';
                } elseif ($values['TOT_P'] !== $values['TOT_M'] + $values['TOT_F']) {
                    $flags[] = 'Population does not equal the reported male and female components.';
                }
                $flagCount += count($flags) > 0 ? 1 : 0;
                $batch[] = ['edition_id' => $edition, 'record_key' => $recordKey,
                    'state_code' => $state, 'district_code' => $district, 'level' => $level, 'residence' => $residence,
                    'name' => $row[$columns[7]], 'geography' => json_encode($geo), 'values' => json_encode($values),
                    'flags' => json_encode($flags), 'source_row' => $index + 1];
                if (count($batch) === 100) {
                    DB::table('census_catalogue_rows')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                DB::table('census_catalogue_rows')->insert($batch);
            }
            DB::table('census_editions')->where('id', $edition)->update(['flag_count' => $flagCount]);

            return $edition;
        });
    }

    public function verifyArchive(object $run): void
    {
        $path = $run->raw_path ? app(ArchiveFiles::class)->path($run->raw_path) : null;
        abort_unless($path && is_file($path) && hash_equals($run->sha256 ?? '', hash_file('sha256', $path)), 422, 'Archived source is missing or its checksum changed.');
    }

    public function publish(int $editionId, ?int $userId, int $expectedCurrent): void
    {
        DB::transaction(function () use ($editionId, $userId, $expectedCurrent): void {
            $edition = DB::table('census_editions')->find($editionId);
            abort_unless($edition, 404);
            $run = DB::table('import_runs')->find($edition->import_run_id);
            DB::table('import_connectors')->where('id', $run->import_connector_id)->lockForUpdate()->first();
            $pointer = DB::table('census_publications')->where('source_key', $edition->source_key)->lockForUpdate()->first();
            $current = $pointer->edition_id ?? 0;
            abort_unless((int) $current === $expectedCurrent, 409, 'Published edition changed. Reload the preview.');
            abort_unless($edition->status === 'draft' && in_array($run->status, ['needs_review', 'accepted'], true), 422, 'Only successfully extracted, non-rejected imports can be published.');
            $this->verifyArchive($run);
            DB::table('census_editions')->where('id', $current)->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('census_editions')->where('id', $editionId)->update(['status' => 'published', 'updated_at' => now()]);
            DB::table('census_publications')->where('source_key', $edition->source_key)->update(['edition_id' => $editionId]);
            DB::table('census_catalogue_reviews')->insert(['edition_id' => $editionId, 'user_id' => $userId, 'action' => $userId === null ? 'publish_with_notes_cli' : 'publish', 'created_at' => now()]);
        });
    }

    public function withdraw(int $editionId, int $userId): void
    {
        DB::transaction(function () use ($editionId, $userId): void {
            $edition = DB::table('census_editions')->find($editionId);
            abort_unless($edition, 404);
            $pointer = DB::table('census_publications')->where('source_key', $edition->source_key)->lockForUpdate()->first();
            abort_unless($pointer && $pointer->edition_id === $editionId, 409, 'This is no longer the published edition.');
            DB::table('census_publications')->where('source_key', $edition->source_key)->update(['edition_id' => null]);
            DB::table('census_editions')->where('id', $editionId)->update(['status' => 'withdrawn', 'updated_at' => now()]);
            DB::table('census_catalogue_reviews')->insert(['edition_id' => $editionId, 'user_id' => $userId, 'action' => 'withdraw', 'created_at' => now()]);
        });
    }
}
