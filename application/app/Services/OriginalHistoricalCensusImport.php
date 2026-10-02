<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ZipArchive;

abstract class OriginalHistoricalCensusImport
{
    abstract public function verify(string $path, string $expectedSha256): array;

    abstract public function stageVerifiedEdition(int $runId, array $verified, string $retrievedAt): int;

    /** Restore only this receipt's publication pointer; keep every imported row and archive. */
    public function recoverPublication(string $receiptPath, string $receiptSha256): array
    {
        $lock = Cache::lock('census-national-collection', 3600);
        abort_unless($lock->get(), 409, 'Census collection or import is already running.');
        try {
            abort_unless(is_file($receiptPath) && preg_match('/^[a-f0-9]{64}$/', $receiptSha256)
                && hash_equals($receiptSha256, hash_file('sha256', $receiptPath)), 422, 'Import receipt checksum mismatch.');
            $receipt = json_decode(file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
            abort_unless(($receipt['committed'] ?? false) && ($receipt['published'] ?? false)
                && ($receipt['publication']['new_observation_rows'] ?? 0) > 0, 422, 'Receipt has no new publication to recover.');
            $directory = dirname($receiptPath);
            $backupPath = $receipt['baseline_backup'];
            abort_unless(dirname($backupPath) === $directory && is_file($backupPath)
                && hash_equals($receipt['baseline_sha256'], hash_file('sha256', $backupPath)), 422, 'Import baseline checksum mismatch.');
            $baseline = json_decode(file_get_contents($backupPath), true, 512, JSON_THROW_ON_ERROR);
            $verified = $this->verify($directory.DIRECTORY_SEPARATOR.$receipt['package_sha256'].'.zip', $receipt['package_sha256']);
            $sourceKey = $verified['manifest']['source_key'];
            abort_unless($baseline['package_sha256'] === $receipt['package_sha256'], 422, 'Recovery baseline belongs to another package.');

            return DB::transaction(function () use ($receipt, $baseline, $verified, $sourceKey): array {
                $pointer = DB::table('census_publications')->where('source_key', $sourceKey)->lockForUpdate()->first();
                $edition = DB::table('census_editions')->where('id', $receipt['edition_id'])->lockForUpdate()->first();
                abort_unless($edition && $edition->source_key === $sourceKey && $edition->status === 'published'
                    && $pointer && (int) $pointer->edition_id === (int) $edition->id,
                    409, 'Publication changed since this receipt; recovery refused.');
                $this->stageVerifiedEdition((int) $edition->import_run_id, $verified, $verified['retrieved_at']);
                foreach ($baseline['rows'] as $before) {
                    $saved = DB::table('census_catalogue_rows')->where('id', $before['id'])->first();
                    abort_unless($saved && (array) $saved === $before, 409, 'Prior source rows changed; recovery refused.');
                }
                foreach ($baseline['editions'] as $before) {
                    abort_unless($before['source_key'] === $sourceKey, 422, 'Baseline contains another source.');
                    DB::table('census_editions')->where('id', $before['id'])->update(['status' => $before['status'], 'updated_at' => now()]);
                }
                $previous = $baseline['publications'][0]['edition_id'] ?? null;
                DB::table('census_publications')->where('source_key', $sourceKey)->update(['edition_id' => $previous]);
                DB::table('census_editions')->where('id', $edition->id)->update(['status' => 'draft', 'updated_at' => now()]);
                DB::table('census_catalogue_reviews')->insert(['edition_id' => $edition->id, 'user_id' => null,
                    'action' => 'recover_publication_cli', 'created_at' => now()]);

                return ['recovered_source_key' => $sourceKey, 'retained_edition_id' => (int) $edition->id,
                    'restored_publication_id' => $previous, 'deleted_rows' => 0, 'committed' => true];
            });
        } finally {
            $lock->release();
        }
    }

    public function importDraftPackage(string $path, string $sha256, string $directory, string $retrievedAt, bool $publish = false, ?int $expectedCurrent = null): array
    {
        $lock = Cache::lock('census-national-collection', 3600);
        abort_unless($lock->get(), 409, 'Census collection or import is already running.');
        try {
            $verified = $this->verify($path, $sha256);
            abort_unless(strtotime($retrievedAt) === strtotime($verified['retrieved_at']), 422, 'Original Census retrieval timestamp differs from preserved receipt.');
            $retrievedAt = $verified['retrieved_at'];
            abort_unless(is_dir($directory) && is_writable($directory)
                && disk_free_space($directory) >= 10 * 1024 ** 3 + filesize($path), 422, 'Original Census archive reserve unavailable.');
            $manifest = $verified['manifest'];
            $archive = $directory.DIRECTORY_SEPARATOR.$sha256.'.zip';
            $this->preserve($archive, file_get_contents($path), $sha256);
            $zip = new ZipArchive;
            $zip->open($archive);
            try {
                $pdf = $directory.DIRECTORY_SEPARATOR.$manifest['original_sha256'].'.pdf';
                $this->preserve($pdf, $this->readOriginalMember($zip, $manifest['original']), $manifest['original_sha256']);
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
                    'publication' => $publication, 'verified_statistics' => $verified['statistics'] ?? null];
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
            abort_unless($edition->status === 'published', 422, 'Original Census publication status mismatch.');

            return ['previous_edition_id' => $editionId, 'active_before_rows' => (int) $edition->row_count,
                'active_after_rows' => (int) $edition->row_count, 'new_observation_rows' => 0,
                'retained_observation_rows' => (int) $edition->row_count, 'new_numeric_values' => 0];
        }
        $previous = (int) ($pointer->edition_id ?? 0);
        abort_unless(($previous === 0 && ($expectedCurrent === null || $expectedCurrent === 0))
            || ($previous > 0 && $expectedCurrent === $previous),
            409, 'Another original PCA edition is published; prior publication is preserved.');
        abort_unless($edition->status === 'draft', 422, 'Original Census edition is not a publishable draft.');
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
                422, 'Original Census revision source differs from published evidence.');
            $priorRows = DB::table('census_catalogue_rows')->where('edition_id', $previousId)->get();
            abort_unless($priorRows->count() === (int) $previous->row_count, 422, 'Published original PCA row count differs.');
        }
        $oldValues = 0;
        foreach ($priorRows as $prior) {
            $next = $rows->get($prior->record_key);
            abort_unless($next, 422, 'Original Census revision omits a published row.');
            foreach (['state_code', 'district_code', 'level', 'residence', 'name'] as $field) {
                abort_unless($next->{$field} === $prior->{$field}, 422, 'Original Census revision changes a published identity.');
            }
            abort_unless(json_decode($next->geography, true, 512, JSON_THROW_ON_ERROR) === json_decode($prior->geography, true, 512, JSON_THROW_ON_ERROR),
                422, 'Original Census revision changes published geography.');
            $values = json_decode($next->values, true, 512, JSON_THROW_ON_ERROR);
            foreach (json_decode($prior->values, true, 512, JSON_THROW_ON_ERROR) as $field => $value) {
                abort_unless(array_key_exists($field, $values) && $values[$field] === $value,
                    422, 'Original Census revision changes or removes a published value.');
                if (is_int($value) || (is_string($value) && is_numeric($value))) {
                    $oldValues++;
                }
            }
            abort_unless(array_diff(json_decode($prior->flags, true, 512, JSON_THROW_ON_ERROR),
                json_decode($next->flags, true, 512, JSON_THROW_ON_ERROR)) === [],
                422, 'Original Census revision removes a published note.');
        }

