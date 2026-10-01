<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class OriginalHistoricalCensusPackage
{
    public function importDraftPackage(string $path, string $sha256, string $directory, string $retrievedAt, bool $publish = false): array
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
            $result = DB::transaction(function () use ($verified, $manifest, $directory, $sha256, $pdf, $retrievedAt, $publish): array {
                $editions = DB::table('census_editions')->where('source_key', $manifest['source_key'])->get();
                $before = ['package_sha256' => $sha256, 'editions' => $editions->all(),
                    'rows' => DB::table('census_catalogue_rows')->whereIn('edition_id', $editions->pluck('id'))->get()->all(),
                    'publications' => DB::table('census_publications')->where('source_key', $manifest['source_key'])->get()->all(),
                    'recovery_note' => 'Only new draft editions are added. Prior editions and publication pointers remain preserved.'];
                $backupRaw = json_encode($before, JSON_THROW_ON_ERROR);
                $backup = $directory.DIRECTORY_SEPARATOR.$sha256.'-before-'.bin2hex(random_bytes(8)).'.json';
                $this->preserve($backup, $backupRaw, hash('sha256', $backupRaw));
                $partition = ['source' => ['key' => $manifest['source_key'], 'year' => $manifest['year'],
                    'original_sha256' => $manifest['original_sha256'], 'source_url' => $manifest['source_url']],
                    'evidence' => $verified, 'rows' => $verified['rows']];
                $runId = app(HistoricalCensusPackage::class)->createRun($partition, $pdf);
                $edition = $this->stageVerifiedEdition($runId, $verified, $retrievedAt);
                if ($publish) {
                    $this->publishWithNotes($edition);
                }
                $afterEditions = DB::table('census_editions')->where('source_key', $manifest['source_key'])->count();
                $afterRows = DB::table('census_catalogue_rows')->whereIn('edition_id',
                    DB::table('census_editions')->where('source_key', $manifest['source_key'])->select('id'))->count();

                return ['edition_id' => $edition, 'before_editions' => $editions->count(), 'after_editions' => $afterEditions,
                    'before_rows' => count($before['rows']), 'after_rows' => $afterRows,
                    'added_rows' => $afterRows - count($before['rows']), 'added_editions' => $afterEditions - $editions->count(),
                    'corrected_rows' => 0, 'unchanged_rows' => $afterRows === count($before['rows']) ? count($verified['rows']) : 0,
                    'baseline_backup' => $backup, 'baseline_sha256' => hash('sha256', $backupRaw),
                    'package_sha256' => $sha256, 'committed' => true, 'published' => $publish];
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

    private function publishWithNotes(int $editionId): void
    {
        $edition = DB::table('census_editions')->where('id', $editionId)->lockForUpdate()->first();
        DB::table('census_publications')->insertOrIgnore(['source_key' => $edition->source_key]);
        $pointer = DB::table('census_publications')->where('source_key', $edition->source_key)->lockForUpdate()->first();
        abort_unless($pointer->edition_id === null || (int) $pointer->edition_id === $editionId,
            409, 'Another original PCA edition is published; prior publication is preserved.');
        if ((int) $pointer->edition_id === $editionId) {
            abort_unless($edition->status === 'published', 422, 'Original PCA publication status mismatch.');

            return;
        }
        abort_unless($edition->status === 'draft', 422, 'Original PCA edition is not a publishable draft.');
        DB::table('census_editions')->where('id', $editionId)->update(['status' => 'published', 'updated_at' => now()]);
        DB::table('census_publications')->where('source_key', $edition->source_key)->update(['edition_id' => $editionId]);
        DB::table('census_catalogue_reviews')->insert([
            'edition_id' => $editionId, 'user_id' => null, 'action' => 'publish_with_notes_cli', 'created_at' => now(),
        ]);
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
                    && (int) $existing->row_count === count($rows)
                    && DB::table('census_catalogue_rows')->where('edition_id', $existing->id)->count() === count($rows),
                    422, 'Existing original PCA edition differs.');

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
            foreach ($rows as &$row) {
                abort_unless($row['level'] !== 'STATE' || $row['original_name'] === $stateEvidence['original_name'], 422, 'Original PCA state identity differs.');
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
                foreach ($row['values'] as $value) {
                    abort_unless(is_int($value) && $value >= 0, 422, 'Invalid original PCA count.');
                }
                foreach ([['TOT_P', 'TOT_M', 'TOT_F'], ['P_LIT', 'M_LIT', 'F_LIT'], ['TOT_WORK_P', 'TOT_WORK_M', 'TOT_WORK_F'], ['CULTIVATOR_P', 'CULTIVATOR_M', 'CULTIVATOR_F']] as [$total, $male, $female]) {
                    $values = $row['values'];
                    abort_unless(isset($values[$total], $values[$male], $values[$female]), 422, 'Incomplete original PCA count mapping.');
                    if ($values[$total] !== $values[$male] + $values[$female]) {
                        abort_unless(collect($row['flags'])->contains(fn (string $flag): bool => str_contains($flag, 'Source discrepancy') && str_contains($flag, $total)), 422, 'Original PCA discrepancy lacks a note.');
                    }
                }
            }

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

    private function member(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        abort_unless($stat && $stat['size'] <= 50000000, 422, 'Missing or oversized original PCA member.');
        $raw = $zip->getFromName($name);
        abort_unless(is_string($raw), 422, 'Unreadable original PCA member.');

        return $raw;
    }
}
