<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class OriginalHistoricalCensusPackage
{
    public function importDraftPackage(string $path, string $sha256, string $directory, string $retrievedAt, bool $publish = false, ?int $expectedCurrent = null): array
    {
        $lock = Cache::lock('census-national-collection', 3600);
        abort_unless($lock->get(), 409, 'Census collection or import is already running.');
        try {
            $verified = $this->verify($path, $sha256);
            abort_unless(strtotime($retrievedAt) === strtotime($verified['retrieved_at']), 422, 'Original PCA retrieval timestamp differs from preserved receipt.');
            $retrievedAt = $verified['retrieved_at'];
            abort_unless(is_dir($directory) && is_writable($directory)
                && disk_free_space($directory) >= 10 * 1024 ** 3 + filesize($path), 422, 'Original PCA archive reserve unavailable.');
            $manifest = $verified['manifest'];
            $archive = $directory.DIRECTORY_SEPARATOR.$sha256.'.zip';
            $this->preserve($archive, file_get_contents($path), $sha256);
            $zip = new ZipArchive;
            $zip->open($archive);
            try {
                $pdf = $directory.DIRECTORY_SEPARATOR.$manifest['original_sha256'].'.pdf';
                $this->preserve($pdf, $this->member($zip, $manifest['original']), $manifest['original_sha256']);
            } finally {
                $zip->close();
            }
            $result = DB::transaction(function () use ($verified, $manifest, $directory, $sha256, $pdf, $retrievedAt, $publish, $expectedCurrent): array {
                $editions = DB::table('census_editions')->where('source_key', $manifest['source_key'])->get();
                $before = ['package_sha256' => $sha256, 'editions' => $editions->all(),
                    'rows' => DB::table('census_catalogue_rows')->whereIn('edition_id', $editions->pluck('id'))->get()->all(),
                    'publications' => DB::table('census_publications')->where('source_key', $manifest['source_key'])->get()->all(),
                    'recovery_note' => 'Prior rows and editions are preserved. For a publication revision, restore the saved pointer and prior edition status together in a transaction after checking the current pointer still targets this receipt edition.'];
                $backupRaw = json_encode($before, JSON_THROW_ON_ERROR);
                $backup = $directory.DIRECTORY_SEPARATOR.$sha256.'-before-'.bin2hex(random_bytes(8)).'.json';
                $this->preserve($backup, $backupRaw, hash('sha256', $backupRaw));
                $partition = ['source' => ['key' => $manifest['source_key'], 'year' => $manifest['year'],
                    'original_sha256' => $manifest['original_sha256'], 'source_url' => $manifest['source_url']],
                    'evidence' => $verified, 'rows' => $verified['rows']];
                $runId = app(HistoricalCensusPackage::class)->createRun($partition, $pdf);
                $edition = $this->stageVerifiedEdition($runId, $verified, $retrievedAt);
                $publication = $publish ? $this->publishWithNotes($edition, $expectedCurrent) : [];
                $afterEditions = DB::table('census_editions')->where('source_key', $manifest['source_key'])->count();
                $afterRows = DB::table('census_catalogue_rows')->whereIn('edition_id',
                    DB::table('census_editions')->where('source_key', $manifest['source_key'])->select('id'))->count();

                return ['edition_id' => $edition, 'before_editions' => $editions->count(), 'after_editions' => $afterEditions,
                    'before_rows' => count($before['rows']), 'after_rows' => $afterRows,
                    'added_rows' => $afterRows - count($before['rows']), 'added_editions' => $afterEditions - $editions->count(),
                    'corrected_rows' => 0, 'unchanged_rows' => $afterRows === count($before['rows']) ? count($verified['rows']) : 0,
                    'baseline_backup' => $backup, 'baseline_sha256' => hash('sha256', $backupRaw),
                    'package_sha256' => $sha256, 'committed' => true, 'published' => $publish,
                    'publication' => $publication];
            });
            $receiptRaw = json_encode($result, JSON_THROW_ON_ERROR);
            $receipt = $directory.DIRECTORY_SEPARATOR.$sha256.'-receipt-'.bin2hex(random_bytes(8)).'.json';
            try {
                $this->preserve($receipt, $receiptRaw, hash('sha256', $receiptRaw));
                $result['receipt'] = $receipt;
                $result['receipt_sha256'] = hash('sha256', $receiptRaw);
            } catch (\Throwable $error) {
                $result['receipt_warning'] = 'Database committed; receipt preservation failed: '.$error->getMessage();
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function publishWithNotes(int $editionId, ?int $expectedCurrent): array
    {
        $edition = DB::table('census_editions')->where('id', $editionId)->lockForUpdate()->first();
        DB::table('census_publications')->insertOrIgnore(['source_key' => $edition->source_key]);
        $pointer = DB::table('census_publications')->where('source_key', $edition->source_key)->lockForUpdate()->first();
        if ((int) $pointer->edition_id === $editionId) {
            abort_unless($edition->status === 'published', 422, 'Original PCA publication status mismatch.');

            return ['previous_edition_id' => $editionId, 'active_before_rows' => (int) $edition->row_count,
                'active_after_rows' => (int) $edition->row_count, 'new_observation_rows' => 0,
                'retained_observation_rows' => (int) $edition->row_count, 'new_numeric_values' => 0];
        }
        $previous = (int) ($pointer->edition_id ?? 0);
        abort_unless(($previous === 0 && ($expectedCurrent === null || $expectedCurrent === 0))
            || ($previous > 0 && $expectedCurrent === $previous),
            409, 'Another original PCA edition is published; prior publication is preserved.');
        abort_unless($edition->status === 'draft', 422, 'Original PCA edition is not a publishable draft.');
        $coverage = $this->verifyPublicationExpansion($editionId, $previous);
        if ($previous > 0) {
            DB::table('census_editions')->where('id', $previous)->update(['status' => 'superseded', 'updated_at' => now()]);
        }
        DB::table('census_editions')->where('id', $editionId)->update(['status' => 'published', 'updated_at' => now()]);
        DB::table('census_publications')->where('source_key', $edition->source_key)->update(['edition_id' => $editionId]);
        DB::table('census_catalogue_reviews')->insert([
            'edition_id' => $editionId, 'user_id' => null, 'action' => 'publish_with_notes_cli', 'created_at' => now(),
        ]);

        return $coverage;
    }

    private function verifyPublicationExpansion(int $editionId, int $previousId): array
    {
        $edition = DB::table('census_editions')->find($editionId);
        $rows = DB::table('census_catalogue_rows')->where('edition_id', $editionId)->get()->keyBy('record_key');
        $priorRows = collect();
        if ($previousId > 0) {
            $previous = DB::table('census_editions')->where('id', $previousId)->lockForUpdate()->first();
            abort_unless($previous && $previous->status === 'published'
                && $previous->source_key === $edition->source_key && $previous->sha256 === $edition->sha256
                && $previous->source_url === $edition->source_url && (int) $previous->year === (int) $edition->year,
                422, 'Original PCA revision source differs from published evidence.');
            $priorRows = DB::table('census_catalogue_rows')->where('edition_id', $previousId)->get();
            abort_unless($priorRows->count() === (int) $previous->row_count, 422, 'Published original PCA row count differs.');
        }
        $oldValues = 0;
        foreach ($priorRows as $prior) {
            $next = $rows->get($prior->record_key);
            abort_unless($next, 422, 'Original PCA revision omits a published row.');
            foreach (['state_code', 'district_code', 'level', 'residence', 'name'] as $field) {
                abort_unless($next->{$field} === $prior->{$field}, 422, 'Original PCA revision changes a published identity.');
            }
            abort_unless(json_decode($next->geography, true, 512, JSON_THROW_ON_ERROR) === json_decode($prior->geography, true, 512, JSON_THROW_ON_ERROR),
                422, 'Original PCA revision changes published geography.');
            $values = json_decode($next->values, true, 512, JSON_THROW_ON_ERROR);
            foreach (json_decode($prior->values, true, 512, JSON_THROW_ON_ERROR) as $field => $value) {
                abort_unless(array_key_exists($field, $values) && $values[$field] === $value,
                    422, 'Original PCA revision changes or removes a published value.');
                $oldValues++;
            }
            abort_unless(array_diff(json_decode($prior->flags, true, 512, JSON_THROW_ON_ERROR),
                json_decode($next->flags, true, 512, JSON_THROW_ON_ERROR)) === [],
                422, 'Original PCA revision removes a published note.');
        }

        return ['previous_edition_id' => $previousId ?: null, 'active_before_rows' => $priorRows->count(),
            'active_after_rows' => $rows->count(), 'new_observation_rows' => $rows->count() - $priorRows->count(),
            'retained_observation_rows' => $priorRows->count(),
            'new_numeric_values' => $rows->sum(fn (object $row): int => count(json_decode($row->values, true, 512, JSON_THROW_ON_ERROR))) - $oldValues];
    }

    private function preserve(string $path, string $raw, string $sha256): void
    {
        if (is_file($path)) {
            abort_unless(hash_equals($sha256, hash_file('sha256', $path)), 422, 'Existing original PCA archive differs.');

            return;
        }
        $stream = fopen($path, 'xb');
        abort_unless($stream !== false, 422, 'Original PCA evidence cannot be preserved.');
        try {
            abort_unless(fwrite($stream, $raw) === strlen($raw), 422, 'Original PCA archive write incomplete.');
        } finally {
            fclose($stream);
        }
        abort_unless(hash_equals($sha256, hash_file('sha256', $path)), 422, 'Original PCA archive write checksum mismatch.');
    }

    public function stageVerifiedEdition(int $runId, array $verified, string $retrievedAt): int
    {
        $manifest = $verified['manifest'];
        $rows = $verified['rows'];
        abort_unless(strtotime($retrievedAt) !== false, 422, 'Original PCA retrieval date is invalid.');

        return DB::transaction(function () use ($runId, $verified, $manifest, $rows, $retrievedAt): int {
            $run = DB::table('import_runs')->where('id', $runId)->lockForUpdate()->first();
            abort_unless($run && in_array($run->status, ['needs_review', 'accepted'], true)
                && $run->sha256 === $manifest['original_sha256'] && $run->source_url === $manifest['source_url']
                && json_decode($run->extracted, true, 512, JSON_THROW_ON_ERROR) === $verified,
                422, 'Original PCA run differs from verified evidence.');
            $existing = DB::table('census_editions')->where('import_run_id', $runId)->first();
            if ($existing) {
                abort_unless($existing->source_key === $manifest['source_key']
                    && $existing->sha256 === $manifest['original_sha256']
                    && $existing->source_url === $manifest['source_url'] && (int) $existing->year === $manifest['year']
                    && (int) $existing->row_count === count($rows)
                    && DB::table('census_catalogue_rows')->where('edition_id', $existing->id)->count() === count($rows),
                    422, 'Existing original PCA edition differs.');
                $stored = DB::table('census_catalogue_rows')->where('edition_id', $existing->id)->get()->keyBy('record_key');
                foreach ($rows as $row) {
                    $saved = $stored->get($row['record_key']);
                    $geography = $saved ? json_decode($saved->geography, true, 512, JSON_THROW_ON_ERROR) : [];
                    abort_unless($saved && $saved->name === $row['original_name'] && $saved->level === $row['level']
                        && $saved->residence === $row['residence']
                        && $saved->state_code === 'O'.substr(hash('sha256', $manifest['source_key']), 0, 9)
                        && $saved->district_code === ($row['level'] === 'STATE' ? '000' : 'O'.substr(hash('sha256', $manifest['source_key'].':'.$row['original_serial']), 0, 9))
                        && ($geography['original_name'] ?? null) === $row['original_name']
                        && ($geography['original_serial'] ?? null) === $row['original_serial']
                        && ($geography['source_record_identity'] ?? null) === $row['source_record_identity']
                        && ($geography['year'] ?? null) === $manifest['year']
                        && ($geography['boundary_basis'] ?? null) === $manifest['boundary_basis']
                        && json_decode($saved->values, true, 512, JSON_THROW_ON_ERROR) === $row['values']
                        && json_decode($saved->flags, true, 512, JSON_THROW_ON_ERROR) === $row['flags'],
                        422, 'Existing original PCA stored evidence differs.');
                }

                return $existing->id;
            }
            $fields = array_values(array_unique(array_merge(...array_map(fn (array $row): array => array_keys($row['values']), $rows))));
            $editionId = DB::table('census_editions')->insertGetId([
                'import_run_id' => $runId, 'source_key' => $manifest['source_key'],
                'name' => 'Original Census '.$manifest['year'].' — '.$rows[0]['original_name'].' Primary Census Abstract',
                'year' => $manifest['year'], 'status' => 'draft', 'sha256' => $manifest['original_sha256'],
                'source_url' => $manifest['source_url'],
                'landing_url' => 'https://censusindia.gov.in/nada/index.php/catalog/'.$manifest['catalogue'],
                'scope' => $manifest['boundary_basis'].'; '.$manifest['coverage'],
                'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'row_count' => count($rows),
                'flag_count' => count(array_filter($rows, fn (array $row): bool => $row['flags'] !== [])),
                'retrieved_at' => $retrievedAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $stateIdentity = 'O'.substr(hash('sha256', $manifest['source_key']), 0, 9);
            foreach ($rows as $index => $row) {
                DB::table('census_catalogue_rows')->insert([
                    'edition_id' => $editionId, 'record_key' => $row['record_key'],
                    'state_code' => $stateIdentity,
                    'district_code' => $row['level'] === 'STATE' ? '000' : 'O'.substr(hash('sha256', $manifest['source_key'].':'.$row['original_serial']), 0, 9),
                    'level' => $row['level'], 'residence' => $row['residence'], 'name' => $row['original_name'],
                    'geography' => json_encode(['original_name' => $row['original_name'],
                        'original_serial' => $row['original_serial'], 'source_record_identity' => $row['source_record_identity'],
                        'year' => $manifest['year'], 'boundary_basis' => $manifest['boundary_basis'],
                        'identifier_basis' => 'Source-scoped navigation token; not Census or LGD code'], JSON_THROW_ON_ERROR),
                    'values' => json_encode($row['values'], JSON_THROW_ON_ERROR),
                    'flags' => json_encode($row['flags'], JSON_THROW_ON_ERROR), 'source_row' => $index + 1,
                ]);
            }

            return $editionId;
        });
    }

    public function verify(string $path, string $expectedSha256): array
    {
        abort_unless(is_file($path) && preg_match('/^[a-f0-9]{64}$/', $expectedSha256)
            && hash_equals($expectedSha256, hash_file('sha256', $path)), 422, 'Original PCA package checksum mismatch.');
        $zip = new ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'Unreadable original PCA package.');
        try {
            $manifest = json_decode($this->member($zip, 'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            abort_unless(($manifest['family'] ?? null) === 'historical-census-original-pca'
                && ($manifest['version'] ?? null) === 1 && is_int($manifest['year'] ?? null)
                && is_string($manifest['catalogue'] ?? null) && ctype_digit($manifest['catalogue'])
                && ! empty($manifest['boundary_basis']) && is_array($manifest['files'] ?? null), 422, 'Invalid original PCA manifest.');
            $catalogue = $manifest['catalogue'];
            $year = $manifest['year'];
            abort_unless($catalogue === '30750' && $year === 1961, 422, 'Original PCA source adapter is not yet available for this catalogue/year.');
            abort_unless($manifest['source_key'] === 'census-original-pca-'.$catalogue.'-'.$year
                && str_starts_with($manifest['source_url'], 'https://censusindia.gov.in/nada/index.php/catalog/'.$catalogue.'/download/'), 422, 'Original PCA source mismatch.');
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                abort_if(isset($names[$name]), 422, 'Duplicate original PCA member.');
                $names[$name] = true;
            }
            abort_unless(count($names) === count($manifest['files']) + 1, 422, 'Unregistered original PCA member.');
            foreach ($manifest['files'] as $name => $digest) {
                abort_unless(preg_match('~^(originals|evidence)/[A-Za-z0-9_.-]+$~', $name)
                    && is_string($digest) && preg_match('/^[a-f0-9]{64}$/', $digest)
                    && hash_equals($digest, hash('sha256', $this->member($zip, $name))), 422, 'Original PCA member mismatch.');
            }
            abort_unless(($manifest['files'][$manifest['original']] ?? null) === $manifest['original_sha256']
                && str_starts_with($this->member($zip, $manifest['original']), '%PDF-'), 422, 'Original PCA PDF mismatch.');
            $audit = json_decode($this->member($zip, 'evidence/pca-mapping-audit-20261001T1901.json'), true, 512, JSON_THROW_ON_ERROR);
            $rows = $audit['rows'];
            abort_unless(count($rows) === $manifest['row_count'] && count($rows) > 0, 422, 'Original PCA row count mismatch.');
            $stateEvidence = json_decode($this->member($zip, 'evidence/kerala-pca-state-verified-candidates-v2.json'), true, 512, JSON_THROW_ON_ERROR);
            $districtEvidence = json_decode($this->member($zip, 'evidence/kerala-pca-cannanore-verified-candidates.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ([$stateEvidence, $districtEvidence] as $evidence) {
                abort_unless(($evidence['original_sha256'] ?? null) === $manifest['original_sha256']
                    && ($evidence['year'] ?? null) === $year, 422, 'Original PCA supplemental evidence differs.');
            }
            $additional = $this->additionalDistrictRows($zip, $manifest);
            foreach ($rows as &$row) {
                abort_unless($row['level'] !== 'STATE' || $row['original_name'] === $stateEvidence['original_name'], 422, 'Original PCA state identity differs.');
                $additionalIdentity = $row['original_serial'].':'.$row['residence'];
                if ($row['level'] === 'DISTRICT' && isset($additional[$additionalIdentity])) {
                    $candidate = $additional[$additionalIdentity];
                    $actual = $row['values'];
                    $expected = $candidate['values'];
                    ksort($actual);
                    ksort($expected);
                    abort_unless($row['original_name'] === $candidate['original_name'] && $actual === $expected,
                        422, 'Original PCA district mapping differs from verified cells.');
                    foreach ($candidate['source_discrepancy_notes'] as $note) {
                        abort_unless(in_array($note, $row['flags'], true), 422, 'Original PCA source discrepancy note is not preserved.');
                    }
                    $row['values']['AREA_ACRES'] = $candidate['area_acres_original'];

                    continue;
                }
                $supplement = $row['level'] === 'STATE' ? $stateEvidence['rows'] : $districtEvidence['rows'];
                $matches = array_values(array_filter($supplement, fn (array $candidate): bool => $candidate['residence'] === $row['residence']
                    && ($row['level'] === 'STATE' || ($candidate['original_serial'] === $row['original_serial'] && $candidate['original_name'] === $row['original_name']))));
                abort_unless(count($matches) === 1, 422, 'Original PCA supplemental row identity differs.');
                $candidate = $matches[0];
                $row['values']['OCCUPIED_HOUSES'] = $candidate['occupied_residential_houses'];
                foreach (['P', 'M', 'F'] as $index => $sex) {
                    $row['values']['CULTIVATOR_'.$sex] = $candidate['cultivators'][$index];
                }
            }
            unset($row);
            $seen = [];
            foreach ($rows as $row) {
                $identity = $catalogue.':'.$year.':'.$row['original_serial'].':'.$row['residence'];
                abort_unless($row['source_record_identity'] === $identity && ! isset($seen[$identity])
                    && $row['record_key'] === hash('sha256', $identity)
                    && in_array($row['level'], ['STATE', 'DISTRICT'], true)
                    && in_array($row['residence'], ['Total', 'Rural', 'Urban'], true)
                    && is_string($row['original_name']) && trim($row['original_name']) !== ''
                    && is_array($row['flags']) && $row['flags'] !== [], 422, 'Original PCA row identity mismatch.');
                $seen[$identity] = true;
                foreach ($row['flags'] as $flag) {
                    abort_unless(is_string($flag) && trim($flag) !== '', 422, 'Invalid original PCA note.');
                }
                foreach ($row['values'] as $field => $value) {
                    if ($field === 'AREA_ACRES') {
                        abort_unless(is_string($value) && preg_match('/^(0|[1-9][0-9]*)\.[0-9]{2}$/', $value)
                            && isset($additional[$row['original_serial'].':'.$row['residence']])
                            && ($manifest['measure_units']['AREA_ACRES'] ?? null) === 'acres (original source)',
                            422, 'Original PCA acreage lacks exact source evidence or units.');
                    } else {
                        abort_unless(is_int($value) && $value >= 0, 422, 'Invalid original PCA count.');
                    }
                }
                foreach ([['TOT_P', 'TOT_M', 'TOT_F'], ['P_LIT', 'M_LIT', 'F_LIT'], ['TOT_WORK_P', 'TOT_WORK_M', 'TOT_WORK_F'], ['CULTIVATOR_P', 'CULTIVATOR_M', 'CULTIVATOR_F']] as [$total, $male, $female]) {
                    $values = $row['values'];
                    abort_unless(isset($values[$total], $values[$male], $values[$female]), 422, 'Incomplete original PCA count mapping.');
                    if ($values[$total] !== $values[$male] + $values[$female]) {
                        abort_unless(collect($row['flags'])->contains(fn (string $flag): bool => str_contains($flag, 'Source discrepancy') && str_contains($flag, $total)), 422, 'Original PCA discrepancy lacks a note.');
                    }
                }
            }
            $this->validateReconciliations($rows);

            $retrievals = [];
            foreach (array_keys($manifest['files']) as $name) {
                if (! str_starts_with($name, 'evidence/pdf-collection-') || ! str_ends_with($name, '.json')) {
                    continue;
                }
                $receipt = json_decode($this->member($zip, $name), true, 512, JSON_THROW_ON_ERROR);
                if (($receipt['url'] ?? null) === $manifest['source_url'] && ($receipt['sha256'] ?? null) === $manifest['original_sha256']) {
                    abort_unless(($receipt['status'] ?? null) === 'preserved' && is_string($receipt['retrieved_at'] ?? null)
                        && strtotime($receipt['retrieved_at']) !== false, 422, 'Invalid original PCA retrieval receipt.');
                    $retrievals[] = $receipt['retrieved_at'];
                }
            }
            abort_unless(count($retrievals) === 1, 422, 'Original PCA requires one matching retrieval receipt.');

            return ['manifest' => $manifest, 'rows' => $rows, 'retrieved_at' => $retrievals[0]];
        } finally {
            $zip->close();
        }
    }

    private function additionalDistrictRows(ZipArchive $zip, array $manifest): array
    {
        $adapters = ['4' => ['trichur', 'TRICHUR DISTRICT', 182], '5' => ['ernakulam', 'ERNAKULAM DISTRICT', 188],
            '6' => ['kottayam', 'KOTTAYAM DISTRICT', 194], '7' => ['alleppey', 'ALLEPPEY DISTRICT', 194],
            '8' => ['quilon', 'QUILON DISTRICT', 200], '9' => ['trivandrum', 'TRIVANDRUM DISTRICT', 206]];
        $rows = [];
        $members = $manifest['supplemental_district_evidence'] ?? [];
        abort_unless(is_array($members), 422, 'Invalid original PCA district evidence registry.');
        foreach ($members as $serial => $member) {
            $adapter = $adapters[$serial] ?? null;
            abort_unless($adapter && is_string($member)
                && in_array($member, ['evidence/kerala-pca-'.$adapter[0].'-core-verified-candidates.json',
                    'evidence/kerala-pca-'.$adapter[0].'-full-verified-candidates.json'], true)
                && isset($manifest['files'][$member]), 422, 'Unsupported original PCA district evidence.');
            $evidence = json_decode($this->member($zip, $member), true, 512, JSON_THROW_ON_ERROR);
            $pages = range($adapter[2], $adapter[2] + (str_contains($member, '-full-') ? 5 : 2));
            abort_unless(($evidence['source_catalogue'] ?? null) === 30750 && ($evidence['year'] ?? null) === 1961
                && ($evidence['original_sha256'] ?? null) === $manifest['original_sha256']
                && ($evidence['physical_pages'] ?? null) === $pages
                && ($evidence['printed_pages'] ?? null) === array_map(fn (int $page): int => $page - 16, $pages)
                && count($evidence['rows'] ?? []) === 3, 422, 'Original PCA district page provenance differs.');
            foreach ($pages as $page) {
                abort_unless(isset($evidence['render_hashes'][(string) $page])
                    && ($manifest['files']['evidence/kerala-1961-pca-'.$page.'.png'] ?? null) === $evidence['render_hashes'][(string) $page],
                    422, 'Original PCA district page rendering is not preserved.');
            }
            foreach ($evidence['render_hashes'] as $page => $digest) {
                abort_unless(preg_match('/^([0-9]+)(_high)?$/', (string) $page, $match)
                    && in_array((int) $match[1], $pages, true)
                    && ($manifest['files']['evidence/kerala-1961-pca-'.str_replace('_', '-', (string) $page).'.png'] ?? null) === $digest,
                    422, 'Original PCA supplemental rendering is not preserved.');
            }
            foreach (['digit_review_render', 'reading_review_render'] as $reviewKey) {
                if (! isset($evidence[$reviewKey])) {
                    continue;
                }
                $review = $evidence[$reviewKey];
                abort_unless(is_array($review) && is_string($review['filename'] ?? null)
                    && preg_match('/^kerala-1961-pca-([0-9]+)-[a-z-]+\.png$/', $review['filename'], $match)
                    && in_array((int) $match[1], $pages, true)
                    && isset($review['sha256']) && ($manifest['files']['evidence/'.$review['filename']] ?? null) === $review['sha256'],
                    422, 'Original PCA digit review rendering is not preserved.');
            }
            foreach ($evidence['rows'] as $candidate) {
                $identity = $serial.':'.($candidate['residence'] ?? '');
                abort_unless(($candidate['original_serial'] ?? null) === (string) $serial
                    && ($candidate['original_name'] ?? null) === $adapter[1]
                    && ($candidate['parent_original_name'] ?? null) === 'KERALA' && ($candidate['level'] ?? null) === 'DISTRICT'
                    && in_array($candidate['residence'] ?? null, ['Total', 'Rural', 'Urban'], true)
                    && ! isset($rows[$identity]) && is_array($candidate['values'] ?? null)
                    && is_string($candidate['area_acres_original'] ?? null), 422, 'Original PCA district evidence identity differs.');
                $candidate['source_discrepancy_notes'] = array_values(array_filter($evidence['notes'] ?? [],
                    fn (string $note): bool => str_contains($note, 'Source discrepancy')));
                $rows[$identity] = $candidate;
            }
        }

        return $rows;
    }

    private function validateReconciliations(array $rows): void
    {
        $groups = [];
        $categories = ['II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX'];
        foreach ($rows as $row) {
            $values = $row['values'];
            $groups[$row['level'].':'.$row['original_serial']][$row['residence']] = $row;
            foreach (array_merge(['SC', 'ST', 'NON_WORK'], array_map(fn (string $category): string => 'WORK_CATEGORY_'.$category, $categories)) as $prefix) {
                if (isset($values[$prefix.'_P'], $values[$prefix.'_M'], $values[$prefix.'_F'])) {
                    $this->requireReconciliation($row, $prefix.'_P', $values[$prefix.'_P'] === $values[$prefix.'_M'] + $values[$prefix.'_F']);
                }
            }
            foreach (['P', 'M', 'F'] as $sex) {
                if (isset($values['NON_WORK_'.$sex])) {
                    $this->requireReconciliation($row, 'TOT_'.$sex, $values['TOT_'.$sex] === $values['TOT_WORK_'.$sex] + $values['NON_WORK_'.$sex]);
                }
                $fields = array_map(fn (string $category): string => 'WORK_CATEGORY_'.$category.'_'.$sex, $categories);
                if (count(array_intersect($fields, array_keys($values))) === count($fields)) {
                    $sum = $values['CULTIVATOR_'.$sex];
                    foreach ($fields as $field) {
                        $sum += $values[$field];
                    }
                    $this->requireReconciliation($row, 'TOT_WORK_'.$sex, $values['TOT_WORK_'.$sex] === $sum);
                }
            }
        }
        foreach ($groups as $group) {
            if (! isset($group['Total'], $group['Rural'], $group['Urban'])) {
                continue;
            }
            foreach ($group['Total']['values'] as $field => $value) {
                if (is_int($value) && isset($group['Rural']['values'][$field], $group['Urban']['values'][$field])) {
                    $this->requireReconciliation($group['Total'], $field, $value === $group['Rural']['values'][$field] + $group['Urban']['values'][$field]);
                }
            }
        }
    }

    private function requireReconciliation(array $row, string $field, bool $matches): void
    {
        abort_unless($matches || collect($row['flags'])->contains(fn (string $flag): bool => str_contains($flag, 'Source discrepancy') && str_contains($flag, $field)),
            422, 'Original PCA discrepancy lacks a note.');
    }

    private function member(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        abort_unless($stat && $stat['size'] <= 50000000, 422, 'Missing or oversized original PCA member.');
        $raw = $zip->getFromName($name);
        abort_unless(is_string($raw), 422, 'Unreadable original PCA member.');

        return $raw;
    }
}
