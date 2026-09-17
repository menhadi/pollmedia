<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CensusPublication;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CensusPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function prepareRun(): array
    {
        $this->seed(PilibhitSeeder::class);
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        Storage::fake('local');
        Storage::disk('local')->put('source.xlsx', 'archived-test-workbook');
        $base = app(CensusPublication::class)->current();
        $payload = json_decode($base->payload, true);
        $rows = [];
        foreach ($payload['villages'] as $village) {
            $rows[] = ['Town/Village' => $village['code'], 'Name' => $village['name'], 'State' => '09', 'District' => '151', 'Subdistt' => $village['subdistrict_code'], 'Level' => 'VILLAGE', 'TRU' => 'Rural', 'TOT_P' => (string) $village['population'], 'TOT_M' => (string) $village['population'], 'TOT_F' => '0', 'No_HH' => (string) $village['households'], 'P_LIT' => (string) $village['literate'], 'P_06' => (string) $village['population_0_6']];
        }
        $connector = DB::table('import_connectors')->insertGetId(['name' => 'Census 2011', 'url' => $payload['source_url'], 'format' => 'xlsx', 'record_key' => 'Town/Village', 'options' => json_encode(['sheet' => $payload['sheet']]), 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('import_runs')->insertGetId(['import_connector_id' => $connector, 'status' => 'accepted', 'origin' => 'upload', 'source_url' => $payload['source_url'], 'sha256' => hash('sha256', 'archived-test-workbook'), 'raw_path' => 'source.xlsx', 'extracted' => json_encode(['rows' => $rows]), 'created_at' => now()]);

        return [$run, $base, $rows];
    }

    public function test_preview_publish_and_rollback_preserve_editions_and_update_public_pages(): void
    {
        [$run, $base, $rows] = $this->prepareRun();
        $historical = DB::table('source_releases')->where('data_source_id', DB::table('data_sources')->where('key', 'census-pilibhit-villages-2001')->value('id'))->first();
        $rows[0]['P_LIT'] = '1379';
        DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode(['rows' => $rows])]);
        $url = '/admin/imports/runs/'.$run.'/census';
        $this->get($url)->assertOk()->assertSee('1 changed values')->assertSee('1,380')->assertSee('1,379');
        $this->post($url, ['base_release_id' => $base->id])->assertRedirect();
        $published = app(CensusPublication::class)->current();
        $this->assertSame(1379, json_decode($published->payload, true)['villages'][0]['literate']);
        $this->assertSame($base->payload, DB::table('source_releases')->where('id', $base->id)->value('payload'));
        $this->assertSame($historical->payload, DB::table('source_releases')->where('id', $historical->id)->value('payload'));
        $this->get('/india/village/131191-bagnera-bagneri?year=2011')->assertOk()->assertSee('1,379');
        $this->post($url, ['base_release_id' => $base->id])->assertStatus(409);
        $this->post($url, ['base_release_id' => $published->id])->assertStatus(422);
        $entry = DB::table('import_publications')->first();
        $restore = '/admin/imports/publications/'.$entry->id.'/restore';
        $this->post($restore, ['base_release_id' => $base->id])->assertStatus(409);
        $this->post($restore, ['base_release_id' => $published->id])->assertRedirect();
        $this->assertSame($base->payload, app(CensusPublication::class)->current()->payload);
        $this->get('/india/village/131191-bagnera-bagneri?year=2011')->assertOk()->assertSee('1,380');
        $this->assertDatabaseCount('import_publications', 2);
        $this->post($restore, ['base_release_id' => app(CensusPublication::class)->current()->id])->assertStatus(409);
    }

    public function test_invalid_scope_coverage_totals_archive_and_unreviewed_runs_are_blocked(): void
    {
        [$run, $base, $rows] = $this->prepareRun();
        $url = '/admin/imports/runs/'.$run.'/census';
        $this->get($url)->assertOk()->assertSee('No publication is needed');
        $originalCount = DB::table('source_releases')->count();
        $cases = [];
        $changed = $rows;
        $changed[0]['TOT_M'] = '1';
        $cases[] = $changed;
        $changed = $rows;
        $changed[0]['District'] = '999';
        $cases[] = $changed;
        $changed = $rows;
        $changed[0]['Name'] = 'Different name';
        $cases[] = $changed;
        $changed = $rows;
        $changed[0]['P_LIT'] = '';
        $cases[] = $changed;
        $cases[] = array_slice($rows, 1);
        $cases[] = array_merge($rows, [$rows[0]]);
        foreach ($cases as $invalid) {
            DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode(['rows' => $invalid])]);
            $this->get($url)->assertOk()->assertSee('Publication blocked');
            $this->post($url, ['base_release_id' => $base->id])->assertStatus(422);
        }
        $rows[0]['P_LIT'] = '1379';
        DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode(['rows' => $rows]), 'status' => 'needs_review']);
        $this->post($url, ['base_release_id' => $base->id])->assertStatus(422);
        DB::table('import_runs')->where('id', $run)->update(['status' => 'accepted', 'source_url' => 'https://censusindia.gov.in/wrong-year.xlsx']);
        $this->post($url, ['base_release_id' => $base->id])->assertStatus(422);
        DB::table('import_runs')->where('id', $run)->update(['source_url' => json_decode($base->payload, true)['source_url']]);
        Storage::disk('local')->put('source.xlsx', 'tampered');
        $this->post($url, ['base_release_id' => $base->id])->assertStatus(422);
        $this->assertDatabaseCount('source_releases', $originalCount);
        $this->assertDatabaseCount('import_publications', 0);
    }

    public function test_publication_routes_require_administrator(): void
    {
        $this->get('/admin/imports/runs/1/census')->assertRedirect(route('admin.login'));
        $this->post('/admin/imports/runs/1/census')->assertRedirect(route('admin.login'));
        $this->post('/admin/imports/publications/1/restore')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create());
        $this->post('/admin/imports/runs/1/census')->assertForbidden();
        $this->post('/admin/imports/publications/1/restore')->assertForbidden();
    }
}
