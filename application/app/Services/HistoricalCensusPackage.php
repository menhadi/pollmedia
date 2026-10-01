<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class HistoricalCensusPackage
{
    public function importDraftPackage(string $path, string $sha256, string $directory, bool $publish = false): array
    {
        $lock = Cache::lock('census-national-collection', 3600);
        abort_unless($lock->get(), 409, 'Census collection or import is already running.');
        try {
            $archive = $this->archivePackage($path, $sha256, $directory);
            $partitions = $this->verify($archive, $sha256);

            $result = DB::transaction(function () use ($partitions, $archive, $directory, $sha256, $publish): array {
                $keys = array_column(array_column($partitions, 'source'), 'key');
                $editions = DB::table('census_editions')->whereIn('source_key', $keys)->get();
                $snapshot = ['package_sha256' => $sha256, 'source_keys' => $keys,
                    'editions' => $editions->all(),
                    'rows' => DB::table('census_catalogue_rows')->whereIn('edition_id', $editions->pluck('id'))->get()->all(),
                    'publications' => DB::table('census_publications')->whereIn('source_key', $keys)->get()->all(),
                    'recovery_note' => 'Import adds drafts without modifying prior editions. Preserve originals; use this baseline and committed receipt IDs for scoped recovery.'];
                $snapshotRaw = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                $backup = $directory.DIRECTORY_SEPARATOR.$sha256.'-before-'.bin2hex(random_bytes(8)).'.json';
                $stream = fopen($backup, 'xb');
                abort_unless($stream !== false, 422, 'Cannot create historical baseline backup.');
                try {
                    abort_unless(fwrite($stream, $snapshotRaw) === strlen($snapshotRaw), 422, 'Historical baseline backup incomplete.');
                } finally {
                    fclose($stream);
                }
                $backupSha = hash('sha256', $snapshotRaw);
                abort_unless(hash_equals($backupSha, hash_file('sha256', $backup)), 422, 'Historical baseline backup checksum mismatch.');
                $before = DB::table('census_editions')->whereIn('source_key', $keys)->count();
                $beforeRows = DB::table('census_catalogue_rows')->whereIn('edition_id',
                    DB::table('census_editions')->select('id')->whereIn('source_key', $keys))->count();
                $ids = [];
                foreach ($partitions as $partition) {
                    $ids[] = $this->prepareEdition($this->createRun($partition, $archive), $partition);
                }
                if ($publish) {
                    foreach ($ids as $id) {
                        $edition = DB::table('census_editions')->where('id', $id)->lockForUpdate()->first();
                        DB::table('census_publications')->insertOrIgnore(['source_key' => $edition->source_key]);
                        $pointer = DB::table('census_publications')->where('source_key', $edition->source_key)->lockForUpdate()->first();
                        abort_unless($pointer->edition_id === null || (int) $pointer->edition_id === $id,
                            409, 'Another historical edition is published; prior publication is preserved.');
                        if ((int) $pointer->edition_id === $id) {
                            abort_unless($edition->status === 'published', 422, 'Historical publication status mismatch.');

                            continue;
                        }
                        abort_unless($edition->status === 'draft', 422, 'Historical edition is not a publishable draft.');
                        DB::table('census_editions')->where('id', $id)->update(['status' => 'published', 'updated_at' => now()]);
                        DB::table('census_publications')->where('source_key', $edition->source_key)->update(['edition_id' => $id]);
                        DB::table('census_catalogue_reviews')->insert(['edition_id' => $id, 'user_id' => null,
                            'action' => 'publish_with_notes_cli', 'created_at' => now()]);
                    }
                }
                $after = DB::table('census_editions')->whereIn('source_key', $keys)->count();
                $afterRows = DB::table('census_catalogue_rows')->whereIn('edition_id',
                    DB::table('census_editions')->select('id')->whereIn('source_key', $keys))->count();

                return ['edition_ids' => $ids, 'before_editions' => $before, 'after_editions' => $after,
                    'before_rows' => $beforeRows, 'after_rows' => $afterRows,
                    'added_rows' => $afterRows - $beforeRows, 'added_editions' => $after - $before,
                    'unchanged_partitions' => count($partitions) - ($after - $before),
                    'published' => $publish, 'archive' => $archive, 'baseline_backup' => $backup, 'baseline_sha256' => $backupSha];
            });
            $result['committed'] = true;
            $result['package_sha256'] = $sha256;
            $receipt = $directory.DIRECTORY_SEPARATOR.$sha256.'-receipt-'.bin2hex(random_bytes(8)).'.json';
            try {
                $raw = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                $stream = fopen($receipt, 'xb');
                abort_unless($stream !== false, 422, 'Cannot create committed import receipt.');
                try {
                    abort_unless(fwrite($stream, $raw) === strlen($raw), 422, 'Committed import receipt incomplete.');
                } finally {
                    fclose($stream);
                }
                $receiptSha = hash('sha256', $raw);
                abort_unless(hash_equals($receiptSha, hash_file('sha256', $receipt)), 422, 'Committed import receipt checksum mismatch.');
                $result['receipt'] = $receipt;
                $result['receipt_sha256'] = $receiptSha;
            } catch (\Throwable $error) {
                $result['receipt_warning'] = 'Database transaction committed; receipt persistence failed: '.$error->getMessage();
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    public function createRun(array $partition, string $archivePath): int
    {
        $source = $partition['source'];
        abort_unless(is_file($archivePath), 422, 'Historical source package must be archived first.');

        return DB::transaction(function () use ($partition, $source, $archivePath): int {
            $name = 'Historical Census '.$source['key'];
            $connector = DB::table('import_connectors')->where('name', $name)->lockForUpdate()->first();
            if ($connector) {
                abort_unless($connector->url === $source['source_url'], 422, 'Historical connector provenance differs.');
                $connectorId = $connector->id;
            } else {
                $connectorId = DB::table('import_connectors')->insertGetId([
                    'name' => $name, 'url' => $source['source_url'], 'format' => 'json',
                    'record_key' => 'state_code,district_code,year',
                    'options' => json_encode(['historical_source_key' => $source['key']]),
                    'automatic' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $extracted = json_encode($partition['evidence'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $existing = DB::table('import_runs')->where('import_connector_id', $connectorId)
                ->where('sha256', $source['original_sha256'])->lockForUpdate()->get();
            foreach ($existing as $run) {
                if (json_decode($run->extracted, true, 512, JSON_THROW_ON_ERROR) === $partition['evidence']) {
                    return $run->id;
                }
            }

            return DB::table('import_runs')->insertGetId([
                'import_connector_id' => $connectorId, 'status' => 'needs_review', 'origin' => 'upload',
                'source_url' => $source['source_url'], 'sha256' => $source['original_sha256'],
                'raw_path' => $archivePath, 'extracted' => $extracted,
                'base_run_id' => $existing->first()?->id,
                'summary' => json_encode(['historical_source_key' => $source['key'], 'row_count' => count($partition['rows']),
                    'warning' => $existing->isEmpty() ? null : 'Extraction revised for the same original; prior evidence is preserved.']),
                'created_at' => now(),
            ]);
        });
    }

    public function archivePackage(string $path, string $sha256, string $directory): string
    {
        $this->verify($path, $sha256);
        abort_unless(is_dir($directory) && is_writable($directory), 422, 'Historical archive directory is unavailable.');
        $target = $directory.DIRECTORY_SEPARATOR.$sha256.'.zip';
        if (is_file($target)) {
            abort_unless(hash_equals($sha256, hash_file('sha256', $target)), 422, 'Existing historical archive checksum mismatch.');

            return $target;
        }
        $input = fopen($path, 'rb');
        $output = fopen($target, 'xb');
        abort_unless($input !== false && $output !== false, 422, 'Cannot preserve historical package.');
        try {
            abort_unless(stream_copy_to_stream($input, $output) === filesize($path), 422, 'Historical archive transfer incomplete.');
        } finally {
            fclose($input);
            fclose($output);
        }
        abort_unless(hash_equals($sha256, hash_file('sha256', $target)), 422, 'Historical archive checksum mismatch.');

        return $target;
    }

    public function prepareEdition(int $runId, array $partition): int
    {
        return DB::transaction(function () use ($runId, $partition): int {
            $run = DB::table('import_runs')->where('id', $runId)->lockForUpdate()->first();
            $source = $partition['source'];
            $evidenceRows = array_values(array_filter($partition['evidence']['records'] ?? [],
                fn ($row) => ($row['year'] ?? null) === $source['year']));
            abort_unless($evidenceRows !== [] && $evidenceRows === $partition['rows'],
                422, 'Historical rows differ from preserved extraction evidence.');
            abort_unless($run && $run->sha256 === $source['original_sha256']
                && $run->source_url === $source['source_url']
                && in_array($run->status, ['needs_review', 'accepted'], true)
                && json_decode($run->extracted, true, 512, JSON_THROW_ON_ERROR) === $partition['evidence'],
                422, 'Historical import run does not match verified evidence.');
            $existing = DB::table('census_editions')->where('import_run_id', $runId)->first();
            if ($existing) {
                abort_unless($existing->source_key === $source['key'] && (int) $existing->year === $source['year'],
                    422, 'Historical import run already belongs to another partition.');
                abort_unless((int) $existing->row_count === count($partition['rows'])
                    && DB::table('census_catalogue_rows')->where('edition_id', $existing->id)->count() === count($partition['rows']),
                    422, 'Existing historical edition row count mismatch.');

                return $existing->id;
            }
            $id = DB::table('census_editions')->insertGetId([
                'import_run_id' => $runId, 'source_key' => $source['key'],
                'name' => $partition['evidence']['source']['name'] ?? 'Census A-02 '.$source['key'], 'year' => $source['year'],
                'status' => 'draft', 'sha256' => $source['original_sha256'],
                'source_url' => $source['source_url'], 'landing_url' => $source['landing_url'],
                'scope' => $partition['evidence']['boundary_basis'],
                'fields' => json_encode(['TOT_P', 'TOT_M', 'TOT_F']),
                'row_count' => count($partition['rows']),
                'flag_count' => count($partition['rows']),
                'retrieved_at' => $partition['evidence']['source']['retrieved_at'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($this->catalogueRows($partition, $id), 100) as $batch) {
                DB::table('census_catalogue_rows')->insert($batch);
            }
            abort_unless(DB::table('census_catalogue_rows')->where('edition_id', $id)->count() === count($partition['rows']),
                422, 'Historical inserted row count mismatch.');

            return $id;
        });
    }

    public function coverage(array $partitions): array
    {
        $identities = [];
        $rows = 0;
        $years = [];
        foreach ($partitions as $partition) {
            $year = $partition['source']['year'];
            $years[$year] = ($years[$year] ?? 0) + count($partition['rows']);
            foreach ($partition['rows'] as $row) {
                $rows++;
                $identities[$year.':'.$row['state_code'].':'.$row['district_code']] = true;
            }
        }
        ksort($years);

        return ['partitions' => count($partitions), 'source_rows' => $rows,
            'unique_geography_years' => count($identities),
            'overlapping_source_rows' => $rows - count($identities), 'source_rows_by_year' => $years];
    }

    public function catalogueRows(array $partition, int $editionId): array
    {
        return array_map(function (array $row) use ($partition, $editionId): array {
            $geography = ['State' => $row['state_code'], 'District' => $row['district_code'],
                'Name' => $row['name'], 'year' => $row['year'],
                'boundary_basis' => $partition['evidence']['boundary_basis']];

            return ['edition_id' => $editionId,
                'record_key' => hash('sha256', $partition['source']['key'].':'.$row['state_code'].':'.$row['district_code']),
                'state_code' => $row['state_code'], 'district_code' => $row['district_code'],
                'level' => $row['state_code'] === '00' ? 'INDIA' : ($row['district_code'] === '000' ? 'STATE' : 'DISTRICT'),
                'residence' => 'Total', 'name' => $row['name'],
                'geography' => json_encode($geography, JSON_THROW_ON_ERROR),
                'values' => json_encode(['TOT_P' => $row['persons'], 'TOT_M' => $row['males'], 'TOT_F' => $row['females']], JSON_THROW_ON_ERROR),
                'flags' => json_encode(array_values(array_unique([...$row['flags'],
                    'Historical population is reported on retrospective 2011 boundaries; see the official source and its footnotes.'])), JSON_THROW_ON_ERROR),
                'source_row' => $row['source_row']];
        }, $partition['rows']);
    }

    public function verify(string $path, string $expected): array
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $expected) && is_file($path)
            && hash_equals($expected, hash_file('sha256', $path)), 422, 'Historical package checksum mismatch.');
        $zip = new ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'Cannot open historical package.');
        try {
            $manifest = json_decode($this->member($zip, 'manifest.json', 1000000), true, 512, JSON_THROW_ON_ERROR);
            abort_unless(($manifest['version'] ?? null) === 1 && ($manifest['family'] ?? null) === 'historical-census-a02'
                && ($manifest['selected_years'] ?? null) === [1901, 1911]
                && ! empty($manifest['sources']) && count($manifest['sources']) <= 72
                && ! empty($manifest['files']), 422, 'Unsupported historical package.');
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                abort_if(isset($names[$name]), 422, 'Duplicate package member.');
                $names[$name] = true;
            }
            abort_unless(count($names) === count($manifest['files']) + 1, 422, 'Unexpected package members.');
            foreach ($manifest['files'] as $name => $digest) {
                abort_unless(preg_match('~^(originals/433\d{2}\.xlsx?|extracted/433\d{2}\.1901-1911\.json)$~', $name)
                    && is_string($digest) && preg_match('/^[a-f0-9]{64}$/', $digest)
                    && hash_equals($digest, hash('sha256', $this->member($zip, $name, 20000000))), 422, 'Historical member checksum or identity mismatch.');
            }
            $seen = [];
            $partitions = [];
            foreach ($manifest['sources'] as $source) {
                $id = (string) ($source['catalogue'] ?? '');
                $year = $source['year'] ?? null;
                $key = 'census-a02-'.$id.'-'.$year;
                abort_unless(ctype_digit($id) && (int) $id >= 43333 && (int) $id <= 43368
                    && in_array($year, [1901, 1911], true) && ($source['key'] ?? null) === $key
                    && ! isset($seen[$key]) && ($source['extracted'] ?? null) === 'extracted/'.$id.'.1901-1911.json'
                    && in_array($source['original'] ?? null, ['originals/'.$id.'.xls', 'originals/'.$id.'.xlsx'], true)
                    && ($source['landing_url'] ?? null) === 'https://censusindia.gov.in/nada/index.php/catalog/'.$id
                    && str_starts_with($source['source_url'] ?? '', $source['landing_url'].'/download/'), 422, 'Historical partition identity mismatch.');
                $seen[$key] = true;
                abort_unless(isset($manifest['files'][$source['original']], $manifest['files'][$source['extracted']])
                    && hash_equals($manifest['files'][$source['original']], $source['original_sha256']), 422, 'Original not registered.');
                $data = json_decode($this->member($zip, $source['extracted'], 20000000), true, 512, JSON_THROW_ON_ERROR);
                abort_unless(($data['source']['sha256'] ?? null) === $source['original_sha256']
                    && ($data['source']['url'] ?? null) === $source['source_url']
                    && is_string($data['source']['retrieved_at'] ?? null)
                    && strtotime($data['source']['retrieved_at']) !== false
                    && isset($data['raw_rows'], $data['notes'])
                    && is_string($data['boundary_basis'] ?? null) && trim($data['boundary_basis']) !== '', 422, 'Historical extraction provenance mismatch.');
                $rows = array_values(array_filter($data['records'], fn ($row) => ($row['year'] ?? null) === $year));
                abort_unless(count($rows) > 0 && count($rows) === $source['row_count'], 422, 'Historical row count mismatch.');
                $identities = [];
                foreach ($rows as $row) {
                    $identity = ($row['state_code'] ?? '').'-'.($row['district_code'] ?? '');
                    abort_unless(preg_match('/^\d{2}-\d{3}$/', $identity) && ! isset($identities[$identity])
                        && is_string($row['name']) && trim($row['name']) !== ''
                        && is_int($row['source_row']) && $row['source_row'] >= 5 && is_array($row['flags']), 422, 'Historical row identity mismatch.');
                    $identities[$identity] = true;
                    foreach (['persons', 'males', 'females'] as $field) {
                        abort_unless(array_key_exists($field, $row) && ($row[$field] === null || (is_int($row[$field]) && $row[$field] >= 0)), 422, 'Historical count is invalid.');
                    }
                }
                $partitions[] = ['source' => $source, 'rows' => $rows, 'evidence' => $data];
            }
            foreach ($partitions as $partition) {
                $id = $partition['source']['catalogue'];
                abort_unless(isset($seen['census-a02-'.$id.'-1901'], $seen['census-a02-'.$id.'-1911']),
                    422, 'Historical source must include both requested year partitions.');
            }

            return $partitions;
        } finally {
            $zip->close();
        }
    }

    private function member(ZipArchive $zip, string $name, int $limit): string
    {
        $stat = $zip->statName($name);
        abort_unless($stat && $stat['size'] <= $limit, 422, 'Missing or oversized historical member.');
        $raw = $zip->getFromName($name);
        abort_unless(is_string($raw), 422, 'Unreadable historical member.');

        return $raw;
    }
}
