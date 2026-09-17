<?php

namespace Tests\Feature;

use App\Services\OfficialDownload;
use App\Services\OfficialImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CensusCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_pdf_is_archived_with_provenance_without_publishing(): void
    {
        Storage::fake('local');
        config(['census-sources' => ['history' => ['name' => 'Decadal history', 'url' => 'https://censusindia.gov.in/history.pdf', 'format' => 'pdf', 'archive_only' => true, 'boundary_basis' => '2011 jurisdictions']]]);
        $this->mock(OfficialDownload::class, fn ($mock) => $mock->shouldReceive('get')->once()->andReturn('%PDF-1.7 history'));
        $this->mock(OfficialImport::class, fn ($mock) => $mock->shouldNotReceive('run'));
        $this->artisan('imports:collect-census --source=history')->assertSuccessful();
        $manifest = json_decode(Storage::disk('local')->get('census-archive/history/manifest.json'), true);
        $this->assertSame('archived_requires_mapping', $manifest['status']);
        $this->assertSame('2011 jurisdictions', $manifest['boundary_basis']);
        $this->assertSame(hash('sha256', '%PDF-1.7 history'), $manifest['sha256']);
        $this->artisan('imports:collect-census --source=history --saved')->assertSuccessful();
        $this->artisan('imports:collect-census --source=unknown')->assertFailed();
        $this->assertDatabaseCount('source_releases', 0);
    }

    public function test_saved_collection_rejects_a_changed_archive_without_network_access(): void
    {
        Storage::fake('local');
        config(['census-sources' => ['test' => ['name' => 'Archived test', 'url' => 'https://censusindia.gov.in/test.xlsx', 'format' => 'xlsx']]]);
        $id = DB::table('import_connectors')->insertGetId(['name' => 'Archived test', 'url' => 'https://censusindia.gov.in/test.xlsx', 'format' => 'xlsx', 'record_key' => 'source_record_key', 'options' => '{}']);
        Storage::disk('local')->put('official-imports/test.xlsx', 'changed bytes');
        DB::table('import_runs')->insert(['import_connector_id' => $id, 'origin' => 'url', 'source_url' => 'https://censusindia.gov.in/test.xlsx', 'status' => 'failed', 'raw_path' => 'official-imports/test.xlsx', 'sha256' => hash('sha256', 'original bytes'), 'created_at' => now()]);
        $this->mock(OfficialImport::class, fn ($mock) => $mock->shouldNotReceive('run'));
        $this->artisan('imports:collect-census --saved')->expectsOutputToContain('No intact archived file')->assertFailed();
    }

    public function test_collection_registers_configured_sources_once_and_only_stages_data(): void
    {
        config(['census-sources' => ['test' => ['name' => 'National test', 'url' => 'https://censusindia.gov.in/test.xls', 'format' => 'xls', 'key_columns' => ['STATE', 'TRU']]]]);
        $this->mock(OfficialImport::class, function ($mock): void {
            $mock->shouldReceive('run')->twice()->andReturnUsing(function ($id): int {
                return DB::table('import_runs')->insertGetId(['import_connector_id' => $id, 'origin' => 'url', 'source_url' => 'https://censusindia.gov.in/test.xls', 'status' => 'needs_review', 'created_at' => now()]);
            });
        });
        $this->artisan('imports:collect-census')->assertSuccessful();
        $this->artisan('imports:collect-census')->assertSuccessful();
        $this->assertDatabaseCount('import_connectors', 1);
        $this->assertDatabaseCount('import_runs', 2);
        $this->assertDatabaseCount('source_releases', 0);
        $connector = DB::table('import_connectors')->first();
        $this->assertNull($connector->accepted_run_id);
        $this->assertSame(['STATE', 'TRU'], json_decode($connector->options, true)['key_columns']);
    }

    public function test_a_failed_source_does_not_stop_the_remaining_sources(): void
    {
        config(['census-sources' => [
            'first' => ['name' => 'First', 'url' => 'https://censusindia.gov.in/one.xlsx', 'format' => 'xlsx'],
            'second' => ['name' => 'Second', 'url' => 'https://censusindia.gov.in/two.xlsx', 'format' => 'xlsx'],
        ]]);
        $this->mock(OfficialImport::class, function ($mock): void {
            $mock->shouldReceive('run')->twice()->andReturnUsing(function ($id): int {
                if ($id === 1) {
                    throw new \RuntimeException('Source unavailable');
                }

                return DB::table('import_runs')->insertGetId(['import_connector_id' => $id, 'origin' => 'url', 'source_url' => 'https://censusindia.gov.in/two.xlsx', 'status' => 'needs_review', 'created_at' => now()]);
            });
        });

        $this->artisan('imports:collect-census')->assertFailed();
        $this->assertDatabaseCount('import_connectors', 2);
        $this->assertDatabaseCount('import_runs', 1);
    }
}
