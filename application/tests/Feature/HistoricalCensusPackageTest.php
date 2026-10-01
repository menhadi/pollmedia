<?php

namespace Tests\Feature;

use App\Services\HistoricalCensusPackage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class HistoricalCensusPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_1951_package_keeps_persons_and_sex_counts(): void
    {
        $path = base_path('../exports/pollmedia-historical-census-1951-20261001.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Prepared 1951 package is unavailable.');
        }
        $service = new HistoricalCensusPackage;
        $partitions = $service->verify($path, hash_file('sha256', $path));
        $coverage = $service->coverage($partitions);
        $this->assertSame(32, $coverage['partitions']);
        $this->assertSame([1951 => 694], $coverage['source_rows_by_year']);
        $values = json_decode($service->catalogueRows($partitions[0], 1)[0]['values'], true);
        $this->assertSame(['TOT_P', 'TOT_M', 'TOT_F'], array_keys($values));
    }

    public function test_1941_package_contains_only_printed_1941_records(): void
    {
        $path = base_path('../exports/pollmedia-historical-census-1941-20261001.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Prepared 1941 package is unavailable.');
        }
        $service = new HistoricalCensusPackage;
        $coverage = $service->coverage($service->verify($path, hash_file('sha256', $path)));
        $this->assertSame(33, $coverage['partitions']);
        $this->assertSame([1941 => 700], $coverage['source_rows_by_year']);
    }

    public function test_1931_package_retains_printed_year(): void
    {
        $path = base_path('../exports/pollmedia-historical-census-1931-20261001.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Prepared 1931 package is unavailable.');
        }
        $service = new HistoricalCensusPackage;
        $coverage = $service->coverage($service->verify($path, hash_file('sha256', $path)));
        $this->assertSame(36, $coverage['partitions']);
        $this->assertSame([1931 => $coverage['source_rows']], $coverage['source_rows_by_year']);
        $this->assertGreaterThan(0, $coverage['source_rows']);
    }

    public function test_1921_package_preserves_year_and_source_partitions(): void
    {
        $path = base_path('../exports/pollmedia-historical-census-1921-20261001.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Prepared 1921 package is unavailable.');
        }
        $service = new HistoricalCensusPackage;
        $partitions = $service->verify($path, hash_file('sha256', $path));
        $coverage = $service->coverage($partitions);
        $this->assertSame(36, $coverage['partitions']);
        $this->assertSame(711, $coverage['source_rows']);
        $this->assertSame([1921 => 711], $coverage['source_rows_by_year']);
        $this->assertSame([1921], array_values(array_unique(array_column(array_column($partitions, 'source'), 'year'))));
    }

    public function test_run_creation_retries_unchanged_and_preserves_prior_extraction_on_revision(): void
    {
        $archive = tempnam(sys_get_temp_dir(), 'historical-run-');
        $partition = ['source' => ['key' => 'census-a02-43334-1901',
            'source_url' => 'https://censusindia.gov.in/original.xls', 'original_sha256' => str_repeat('a', 64)],
            'evidence' => ['records' => [['persons' => 10]]], 'rows' => [['persons' => 10]]];
        $service = new HistoricalCensusPackage;
        try {
            $id = $service->createRun($partition, $archive);
            $this->assertSame($id, $service->createRun($partition, $archive));
            $this->assertDatabaseCount('import_connectors', 1);
            $this->assertDatabaseCount('import_runs', 1);
            $partition['evidence']['records'][0]['persons'] = 11;
            $revision = $service->createRun($partition, $archive);
            $this->assertNotSame($id, $revision);
            $this->assertDatabaseHas('import_runs', ['id' => $revision, 'base_run_id' => $id]);
            $this->assertSame(10, json_decode(DB::table('import_runs')->where('id', $id)->value('extracted'), true)['records'][0]['persons']);
            $this->assertDatabaseCount('import_runs', 2);
        } finally {
            unlink($archive);
        }
    }

    public function test_prepared_package_archive_retry_preserves_bytes_and_rejects_corruption(): void
    {
        $package = base_path('../exports/pollmedia-historical-census-1901-1911-20260930.zip');
        if (! is_file($package)) {
            $this->markTestSkipped('Prepared source package is unavailable in this checkout.');
        }
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'historical-archive-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $sha = hash_file('sha256', $package);
        $service = new HistoricalCensusPackage;
        $target = $directory.DIRECTORY_SEPARATOR.$sha.'.zip';
        try {
            $this->assertSame($target, $service->archivePackage($package, $sha, $directory));
            $this->assertSame($sha, hash_file('sha256', $target));
            $this->assertSame($target, $service->archivePackage($package, $sha, $directory));
            $result = $service->importDraftPackage($package, $sha, $directory);
            $this->assertSame(1400, $result['added_rows']);
            $this->assertSame(66, $result['added_editions']);
            $this->assertFalse($result['published']);
            $this->assertSame($result['baseline_sha256'], hash_file('sha256', $result['baseline_backup']));
            $this->assertTrue($result['committed']);
            $this->assertSame($result['receipt_sha256'], hash_file('sha256', $result['receipt']));
            $this->assertSame($result['edition_ids'], json_decode(file_get_contents($result['receipt']), true)['edition_ids']);
            $this->assertSame([], json_decode(file_get_contents($result['baseline_backup']), true)['editions']);
            $this->assertDatabaseCount('census_editions', 66);
            $this->assertDatabaseCount('census_catalogue_rows', 1400);
            $retry = $service->importDraftPackage($package, $sha, $directory);
            $this->assertSame(0, $retry['added_rows']);
            $this->assertSame(66, $retry['unchanged_partitions']);
            $this->assertDatabaseCount('import_runs', 66);
            $this->assertDatabaseCount('census_catalogue_rows', 1400);
            $published = $service->importDraftPackage($package, $sha, $directory, true);
            $this->assertTrue($published['published']);
            $this->assertSame(0, $published['added_rows']);
            $this->assertSame(66, DB::table('census_editions')->where('status', 'published')->count());
            $this->assertDatabaseCount('census_catalogue_reviews', 66);
            $this->get(route('census-catalogue.index', ['edition' => $published['edition_ids'][0]]))
                ->assertOk()->assertSee('1901')->assertSee('retrospective 2011 boundaries')
                ->assertSee('Official source workbook')->assertSee('†');
            $this->get(route('civic.index', ['year' => 1911]))->assertOk()->assertSee('1911');
            $state = $this->get(route('civic.index', ['year' => 1901]))->assertOk()
                ->assertSee('state-navigation')->viewData('stateOptions')->firstWhere('state_code', '28');
            $this->assertNotNull($state);
            $this->get(route('civic.place', ['record' => $state->id]))->assertOk()->assertSee('district-navigation')
                ->assertViewHas('districtOptions', fn ($rows) => $rows->count() === 23)
                ->assertSee('retrospective 2011 boundaries');
            $this->get(route('civic.index', ['year' => 2011, 'edition' => $published['edition_ids'][0]]))
                ->assertOk()->assertSee('Census · 1901')->assertSee('1911');
            $service->importDraftPackage($package, $sha, $directory, true);
            $this->assertDatabaseCount('census_catalogue_reviews', 66);
            DB::table('census_publications')->where('source_key', 'census-a02-43333-1901')->update(['edition_id' => $published['edition_ids'][1]]);
            try {
                $service->importDraftPackage($package, $sha, $directory, true);
                $this->fail('Conflicting publication must be preserved.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertSame($published['edition_ids'][1], DB::table('census_publications')->where('source_key', 'census-a02-43333-1901')->value('edition_id'));
            file_put_contents($target, 'corrupted archived bytes');
            try {
                $service->archivePackage($package, $sha, $directory);
                $this->fail('Corrupt archive must not be overwritten.');
            } catch (HttpException $error) {
                $this->assertSame('Existing historical archive checksum mismatch.', $error->getMessage());
            }
            $this->assertSame('corrupted archived bytes', file_get_contents($target));
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
            foreach (array_merge(glob($directory.DIRECTORY_SEPARATOR.'*-before-*.json'), glob($directory.DIRECTORY_SEPARATOR.'*-receipt-*.json')) as $backup) {
                unlink($backup);
            }
            rmdir($directory);
        }
    }

    public function test_verification_command_rejects_missing_package_without_database_writes(): void
    {
        $this->artisan('census:verify-historical', ['package' => '/missing-historical-package.zip',
            'sha256' => str_repeat('0', 64)])
            ->expectsOutput('Historical package checksum mismatch.')
            ->assertExitCode(1);
        $this->assertDatabaseCount('import_runs', 0);
        $this->assertDatabaseCount('census_editions', 0);
    }

    public function test_transaction_creates_one_draft_and_repeated_run_adds_nothing(): void
    {
        $source = ['key' => 'census-a02-43334-1901', 'year' => 1901,
            'original_sha256' => str_repeat('a', 64), 'source_url' => 'https://censusindia.gov.in/original.xls',
            'landing_url' => 'https://censusindia.gov.in/nada/index.php/catalog/43334'];
        $evidence = ['source' => ['name' => 'Official A02', 'retrieved_at' => '2026-09-30T17:25:35+00:00'], 'boundary_basis' => '2011 jurisdictions'];
        $connector = DB::table('import_connectors')->insertGetId(['name' => 'Historical test',
            'url' => $source['source_url'], 'format' => 'json', 'record_key' => 'identity',
            'options' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('import_runs')->insertGetId(['import_connector_id' => $connector,
            'status' => 'needs_review', 'origin' => 'upload', 'source_url' => $source['source_url'],
            'sha256' => $source['original_sha256'], 'extracted' => json_encode($evidence), 'created_at' => now()]);
        $partition = ['source' => $source, 'evidence' => $evidence,
            'rows' => [['state_code' => '01', 'district_code' => '000', 'year' => 1901,
                'name' => 'Printed state', 'persons' => 10, 'males' => null, 'females' => null,
                'flags' => ['Missing components'], 'source_row' => 6]]];
        $service = new HistoricalCensusPackage;
        $partition['evidence']['records'] = $partition['rows'];
        DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode($partition['evidence'])]);
        $altered = $partition;
        $altered['rows'][0]['persons'] = 999;
        try {
            $service->prepareEdition($run, $altered);
            $this->fail('Altered source counts must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame('Historical rows differ from preserved extraction evidence.', $error->getMessage());
        }
        $this->assertDatabaseCount('census_editions', 0);
        $this->assertDatabaseCount('census_catalogue_rows', 0);
        $edition = $service->prepareEdition($run, $partition);
        $this->assertSame($edition, $service->prepareEdition($run, $partition));
        $this->assertDatabaseCount('census_editions', 1);
        $this->assertDatabaseCount('census_catalogue_rows', 1);
        $this->assertDatabaseHas('census_editions', ['id' => $edition, 'status' => 'draft', 'row_count' => 1]);
        $partition['rows'][] = $partition['rows'][0];
        $partition['source']['key'] = 'census-a02-43334-1911';
        $partition['source']['year'] = 1911;
        try {
            $service->prepareEdition($run, $partition);
            $this->fail('A run must not be reused for a different partition.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertDatabaseCount('census_editions', 1);
        $this->assertDatabaseCount('census_catalogue_rows', 1);
        DB::table('census_catalogue_rows')->where('edition_id', $edition)->delete();
        $partition['source'] = $source;
        $partition['rows'] = $partition['evidence']['records'];
        try {
            $service->prepareEdition($run, $partition);
            $this->fail('Incomplete existing editions must not count as unchanged.');
        } catch (HttpException $error) {
            $this->assertSame('Existing historical edition row count mismatch.', $error->getMessage());
        }
    }

    public function test_duplicate_insert_rolls_back_edition_and_rows(): void
    {
        $source = ['key' => 'census-a02-43334-1901', 'year' => 1901,
            'original_sha256' => str_repeat('a', 64), 'source_url' => 'https://censusindia.gov.in/original.xls',
            'landing_url' => 'https://censusindia.gov.in/nada/index.php/catalog/43334'];
        $evidence = ['source' => ['name' => 'Official A02', 'retrieved_at' => '2026-09-30T17:25:35+00:00'], 'boundary_basis' => '2011 jurisdictions'];
        $connector = DB::table('import_connectors')->insertGetId(['name' => 'Historical test',
            'url' => $source['source_url'], 'format' => 'json', 'record_key' => 'identity',
            'options' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('import_runs')->insertGetId(['import_connector_id' => $connector,
            'status' => 'needs_review', 'origin' => 'upload', 'source_url' => $source['source_url'],
            'sha256' => $source['original_sha256'], 'extracted' => json_encode($evidence), 'created_at' => now()]);
        $row = ['state_code' => '01', 'district_code' => '000', 'year' => 1901,
            'name' => 'Printed state', 'persons' => 10, 'males' => null, 'females' => null,
            'flags' => [], 'source_row' => 6];
        $evidence['records'] = [$row, $row];
        DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode($evidence)]);
        try {
            (new HistoricalCensusPackage)->prepareEdition($run, ['source' => $source,
                'evidence' => $evidence, 'rows' => [$row, $row]]);
            $this->fail('Duplicate insert must fail.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('UNIQUE', $error->getMessage());
        }
        $this->assertDatabaseCount('census_editions', 0);
        $this->assertDatabaseCount('census_catalogue_rows', 0);
        $this->assertDatabaseCount('import_runs', 1);
    }

    public function test_coverage_distinguishes_source_overlap_from_geography_count(): void
    {
        $row = ['state_code' => '23', 'district_code' => '000'];
        $coverage = (new HistoricalCensusPackage)->coverage([
            ['source' => ['year' => 1901], 'rows' => [$row]],
            ['source' => ['year' => 1901], 'rows' => [$row]],
            ['source' => ['year' => 1911], 'rows' => [$row]],
        ]);
        $this->assertSame(3, $coverage['source_rows']);
        $this->assertSame(2, $coverage['unique_geography_years']);
        $this->assertSame(1, $coverage['overlapping_source_rows']);
        $this->assertSame([1901 => 2, 1911 => 1], $coverage['source_rows_by_year']);
    }

    public function test_mapping_keeps_missing_counts_and_source_boundaries(): void
    {
        $partition = ['source' => ['key' => 'census-a02-43356-1901'],
            'evidence' => ['boundary_basis' => '2011 boundaries'],
            'rows' => [['state_code' => '23', 'district_code' => '000', 'name' => 'MADHYA PRADESH',
                'year' => 1901, 'persons' => 12679214, 'males' => null, 'females' => null,
                'flags' => ['Source components missing'], 'source_row' => 6]]];
        $mapped = (new HistoricalCensusPackage)->catalogueRows($partition, 7)[0];
        $this->assertSame('STATE', $mapped['level']);
        $this->assertNull(json_decode($mapped['values'], true)['TOT_M']);
        $this->assertSame('2011 boundaries', json_decode($mapped['geography'], true)['boundary_basis']);
        $this->assertSame(6, $mapped['source_row']);
        $this->assertContains('Source components missing', json_decode($mapped['flags'], true));
        $this->assertContains('Historical population is reported on retrospective 2011 boundaries; see the official source and its footnotes.',
            json_decode($mapped['flags'], true));
        $partition['source']['key'] = 'census-a02-43333-1901';
        $nationalSource = (new HistoricalCensusPackage)->catalogueRows($partition, 8)[0];
        $this->assertNotSame($mapped['record_key'], $nationalSource['record_key']);
        $this->assertSame($mapped['values'], $nationalSource['values']);
    }

    public function test_duplicate_geography_is_rejected_even_with_valid_checksums(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'historical-test-');
        $original = 'preserved original';
        $url = 'https://censusindia.gov.in/nada/index.php/catalog/43334/download/1/original.xls';
        $row = ['state_code' => '01', 'district_code' => '000', 'year' => 1901,
            'name' => 'Printed state', 'source_row' => 6, 'flags' => [],
            'persons' => 10, 'males' => 5, 'females' => 5];
        $data = json_encode(['source' => ['sha256' => hash('sha256', $original), 'url' => $url, 'retrieved_at' => '2026-09-30T17:25:35+00:00'],
            'raw_rows' => [], 'notes' => [], 'boundary_basis' => '2011 jurisdictions', 'records' => [$row, $row]], JSON_THROW_ON_ERROR);
        $manifest = ['version' => 1, 'family' => 'historical-census-a02', 'selected_years' => [1901, 1911],
            'files' => ['originals/43334.xls' => hash('sha256', $original),
                'extracted/43334.1901-1911.json' => hash('sha256', $data)],
            'sources' => [['catalogue' => '43334', 'year' => 1901, 'key' => 'census-a02-43334-1901',
                'extracted' => 'extracted/43334.1901-1911.json', 'original' => 'originals/43334.xls',
                'original_sha256' => hash('sha256', $original), 'source_url' => $url,
                'landing_url' => 'https://censusindia.gov.in/nada/index.php/catalog/43334', 'row_count' => 2]]];
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('originals/43334.xls', $original);
        $zip->addFromString('extracted/43334.1901-1911.json', $data);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();
        try {
            $this->expectException(HttpException::class);
            $this->expectExceptionMessage('Historical row identity mismatch.');
            (new HistoricalCensusPackage)->verify($path, hash_file('sha256', $path));
        } finally {
            unlink($path);
        }
    }

    public function test_wrong_checksum_is_rejected_before_opening_archive(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'historical-test-');
        file_put_contents($path, 'original bytes');
        try {
            $this->expectException(HttpException::class);
            $this->expectExceptionMessage('Historical package checksum mismatch.');
            (new HistoricalCensusPackage)->verify($path, str_repeat('0', 64));
        } finally {
            unlink($path);
        }
    }
}
