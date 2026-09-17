<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionBatch;
use App\Services\StateElectionPublication;
use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Database\Seeders\UttarPradeshGeographySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UttarPradeshGeographyTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_geography_is_complete_repeatable_and_reciprocal_without_fabricated_results(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class, PilibhitAssemblySeeder::class]);
        Bus::fake();
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);
        $batch = app(ElectionBatch::class);
        $id = $batch->create($admin->id);
        $batch->process($id);
        app(StateElectionPublication::class)->publish($id, $admin->id);
        $results = DB::table('election_contests')->get()->toJson();
        $offices = DB::table('office_assignments')->get()->toJson();
        $this->seed(UttarPradeshGeographySeeder::class);
        $this->assertSame(80, DB::table('places')->where('type', 'pc')->count());
        $this->assertSame(75, DB::table('places')->where('type', 'district')->count());
        $this->assertDatabaseCount('places', 558);
        $count = DB::table('place_relationships')->count();
        $this->seed(UttarPradeshGeographySeeder::class);
        $this->assertDatabaseCount('place_relationships', $count);
        $this->assertSame($results, DB::table('election_contests')->get()->toJson());
        $this->assertSame($offices, DB::table('office_assignments')->get()->toJson());
        foreach (['assembly_segment_of', 'district_directory_lists'] as $type) {
            $groups = DB::table('place_relationships')->where('type', $type)->whereNull('valid_to')->get()->groupBy('from_place_id');
            $this->assertCount(403, $groups);
            foreach ($groups as $rows) {
                $this->assertCount(1, $rows->pluck('to_place_id')->unique());
            }
        }
        $this->get('/india/ac/uttar-pradesh-1-behat')->assertOk()->assertSee('/india/pc/uttar-pradesh-1-saharanpur')->assertSee('/india/district/uttar-pradesh-saharanpur')->assertSee('2006-12-18')->assertSee('2023-10-06')->assertSee('PDF page 12');
        $this->get('/india/pc/uttar-pradesh-1-saharanpur')->assertOk()->assertSee('/india/ac/uttar-pradesh-1-behat')->assertDontSee('Pilibhit PC and Pilibhit district')->assertSee('Election results have not been imported for this constituency');
        $this->get('/india/district/uttar-pradesh-sambhal')->assertOk()->assertSee('/india/ac/uttar-pradesh-111-gunnaur')->assertSee('/india/ac/uttar-pradesh-33-sambhal');
        $this->get('/india/ac/baheri')->assertOk()->assertSee('/india/pc/pilibhit')->assertSee('/india/district/bareilly');
        $this->get('/india/state/uttar-pradesh?type=pc')->assertOk()->assertSee('80 available pages');
        $this->get('/india/state/uttar-pradesh?type=district')->assertOk()->assertSee('75 available pages');
        $this->get('/sitemap.xml')->assertOk()->assertSee('/india/pc/uttar-pradesh-1-saharanpur')->assertSee('/india/district/uttar-pradesh-sambhal');
    }

    public function test_fixture_reproduces_official_pdf_tables_and_requires_published_ac_identities(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'up-geography-');
        try {
            $process = new Process([config('imports.python'), base_path('../pilot/extract_up_geography.py'), base_path('../pilot/raw'), $path]);
            $process->setTimeout(60);
            $process->mustRun();
            $extracted = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $fixture = json_decode(file_get_contents(database_path('fixtures/up-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach ($extracted as $key => $value) {
                $this->assertSame($value, $fixture[$key]);
            }
        } finally {
            unlink($path);
        }
        try {
            $this->seed(UttarPradeshGeographySeeder::class);
            $this->fail('Unmapped AC codes must not create relationships.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('places', 0);
        $this->assertDatabaseCount('place_relationships', 0);
    }
}
