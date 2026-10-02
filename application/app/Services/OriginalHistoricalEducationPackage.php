<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use ZipArchive;

class OriginalHistoricalEducationPackage extends OriginalHistoricalCensusImport
{
    protected const ReviewedManifestSha256 = '9a6b90c0814b2227144cb44b14dc506c0afba01de3435026caf1e9bd9f0eb351';

    public function verify(string $path, string $expectedSha256): array
    {
        abort_unless(is_file($path) && preg_match('/^[a-f0-9]{64}$/', $expectedSha256)
            && hash_equals($expectedSha256, hash_file('sha256', $path)), 422, 'Original education package checksum mismatch.');
        $zip = new ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'Unreadable original education package.');
        try {
            $manifestRaw = $this->member($zip, 'manifest.json');
            /** This adapter admits only the reviewed batch; a new extraction needs a new reviewed manifest pin. */
            abort_unless(hash_equals(static::ReviewedManifestSha256, hash('sha256', $manifestRaw)),
                422, 'Original education manifest differs from the reviewed source batch.');
            $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                abort_if(isset($names[$name]), 422, 'Duplicate original education member.');
                $names[$name] = true;
            }
            $expected = array_fill_keys(array_merge(['manifest.json'], array_keys($manifest['files'])), true);
            abort_unless(array_diff_key($names, $expected) === [] && array_diff_key($expected, $names) === [],
                422, 'Original education archive inventory differs.');
            foreach ($manifest['files'] as $name => $sha256) {
                abort_unless(hash_equals($sha256, hash('sha256', $this->member($zip, $name))),
                    422, 'Original education member checksum mismatch.');
            }
            $payload = json_decode($this->member($zip, 'education-population.json'), true, 512, JSON_THROW_ON_ERROR);

            return ['manifest' => $manifest, 'rows' => $payload['rows'], 'fields' => $payload['fields'],
                'checks' => $payload['checks'], 'statistics' => $payload['statistics'],
                'notes' => $payload['notes'], 'retrieved_at' => $manifest['retrieved_at']];
        } finally {
            $zip->close();
        }
    }

    public function stageVerifiedEdition(int $runId, array $verified, string $retrievedAt): int
    {
        $manifest = $verified['manifest'];
        $rows = $verified['rows'];

        return DB::transaction(function () use ($runId, $verified, $retrievedAt, $manifest, $rows): int {
            $run = DB::table('import_runs')->where('id', $runId)->lockForUpdate()->first();
            abort_unless($run && in_array($run->status, ['needs_review', 'accepted'], true)
                && $run->sha256 === $manifest['original_sha256'] && $run->source_url === $manifest['source_url']
                && json_decode($run->extracted, true, 512, JSON_THROW_ON_ERROR) === $verified,
                422, 'Original education run differs from verified evidence.');
            $stateIdentity = 'O'.substr(hash('sha256', $manifest['source_key']), 0, 9);
            $expectedRows = [];
            foreach ($rows as $index => $row) {
                $expectedRows[$row['record_key']] = [
                    'record_key' => $row['record_key'], 'state_code' => $stateIdentity, 'district_code' => '000',
                    'level' => $row['original_level'], 'residence' => $row['residence'], 'name' => $this->storedName($row),
                    'geography' => json_encode($this->storedGeography($row, $manifest), JSON_THROW_ON_ERROR),
                    'values' => json_encode($row['values'], JSON_THROW_ON_ERROR),
                    'flags' => json_encode($row['flags'], JSON_THROW_ON_ERROR), 'source_row' => $index + 1,
                ];
            }
            $existing = DB::table('census_editions')->where('import_run_id', $runId)->first();
            if ($existing) {
                abort_unless($existing->source_key === $manifest['source_key'] && $existing->sha256 === $manifest['original_sha256']
                    && $existing->source_url === $manifest['source_url'] && (int) $existing->year === $manifest['year']
                    && (int) $existing->row_count === count($rows)
                    && json_decode($existing->fields, true, 512, JSON_THROW_ON_ERROR) === array_keys($verified['fields'])
                    && $existing->scope === $manifest['boundary_basis'].'; '.$manifest['scope'].'; '.implode(' ', $verified['notes']),
                    422, 'Existing original education edition differs.');
                $stored = DB::table('census_catalogue_rows')->where('edition_id', $existing->id)->get()->keyBy('record_key');
                abort_unless($stored->count() === count($expectedRows), 422, 'Existing original education row count differs.');
                foreach ($expectedRows as $key => $expectedRow) {
                    $saved = $stored->get($key);
                    abort_unless($saved, 422, 'Existing original education row is missing.');
                    foreach ($expectedRow as $column => $value) {
                        $actual = $saved->{$column};
                        if (in_array($column, ['geography', 'values', 'flags'], true)) {
                            $actual = json_decode($actual, true, 512, JSON_THROW_ON_ERROR);
                            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                        } elseif ($column === 'source_row') {
                            $actual = (int) $actual;
                        }
                        abort_unless($actual === $value, 422, 'Existing original education stored evidence differs.');
                    }
                }

                return $existing->id;
            }
            $edition = DB::table('census_editions')->insertGetId([
                'import_run_id' => $runId, 'source_key' => $manifest['source_key'],
                'name' => $this->editionName(),
                'year' => $manifest['year'], 'status' => 'draft', 'sha256' => $manifest['original_sha256'],
                'source_url' => $manifest['source_url'],
                'landing_url' => 'https://censusindia.gov.in/nada/index.php/catalog/'.$manifest['catalogue'],
                'scope' => $manifest['boundary_basis'].'; '.$manifest['scope'].'; '.implode(' ', $verified['notes']),
                'fields' => json_encode(array_keys($verified['fields']), JSON_THROW_ON_ERROR),
                'row_count' => count($rows), 'flag_count' => count(array_filter($rows, fn (array $row): bool => $row['flags'] !== [])),
                'retrieved_at' => $retrievedAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($expectedRows as $row) {
                DB::table('census_catalogue_rows')->insert(['edition_id' => $edition] + $row);
            }

            return $edition;
        });
    }

    protected function editionName(): string
    {
        return 'Original Census 1961 — Tripura educational levels (B-III A/B population)';
    }

    protected function storedName(array $row): string
    {
        return $row['original_name'];
    }

    protected function storedGeography(array $row, array $manifest): array
    {
        return ['original_name' => $row['original_name'],
            'parent_original_name' => $row['parent_original_name'], 'original_table' => $row['table'],
            'source_record_identity' => $row['source_record_identity'], 'year' => $manifest['year'],
            'boundary_basis' => $manifest['boundary_basis'],
            'identifier_basis' => 'Source-scoped storage token; not Census or LGD code'];
    }

    private function member(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        abort_unless($stat && $stat['size'] <= 50000000, 422, 'Missing or oversized original education member.');
        $raw = $zip->getFromName($name);
        abort_unless(is_string($raw), 422, 'Unreadable original education member.');

        return $raw;
    }
}
