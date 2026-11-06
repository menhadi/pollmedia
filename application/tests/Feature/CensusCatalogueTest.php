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

    public function test_registered_native_2001_headers_preserve_original_geographic_codes(): void
    {
        $run = $this->source();
        $headers = ['STATE', 'DISTRICT', 'SUB-DISTT', 'TOWN_VILL', 'WARD', 'EB', 'LEVEL', 'NAME', 'TRU'];
        $key = 'india-pca-2011';
        config(['census-sources.'.$key.'.year' => 2001, 'census-sources.'.$key.'.geography_headers' => $headers]);
        $data = ['headers' => [...$headers, 'TOT_P', 'TOT_M', 'TOT_F'], 'rows' => [array_combine([...$headers, 'TOT_P', 'TOT_M', 'TOT_F'], ['33', '29', '0001', '02399700', '0', '0', 'VILLAGE', 'Original village', 'Rural', '100', '50', '50'])], 'scope' => 'Original Sheet1 row 5'];
        DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode($data)]);
        $edition = app(CensusCatalogue::class)->prepare($run);
        $row = DB::table('census_catalogue_rows')->where('edition_id', $edition)->first();
        $geography = json_decode($row->geography, true);
        $this->assertSame('0001', $geography['SUB-DISTT']);
        $this->assertSame('02399700', $geography['TOWN_VILL']);
        $this->assertArrayNotHasKey('TAHSIL', $geography);
        $this->get('/admin/census?edition='.$edition)->assertOk()->assertSee('Original village');
        $this->get('/india/census?edition='.$edition)->assertNotFound();
    }

    private function directorySource(): int
    {
        $run = $this->source();
        $import = DB::table('import_runs')->find($run);
        $key = 'census-2001-village-directory-counts-31-01';
        $identity = ['31', '01', '0001', '00000100'];
        $source = config('census-sources.india-pca-2011');
        $fields = ['P_SCH' => 'Number of Primary School'];
        $package = ['schema' => 'census-vd-explicit-counts-v1', 'year' => 2001, 'source_key' => $key,
            'source_url' => $import->source_url, 'sha256' => $import->sha256, 'landing_url' => $source['landing'],
            'fields' => $fields, 'scope' => 'Census 2001 village counts; boundaries unverified.',
            'rows' => [['identity' => $identity, 'lgd' => '627031', 'name' => 'Bitra', 'source_sheet' => 'Native', 'source_row' => 2,
                'values' => ['P_SCH' => 0], 'value_evidence' => ['P_SCH' => ['official_label' => $fields['P_SCH'], 'source_column' => 19]],
                'record_type_evidence' => ['identity' => $identity, 'status' => 'confirmed_pca_rural_village']]],
        ];
        $encoded = json_encode($package);
        config(['census-sources' => [$key => array_replace($source, ['schema' => $package['schema'], 'year' => 2001,
            'parent_codes' => ['31', '01'], 'count_fields' => $fields, 'review_package_sha256' => hash('sha256', $encoded)])]]);
        DB::table('import_runs')->where('id', $run)->update(['extracted' => $encoded]);

        return $run;
    }

    public function test_verified_directory_package_uses_existing_private_review_and_publication(): void
    {
        $run = $this->directorySource();
        $service = app(CensusCatalogue::class);
        $edition = $service->prepare($run);
        $this->assertSame($edition, $service->prepare($run));
        $this->get('/india/census?edition='.$edition)->assertNotFound();
        $this->get('/admin/census?edition='.$edition)->assertOk()->assertSee('Bitra')->assertSee('Number of Primary School')->assertSee('Column 19');
        $service->publish($edition, auth()->id(), 0);
        $this->get('/india/census?edition='.$edition)->assertOk()->assertViewHas('field', 'P_SCH');
        $this->assertDatabaseCount('census_catalogue_rows', 1);
    }

    public function test_directory_rejects_fractional_counts_and_duplicate_targets(): void
    {
        $sources = config('census-sources');
        foreach (['fraction', 'duplicate', 'parent', 'field', 'type', 'locator', 'lgd'] as $case) {
            config(['census-sources' => $sources]);
            $run = $this->directorySource();
            $data = json_decode(DB::table('import_runs')->where('id', $run)->value('extracted'), true);
            if ($case === 'fraction') {
                $data['rows'][0]['values']['P_SCH'] = 0.2;
            } elseif ($case === 'duplicate') {
                $data['rows'][] = $data['rows'][0];
            } elseif ($case === 'parent') {
                $data['rows'][0]['identity'][1] = '02';
            } elseif ($case === 'field') {
                $data['rows'][0]['values']['UNKNOWN'] = 1;
            } elseif ($case === 'type') {
                $data['rows'][0]['record_type_evidence']['status'] = 'pending';
            } elseif ($case === 'locator') {
                $data['rows'][0]['value_evidence']['P_SCH']['source_column'] = 0;
            } else {
                $data['rows'][] = $data['rows'][0];
                $data['rows'][1]['identity'][3] = '00000200';
            }
            $encoded = json_encode($data);
            config(['census-sources.'.$data['source_key'].'.review_package_sha256' => hash('sha256', $encoded)]);
            DB::table('import_runs')->where('id', $run)->update(['extracted' => $encoded]);
            $this->post('/admin/census/prepare/'.$run)->assertStatus(422);
        }
        $this->assertDatabaseCount('census_editions', 0);
    }

    public function test_directory_publication_rejects_changed_review_package(): void
    {
        $run = $this->directorySource();
        $edition = app(CensusCatalogue::class)->prepare($run);
        DB::table('import_runs')->where('id', $run)->update(['extracted' => '{}']);
        $this->post('/admin/census/'.$edition.'/publish', ['current' => 0, 'reviewed' => 1])->assertStatus(422);
        $this->assertDatabaseHas('census_editions', ['id' => $edition, 'status' => 'draft']);
    }

    public function test_directory_preparation_rejects_unregistered_package_and_changed_archive(): void
    {
        $run = $this->directorySource();
        $encoded = DB::table('import_runs')->where('id', $run)->value('extracted');
        DB::table('import_runs')->where('id', $run)->update(['extracted' => $encoded.' ']);
        $this->post('/admin/census/prepare/'.$run)->assertStatus(422);
        DB::table('import_runs')->where('id', $run)->update(['extracted' => $encoded]);
        Storage::disk('local')->put('test.xlsx', 'tampered original');
        $this->post('/admin/census/prepare/'.$run)->assertStatus(422);
        $this->assertDatabaseCount('census_editions', 0);
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

    public function test_non_population_edition_defaults_to_its_first_available_field(): void
    {
        $run = $this->source();
        $edition = app(CensusCatalogue::class)->prepare($run);
        DB::table('census_editions')->where('id', $edition)->update(['fields' => json_encode(['P_SCH', 'M_SCH'])]);
        DB::table('census_catalogue_rows')->where('edition_id', $edition)->update(['values' => json_encode(['P_SCH' => 0, 'M_SCH' => null])]);

        $this->get('/admin/census?edition='.$edition)->assertOk()->assertViewHas('field', 'P_SCH');
        $this->get('/admin/census?edition='.$edition.'&field=M_SCH')->assertOk()->assertSee('Not reported');
        $this->get('/admin/census?edition='.$edition.'&field=TOT_P')->assertStatus(422);
        $this->get('/india/census?edition='.$edition)->assertNotFound();
        $this->post('/admin/census/'.$edition.'/publish', ['current' => 0, 'reviewed' => 1])->assertRedirect();
        $this->get('/india/census?edition='.$edition)->assertOk()->assertViewHas('field', 'P_SCH');
    }

    public function test_directory_preview_displays_official_labels_and_original_cell_locations(): void
    {
        $run = $this->source();
        $edition = app(CensusCatalogue::class)->prepare($run);
        $identity = ['31', '01', '0001', '00000100'];
        $recordKey = hash('sha256', json_encode($identity));
        DB::table('census_editions')->where('id', $edition)->update(['source_key' => 'census-2001-village-directory-counts-31-01', 'fields' => json_encode(['P_SCH'])]);
        $row = DB::table('census_catalogue_rows')->where('edition_id', $edition)->first();
        DB::table('census_catalogue_rows')->where('id', $row->id)->update(['record_key' => $recordKey, 'values' => json_encode(['P_SCH' => 0])]);
        DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode([
            'fields' => ['P_SCH' => 'Number of Primary School'],
            'rows' => [['identity' => $identity, 'source_sheet' => 'Native <sheet>', 'source_row' => 27, 'value_evidence' => ['P_SCH' => ['source_column' => 19]]]],
        ])]);

        $this->get('/admin/census?edition='.$edition)->assertOk()
            ->assertSee('Number of Primary School')->assertSee('Native &lt;sheet&gt;', false)
            ->assertSee('Row 27')->assertSee('Column 19')
            ->assertDontSee('not workbook row numbers');
    }

    public function test_unreviewed_data_can_be_published_but_integrity_and_admin_access_are_required(): void
    {
        $run = $this->source(false, false);
        $edition = app(CensusCatalogue::class)->prepare($run);
        $this->post('/admin/census/'.$edition.'/publish', ['current' => 0, 'reviewed' => 1])->assertRedirect();
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
        $this->get('/india/census?edition='.$first)->assertOk()->assertSee('earlier published snapshot');
        $this->get('/india/census')->assertOk()->assertViewHas('editions', fn ($rows) => $rows->count() === 2);
        $this->assertDatabaseHas('census_editions', ['id' => $first, 'status' => 'superseded']);
        $this->assertDatabaseCount('census_catalogue_rows', 4);
    }

    public function test_cli_publishes_flagged_data_idempotently_without_claiming_human_review(): void
    {
        $run = $this->source(false, false);
        $data = json_decode(DB::table('import_runs')->where('id', $run)->value('extracted'), true);
        $data['rows'][0]['TOT_P'] = '120';
        DB::table('import_runs')->where('id', $run)->update(['extracted' => json_encode($data)]);
        $this->artisan('census:prepare', ['run' => $run, '--publish' => true])->assertSuccessful();
        $this->artisan('census:prepare', ['run' => $run, '--publish' => true])->assertSuccessful();
        $this->assertDatabaseCount('census_catalogue_reviews', 1);
        $this->assertDatabaseHas('census_catalogue_reviews', ['user_id' => null, 'action' => 'publish_with_notes_cli']);
        $this->assertDatabaseHas('import_runs', ['id' => $run, 'status' => 'needs_review']);
        $this->get('/india/census')->assertOk()->assertSee('†')->assertSee('Population does not equal');
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
