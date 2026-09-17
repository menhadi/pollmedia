<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CensusCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CensusCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private function source(bool $urban = false, bool $accepted = true): int
    {
        Storage::fake('local');
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $key = $urban ? 'india-basic-2011-urban' : 'india-pca-2011';
        $url = config('census-sources.'.$key.'.url');
        $connector = DB::table('import_connectors')->insertGetId(['name' => 'Test Census', 'url' => $url, 'format' => 'xlsx', 'record_key' => 'source_record_key', 'options' => json_encode($urban ? ['filter_value' => 'Urban'] : [])]);
        $headers = ['State', 'District', 'Subdistt', 'Town/Village', 'Ward', 'EB', 'Level', 'Name', 'TRU', 'TOT_P', 'TOT_M', 'TOT_F', 'No_HH'];
        $rows = [array_combine($headers, ['09', '151', '00000', '000000', '0000', '000000', $urban ? 'TOWN' : 'STATE', 'Example area', $urban ? 'Urban' : 'Total', '100', '50', '50', '0'])];
        $rows[] = array_replace($rows[0], ['Name' => 'Example area + outgrowths', 'TOT_P' => '150', 'TOT_F' => '100', 'No_HH' => '']);
        Storage::disk('local')->put('test.xlsx', 'archived test source');
        $run = DB::table('import_runs')->insertGetId(['import_connector_id' => $connector, 'source_url' => $url, 'origin' => 'url', 'status' => $accepted ? 'accepted' : 'needs_review', 'raw_path' => 'test.xlsx', 'sha256' => hash('sha256', 'archived test source'), 'extracted' => json_encode(['headers' => $headers, 'rows' => $rows, 'scope' => 'Worksheet Data; Total and other scope recorded']), 'created_at' => now()]);
        if ($accepted) {
            DB::table('import_connectors')->where('id', $connector)->update(['accepted_run_id' => $run]);
        }

        return $run;
    }

    public function test_preview_is_private_and_publication_preserves_units_and_zero_vs_missing(): void
    {
        $run = $this->source(true);
        $service = app(CensusCatalogue::class);
        $edition = $service->prepare($run);
        $this->assertSame($edition, $service->prepare($run));
        $this->assertDatabaseCount('census_catalogue_rows', 2);
        $this->get('/india/census?edition='.$edition)->assertNotFound();
        $this->get('/admin/census?edition='.$edition)->assertOk()->assertSee('Example area + outgrowths');
        $this->post('/admin/census/'.$edition.'/publish', ['current' => 0, 'reviewed' => 1])->assertRedirect();
        $this->get('/india/census?edition='.$edition.'&field=No_HH')->assertOk()->assertSee('Not reported')->assertSee('Example area + outgrowths')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get('/india/census?edition='.$edition.'&state=10')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get('/india/census?edition='.$edition.'&field=not_a_column')->assertStatus(422);
        $this->post('/admin/census/'.$edition.'/withdraw')->assertRedirect();
        $this->get('/india/census?edition='.$edition)->assertNotFound();
        $this->assertDatabaseCount('census_catalogue_reviews', 2);
    }

    public function test_baseline_review_integrity_and_admin_access_are_required(): void
    {
        $run = $this->source(false, false);
        $edition = app(CensusCatalogue::class)->prepare($run);
        $this->post('/admin/census/'.$edition.'/publish', ['current' => 0, 'reviewed' => 1])->assertStatus(422);
        $this->actingAs(User::factory()->create());
        $this->get('/admin/census')->assertForbidden();
        $this->post('/admin/census/'.$edition.'/publish', ['current' => 0, 'reviewed' => 1])->assertForbidden();
        Storage::disk('local')->put('test.xlsx', 'changed file');
        $this->expectException(HttpException::class);
        app(CensusCatalogue::class)->verifyArchive(DB::table('import_runs')->find($run));
    }

    public function test_population_discrepancies_are_visible_as_dagger_notes_and_stale_publication_is_blocked(): void
    {
        $run = $this->source();
        $data = json_decode(DB::table('import_runs')->where('id', $run)->value('extracted'), true);
        $data['rows'][0]['TOT_P'] = '120';
        DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode($data)]);
        $edition = app(CensusCatalogue::class)->prepare($run);
        $this->assertDatabaseHas('census_editions', ['id' => $edition, 'flag_count' => 1]);
        $this->post('/admin/census/'.$edition.'/publish', ['current' => 999, 'reviewed' => 1])->assertStatus(409);
        $this->post('/admin/census/'.$edition.'/publish', ['current' => 0, 'reviewed' => 1])->assertRedirect();
        $this->get('/india/census')->assertOk()->assertSee('†')->assertSee('Population does not equal');
    }

    public function test_replacement_supersedes_the_previous_snapshot_without_duplicate_public_rows(): void
    {
        $run = $this->source();
        $service = app(CensusCatalogue::class);
        $first = $service->prepare($run);
        $service->publish($first, auth()->id(), 0);
        $copy = (array) DB::table('import_runs')->find($run);
        unset($copy['id']);
        $next = DB::table('import_runs')->insertGetId($copy);
        DB::table('import_connectors')->where('id', $copy['import_connector_id'])->update(['accepted_run_id' => $next]);
        $second = $service->prepare($next);
        $this->post('/admin/census/'.$second.'/publish', ['current' => 0, 'reviewed' => 1])->assertStatus(409);
        $this->post('/admin/census/'.$second.'/publish', ['current' => $first, 'reviewed' => 1])->assertRedirect();
        $this->get('/india/census?edition='.$first)->assertNotFound();
        $this->get('/india/census')->assertOk()->assertViewHas('editions', fn ($rows) => $rows->count() === 1);
        $this->assertDatabaseHas('census_editions', ['id' => $first, 'status' => 'superseded']);
        $this->assertDatabaseCount('census_catalogue_rows', 4);
    }

    public function test_duplicate_complete_geography_rolls_back_preparation(): void
    {
        $run = $this->source();
        $data = json_decode(DB::table('import_runs')->where('id', $run)->value('extracted'), true);
        $data['rows'][1] = $data['rows'][0];
        DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode($data)]);
        $this->post('/admin/census/prepare/'.$run)->assertStatus(422);
        $this->assertDatabaseCount('census_editions', 0);
        $this->assertDatabaseCount('census_catalogue_rows', 0);
    }
}
