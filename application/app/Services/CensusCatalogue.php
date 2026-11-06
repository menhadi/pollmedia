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

                return (! isset($options['census_source_key']) || $options['census_source_key'] === $key)
                    && ! ($source['archive_only'] ?? false) && $source['url'] === $run->source_url
                    && ($source['filter_value'] ?? null) === ($options['filter_value'] ?? null);
            });
            abort_unless($key, 422, 'No verified national Census mapping matches this source and scope.');
            $source = config('census-sources.'.$key);
            DB::table('census_publications')->insertOrIgnore(['source_key' => $key]);
            $year = $source['year'] ?? null;
            abort_unless(in_array($year, [2001, 2011], true), 422, 'This Census year needs a verified column mapping.');
            $this->verifyArchive($run);
            $data = json_decode($run->extracted, true, 512, JSON_THROW_ON_ERROR);
            if (($source['schema'] ?? null) === 'census-2001-source-only-originals-v1') {
                return $this->prepareSourceOnly($run, $key, $source, $data);
            }
            if (in_array($source['schema'] ?? null, ['census-vd-explicit-counts-v1', 'census-vd-all-values-v1'], true)) {
                return $this->prepareDirectoryCounts($run, $key, $source, $data);
            }
            abort_unless(! empty($data['rows']) && count($data['rows']) <= 20000, 422, 'Census table is empty or exceeds the partition limit.');
            $columns = $year === 2001 ? ['STATE', 'DISTRICT', 'TAHSIL', 'TOWN_VILL', 'WARD', 'EB', 'LEVEL', 'NAME', 'TRU'] : ['State', 'District', 'Subdistt', 'Town/Village', 'Ward', 'EB', 'Level', 'Name', 'TRU'];
            if ($year === 2001 && ($source['geography_headers'] ?? null) === ['STATE', 'DISTRICT', 'SUB-DISTT', 'TOWN_VILL', 'WARD', 'EB', 'LEVEL', 'NAME', 'TRU']) {
                $columns = $source['geography_headers'];
            }
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

    private function prepareDirectoryCounts(object $run, string $key, array $source, array $data): int
    {
        abort_unless(hash_equals($source['review_package_sha256'] ?? '', hash('sha256', $run->extracted)), 422, 'Directory review package differs from the registered verified package.');
        $allValues = ($source['schema'] ?? null) === 'census-vd-all-values-v1';
        abort_unless(($data['schema'] ?? null) === ($source['schema'] ?? null)
            && ($data['year'] ?? null) === 2001 && ($data['source_key'] ?? null) === $key
            && ($data['source_url'] ?? null) === $run->source_url
            && ($data['sha256'] ?? null) === $run->sha256
            && ($data['landing_url'] ?? null) === $source['landing'], 422, 'Directory source identity changed.');
        $fields = $source['count_fields'] ?? [];
        abort_unless($fields !== [] && ($data['fields'] ?? null) === $fields
            && ! empty($data['rows']) && count($data['rows']) <= 20000, 422, 'Directory fields or partition size are invalid.');
        $batch = [];
        $seen = [];
        $lgds = [];
        foreach ($data['rows'] as $row) {
            $identity = $row['identity'] ?? [];
            abort_unless(count($identity) === 4 && array_is_list($identity), 422, 'Complete directory identity is required.');
            foreach ([2, 2, 4, 8] as $index => $width) {
                abort_unless(is_string($identity[$index]) && preg_match('/^\d{'.$width.'}$/D', $identity[$index]), 422, 'Invalid directory identity code.');
            }
            abort_unless(array_slice($identity, 0, 2) === ($source['parent_codes'] ?? null), 422, 'Directory parent geography differs from the registered source.');
            $recordKey = hash('sha256', json_encode($identity));
            $lgd = $row['lgd'] ?? '';
            abort_unless(is_string($lgd) && preg_match('/^\d+$/D', $lgd), 422, 'Verified LGD association is required.');
            abort_if(isset($seen[$recordKey]) || isset($lgds[$lgd]), 422, 'Duplicate directory identity or LGD target.');
            $seen[$recordKey] = true;
            $lgds[$lgd] = true;
            abort_unless(($allValues || ($row['record_type_evidence']['status'] ?? null) === 'confirmed_pca_rural_village')
                && ($row['record_type_evidence']['identity'] ?? null) === $identity
                && is_string($row['source_sheet'] ?? null) && trim($row['source_sheet']) !== ''
                && is_int($row['source_row'] ?? null) && $row['source_row'] >= 2
                && is_string($row['name'] ?? null) && trim($row['name']) !== '', 422, 'Directory record type or source locator is missing.');
            abort_unless(array_keys($row['values'] ?? []) === array_keys($fields), 422, 'Directory count fields changed.');
            foreach ($fields as $field => $label) {
                $value = $row['values'][$field];
                $evidence = $row['value_evidence'][$field] ?? ($allValues ? ($data['sheet_evidence'][$row['source_sheet']][$field] ?? []) : []);
                abort_unless(($allValues ? (is_scalar($value) || $value === null) : (is_int($value) && $value >= 0)) && ($evidence['official_label'] ?? null) === $label
                    && is_int($evidence['source_column'] ?? null) && $evidence['source_column'] > 0, 422, 'Invalid directory count or cell evidence.');
            }
            $batch[] = ['record_key' => $recordKey, 'state_code' => $identity[0], 'district_code' => $identity[1],
                'level' => 'VILLAGE', 'residence' => 'Rural', 'name' => $row['name'],
                'geography' => json_encode(array_combine(['ST_CODE', 'DIST_CODE', 'THSIL_CODE', 'V_CT_CODE'], $identity)),
                'values' => json_encode($row['values']), 'flags' => json_encode($allValues ? ($row['notes'] ?? []) : []), 'source_row' => $row['source_row']];
        }
        $edition = DB::table('census_editions')->insertGetId([
            'import_run_id' => $run->id, 'source_key' => $key, 'name' => $source['name'], 'year' => 2001,
            'sha256' => $run->sha256, 'source_url' => $run->source_url, 'landing_url' => $source['landing'],
            'scope' => $data['scope'], 'fields' => json_encode(array_keys($fields)), 'row_count' => count($batch),
            'flag_count' => count(array_filter($batch, fn (array $row): bool => $row['flags'] !== '[]')),
            'retrieved_at' => $run->created_at, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (array_chunk($batch, 100) as $chunk) {
            DB::table('census_catalogue_rows')->insert(array_map(fn (array $row): array => ['edition_id' => $edition] + $row, $chunk));
        }

        return $edition;
    }


    private function prepareSourceOnly(object $run, string $key, array $source, array $data): int
    {
        abort_unless(hash_equals($source['review_package_sha256'] ?? '', hash('sha256', $run->extracted))
            && ($data['schema'] ?? null) === 'census-2001-source-only-originals-v1'
            && ($data['year'] ?? null) === 2001 && ($data['source_url'] ?? null) === $run->source_url
            && ($data['original_sha256'] ?? null) === $run->sha256
            && ($data['landing_url'] ?? null) === $source['landing'], 422, 'Original source-only evidence changed.');
        abort_unless(!empty($data['rows']) && count($data['rows']) <= 20000, 422, 'Invalid source-only partition.');
        $fields = [];
        $batch = [];
        $seen = [];
        foreach ($data['rows'] as $row) {
            abort_unless(array_key_exists('current_village_assignment', $row) && $row['current_village_assignment'] === null
                && !isset($row['lgd']) && is_string($row['source_sheet'] ?? null)
                && is_int($row['source_row'] ?? null) && $row['source_row'] >= 2
                && is_string($row['display_note'] ?? null) && trim($row['display_note']) !== '', 422, 'Source-only records must remain unassigned with an identity note.');
            $headers = $data['original_headers_by_sheet'][$row['source_sheet']] ?? null;
            $cells = $row['values_by_original_column'] ?? null;
            abort_unless(is_array($headers) && array_is_list($headers) && is_array($cells) && array_is_list($cells)
                && count($headers) === count($cells), 422, 'Original source columns changed.');
            $recordKey = hash('sha256', json_encode([$row['source_sheet'], $row['source_row']]));
            abort_if(isset($seen[$recordKey]), 422, 'Duplicate original source locator.');
            $seen[$recordKey] = true;
            $values = [];
            foreach ($cells as $index => $value) {
                abort_unless(is_scalar($value) || $value === null, 422, 'Unsupported original cell.');
                $field = 'COLUMN_'.($index + 1);
                $fields[$field] = true;
                $values[$field] = $value;
            }
            $geo = ['SOURCE_SHEET' => $row['source_sheet'], 'SOURCE_ROW' => $row['source_row'],
                'ORIGINAL_IDENTITY_AUDIT' => json_encode($row['source_identity_audit'] ?? [], JSON_UNESCAPED_UNICODE),
                'CURRENT_VILLAGE_ASSIGNMENT' => 'Unresolved; no current village assigned'];
            $batch[] = ['record_key' => $recordKey, 'state_code' => '', 'district_code' => '',
                'level' => 'SOURCE RECORD', 'residence' => 'Unresolved',
                'name' => $row['source_sheet'].' / original row '.$row['source_row'],
                'geography' => json_encode($geo), 'values' => json_encode($values),
                'flags' => json_encode([$row['display_note']]), 'source_row' => $row['source_row']];
        }
        $edition = DB::table('census_editions')->insertGetId([
            'import_run_id' => $run->id, 'source_key' => $key, 'name' => $source['name'], 'year' => 2001,
            'sha256' => $run->sha256, 'source_url' => $run->source_url, 'landing_url' => $source['landing'],
            'scope' => $data['scope'], 'fields' => json_encode(array_keys($fields)), 'row_count' => count($batch),
            'flag_count' => count($batch), 'retrieved_at' => $run->created_at, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (array_chunk($batch, 100) as $chunk) {
            DB::table('census_catalogue_rows')->insert(array_map(fn (array $row): array => ['edition_id' => $edition] + $row, $chunk));
        }

        return $edition;
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
            if (str_starts_with($edition->source_key, 'census-2001-source-only-')) {
                abort_unless(hash_equals(config('census-sources.'.$edition->source_key.'.review_package_sha256', ''), hash('sha256', $run->extracted)), 422, 'Source-only review evidence changed before publication.');
            }
            if (str_starts_with($edition->source_key, 'census-2001-village-directory-')) {
                $source = config('census-sources.'.$edition->source_key, []);
                abort_unless(in_array($source['schema'] ?? null, ['census-vd-explicit-counts-v1', 'census-vd-all-values-v1'], true)
                    && hash_equals($source['review_package_sha256'] ?? '', hash('sha256', $run->extracted)), 422, 'Directory review evidence changed before publication.');
            }
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