        return ['previous_edition_id' => $previousId ?: null, 'active_before_rows' => $priorRows->count(),
            'active_after_rows' => $rows->count(), 'new_observation_rows' => $rows->count() - $priorRows->count(),
            'retained_observation_rows' => $priorRows->count(),
            'new_numeric_values' => $rows->sum(fn (object $row): int => count(array_filter(json_decode($row->values, true, 512, JSON_THROW_ON_ERROR),
                fn (mixed $value): bool => is_int($value) || (is_string($value) && is_numeric($value))))) - $oldValues];
    }

    private function preserve(string $path, string $raw, string $sha256): void
    {
        if (is_file($path)) {
            abort_unless(hash_equals($sha256, hash_file('sha256', $path)), 422, 'Existing original PCA archive differs.');

            return;
        }
        $stream = fopen($path, 'xb');
        abort_unless($stream !== false, 422, 'Original Census evidence cannot be preserved.');
        try {
            abort_unless(fwrite($stream, $raw) === strlen($raw), 422, 'Original Census archive write incomplete.');
        } finally {
            fclose($stream);
        }
        abort_unless(hash_equals($sha256, hash_file('sha256', $path)), 422, 'Original Census archive write checksum mismatch.');
    }

    private function readOriginalMember(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        abort_unless($stat && $stat['size'] <= 50000000, 422, 'Missing or oversized original PCA member.');
        $raw = $zip->getFromName($name);
        abort_unless(is_string($raw), 422, 'Unreadable original PCA member.');

        return $raw;
    }
}
