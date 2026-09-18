<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class CensusPackageTest extends TestCase
{
    use RefreshDatabase;

    private function package(bool $badHash = false, bool $badRow = false): array
    {
        Storage::fake('local');
        $path = Storage::disk('local')->path('transfer.zip');
        $key = 'india-pca-2011';
        $headers = ['State', 'District', 'Subdistt', 'Town/Village', 'Ward', 'EB', 'Level', 'Name', 'TRU', 'TOT_P', 'TOT_M', 'TOT_F', 'No_HH'];
        $row = array_combine($headers, ['09', '151', '00000', '000000', '0000', '000000', 'STATE', 'Example area', 'Total', '120', '50', '50', '0']);
        if ($badRow) {
            $row['TOT_P'] = 'invalid';
        }
        $data = json_encode(['headers' => $headers, 'rows' => [$row], 'scope' => 'Source scope']);
        $raw = 'preserved original';
        $manifest = ['version' => 1, 'sources' => [[
            'key' => $key, 'source_url' => config('census-sources.'.$key.'.url'),
            'sha256' => hash('sha256', $raw), 'extracted_sha256' => hash('sha256', $badHash ? 'wrong' : $data),
            'options' => [], 'retrieved_at' => '2026-09-18 00:00:00', 'row_count' => 1,
        ]]];
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->addFromString($key.'.xlsx', $raw);
        $zip->addFromString($key.'.json', $data);
        $zip->close();

        return ['package' => $path, '--sha256' => hash_file('sha256', $path)];
    }

    public function test_import_publishes_notes_and_is_repeatable_without_database_id_transfer(): void
    {
        $args = $this->package();
        $this->artisan('census:import-package', $args + ['--check' => true])->assertSuccessful();
        $this->assertDatabaseCount('census_editions', 0);
        $this->artisan('census:import-package', $args + ['--publish' => true])->assertSuccessful();
        $this->artisan('census:import-package', $args + ['--publish' => true])->assertSuccessful();
        $this->assertDatabaseCount('census_editions', 1);
        $this->assertDatabaseCount('import_runs', 1);
        $this->assertDatabaseCount('census_catalogue_reviews', 1);
        $this->assertDatabaseHas('census_editions', ['status' => 'published', 'flag_count' => 1]);
        $this->assertDatabaseHas('census_catalogue_reviews', ['user_id' => null, 'action' => 'publish_with_notes_cli']);
        $this->get('/india/census')->assertOk()->assertSee('†')->assertSee('Example area');
        $this->assertSame('preserved original', Storage::disk('local')->get(DB::table('import_runs')->value('raw_path')));
    }

    public function test_corrupt_extraction_and_invalid_rows_do_not_import_any_database_records(): void
    {
        $this->artisan('census:import-package', $this->package(badHash: true))->assertFailed();
        $this->assertDatabaseCount('import_runs', 0);
        $this->artisan('census:import-package', $this->package(badRow: true) + ['--publish' => true])->assertFailed();
        $this->assertDatabaseCount('import_runs', 0);
        $this->assertDatabaseCount('import_connectors', 0);
        $this->assertDatabaseCount('census_publications', 0);
    }

    public function test_wrong_package_checksum_is_rejected(): void
    {
        $args = $this->package();
        $args['--sha256'] = str_repeat('0', 64);
        $this->artisan('census:import-package', $args)->assertFailed();
        $this->assertDatabaseCount('import_runs', 0);
    }
}
