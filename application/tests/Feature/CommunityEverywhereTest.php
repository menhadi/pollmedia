<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CommunityData;
use App\Services\CommunityElectionCorrections;
use App\Services\CommunityMapCorrections;
use App\Services\ElectoralVillageMap;
use App\Services\HistoricalElectionArchive;
use App\Services\HistoricalElectionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $level = 'VILLAGE'): int
    {
        $connector = DB::table('import_connectors')->insertGetId(['name' => 'Official fixture', 'url' => 'https://censusindia.gov.in/test.xls', 'format' => 'xls', 'record_key' => 'code', 'options' => '{}']);
        $run = DB::table('import_runs')->insertGetId(['import_connector_id' => $connector, 'source_url' => 'https://censusindia.gov.in/test.xls', 'origin' => 'url', 'status' => 'accepted', 'sha256' => str_repeat('a', 64), 'extracted' => '{}', 'created_at' => now()]);
        $key = 'fixture-'.$level;
        $edition = DB::table('census_editions')->insertGetId(['import_run_id' => $run, 'source_key' => $key, 'name' => 'Census fixture', 'year' => 2011, 'status' => 'published', 'sha256' => str_repeat('a', 64), 'source_url' => 'https://censusindia.gov.in/test.xls', 'landing_url' => 'https://censusindia.gov.in', 'scope' => 'Official source fixture', 'fields' => json_encode(['TOT_P', 'P_06', 'P_LIT', 'No_HH', 'WATER']), 'row_count' => 1, 'retrieved_at' => now()]);
        DB::table('census_publications')->insert(['source_key' => $key, 'edition_id' => $edition]);

        return DB::table('census_catalogue_rows')->insertGetId(['edition_id' => $edition, 'record_key' => str_repeat('b', 64), 'state_code' => '09', 'district_code' => '151', 'level' => $level, 'residence' => $level === 'VILLAGE' ? 'Rural' : 'Total', 'name' => 'Fixture place', 'geography' => json_encode(['Town/Village' => '123456', 'Subdistt' => '00001']), 'values' => json_encode(['TOT_P' => 100, 'P_06' => 20, 'P_LIT' => 60, 'No_HH' => 20, 'WATER' => '01']), 'flags' => '[]', 'source_row' => 2]);
    }

    private function correction(int $id, string $field, string $value, string $type = 'number'): int
    {
        $data = app(CommunityData::class);
        $this->actingAs(User::factory()->create())->post(route('community.propose', $id), ['field' => $field, 'value_type' => $type, 'value' => $value, 'reason' => 'The official reference shows a corrected source cell.', 'expected' => $data->fingerprint($data->record($id))])->assertRedirect();

        return DB::table('community_data_proposals')->max('id');
    }

    private function approve(int $id): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('community.decide', $id), ['decision' => 'approved', 'review_note' => 'Verified against the original source reference.'])->assertRedirect();
    }

    public function test_listing_scope_has_inline_edit_controls_without_search_and_protects_identifiers(): void
    {
        $id = $this->record('DISTRICT');
        $url = route('community.scope', ['state' => '09', 'district' => '151']);
        $this->get($url)->assertRedirect();
        $this->actingAs(User::factory()->create())->get($url)->assertOk()->assertSee('Fixture place')->assertSee('Place name')->assertSee('Suggested value')->assertSee(route('community.propose', $id), false)->assertSee('Recalculated when its source counts change.');
    }

    public function test_approved_population_changes_update_native_summary_overview_and_quick_facts(): void
    {
        $id = $this->record();
        $service = $this->mock(CommunityMapCorrections::class)->makePartial();
        $service->shouldReceive('parents')->andReturn(['9:173:']);
        $base = ['year' => 2011, 'groups' => ['9:173:' => ['population' => 100, 'households' => 20, 'literacy' => 75, 'literate' => 60, 'population_7_plus' => 80, 'water' => 99, 'water_households' => 999, 'counts' => ['records' => 1, 'population' => 1, 'households' => 1, 'literacy' => 1]]]];
        $proposal = $this->correction($id, 'TOT_P', '200');
        $this->assertSame($base['groups'], $service->apply($base, 'india-overview-metrics.json')['groups']);
        $this->approve($proposal);
        $updated = $service->apply($base, 'india-overview-metrics.json');
        $g = $updated['groups']['9:173:'];
        $this->assertSame(200, $g['population']);
        $this->assertSame(33.33, $g['literacy']);
        $this->assertSame(99, $g['water']);
        $this->assertSame(999, $g['water_households']);
        $native = $service->apply(['year' => 2011, 'field' => 'c11_TOT_P', 'groups' => ['9:173:' => [1, 1, 1, 100, [], 0]]], 'census-2011-fields/c11_TOT_P.json');
        $this->assertSame(200, $native['groups']['9:173:'][3]);
        $data = app(CommunityData::class);
        $facts = $data->quickFacts($data->publishedRows()->where('r.id', $id), 'TOT_P');
        $this->assertEquals(200, $facts['highest']['value']);
        $this->get(route('community.record', $id))->assertOk()->assertSee('33.33');
    }

    public function test_raw_water_codes_remain_distributions_and_never_replace_jjm_percentages(): void
    {
        $id = $this->record();
        $service = $this->mock(CommunityMapCorrections::class)->makePartial();
        $service->shouldReceive('parents')->andReturn(['9:173:']);
        $this->approve($this->correction($id, 'WATER', '02', 'text'));
        $data = $service->apply(['year' => 2011, 'field' => 'c11_WATER', 'groups' => ['9:173:' => [1, 1, 0, 0, ['"01"' => 1], 0]]], 'census-2011-fields/c11_WATER.json');
        $this->assertSame(0, $data['groups']['9:173:'][3]);
        $this->assertSame(['"02"' => 1], $data['groups']['9:173:'][4]);
        $this->get(route('community.map-data', ['file' => '../../.env']))->assertNotFound();
        $this->get(route('community.map-data', ['file' => 'jjm-water.json']))->assertNotFound();
    }

    public function test_constituency_inline_edit_goes_through_admin_and_updates_calculated_results(): void
    {
        $original = ['code' => 1, 'name' => 'AMETHI', 'constituency_name' => 'AMETHI', 'state_name' => 'Uttar Pradesh', 'status' => 'validated', 'electors' => 100, 'votes_polled' => 80, 'valid_candidate_votes' => 80, 'number_of_seats' => 1, 'winner' => 'Candidate A', 'margin' => 20, 'candidates' => [['candidate_name' => 'Candidate A', 'party_at_election' => 'Party A', 'votes' => 50, 'general_votes' => 50, 'postal_votes' => 0], ['candidate_name' => 'Candidate B', 'party_at_election' => 'Party B', 'votes' => 30, 'general_votes' => 30, 'postal_votes' => 0]]];
        $source = ['source_url' => 'https://eci.gov.in/results', 'source_sha256' => str_repeat('c', 64), 'records' => [$original]];
        $this->mock(HistoricalElectionArchive::class)->shouldReceive('load')->andReturn([$source]);
        $id = DB::table('historical_constituency_index')->insertGetId(['edition_id' => str_repeat('a', 24), 'record_code' => 1, 'kind' => 'pc', 'year' => 2024, 'edition_label' => '2024', 'state_label' => 'Uttar Pradesh', 'constituency_name' => 'AMETHI', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 2, 'extraction_sha256' => str_repeat('c', 64)]);
        $this->get(route('community.election', $id))->assertRedirect();
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('community.election', $id))->assertOk()->assertSee('Candidate A')->assertSee('Suggested value');
        $document = app(CommunityElectionCorrections::class)->document($id);
        $this->post(route('community.election.propose', $id), ['field' => 'electors', 'value_type' => 'number', 'value' => '200', 'reason' => 'The official constituency report corrects registered electors.', 'expected' => $document['expected']])->assertRedirect();
        $proposal = DB::table('community_data_proposals')->max('id');
        $this->assertDatabaseCount('historical_election_reviews', 0);
        $this->approve($proposal);
        $record = app(HistoricalElectionReview::class)->apply(str_repeat('a', 24), $original, $source['source_sha256']);
        $this->assertSame(200, $record['electors']);
        $this->assertSame(20, $record['margin']);
        $this->get(route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'AMETHI']))->assertOk()->assertSee('40.00');
        $this->actingAs($user)->get(route('community.account'))->assertOk()->assertSee('Approved')->assertSee('200');
    }

    public function test_map_endpoint_refreshes_after_approval_without_changing_generated_source_files(): void
    {
        $id = $this->record();
        $service = $this->mock(CommunityMapCorrections::class)->makePartial();
        $service->shouldReceive('parents')->andReturn(['9:173:']);
        $folder = public_path('maps/census-2011-fields');
        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }$path = $folder.'/c11_CORRECTION_TEST.json';
        $this->assertFileDoesNotExist($path);
        $raw = json_encode(['year' => 2011, 'field' => 'c11_TOT_P', 'groups' => ['9:173:' => [1, 1, 1, 100, [], 0]]]);
        file_put_contents($path, $raw);
        try {
            $url = route('community.map-data', ['file' => 'census-2011-fields/c11_CORRECTION_TEST.json']);
            $this->getJson($url)->assertOk()->assertJsonPath('groups.9:173:.3', 100)->assertHeader('Cache-Control', 'no-store, private');
            $proposal = $this->correction($id, 'TOT_P', '250');
            $this->approve($proposal);
            $this->getJson($url)->assertOk()->assertJsonPath('groups.9:173:.3', 250);
            $this->assertSame($raw, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_approved_district_source_total_updates_its_uniquely_linked_map_group(): void
    {
        $id = $this->record('DISTRICT');
        $service = $this->mock(CommunityMapCorrections::class)->makePartial();
        $service->shouldReceive('parents')->andReturn(['9:173:']);
        $this->approve($this->correction($id, 'TOT_P', '300'));
        $data = $service->apply(['year' => 2011, 'groups' => ['9:173:' => ['population' => 80, 'households' => 15, 'literate' => 45, 'population_7_plus' => 60, 'literacy' => 75, 'counts' => ['records' => 1, 'population' => 1, 'households' => 1, 'literacy' => 1]]]], 'india-overview-metrics.json');
        $this->assertSame(300, $data['groups']['9:173:']['population']);
        $this->assertSame($id, $data['community_direct_records']['9:173:']);
        $this->assertStringContainsString('historical boundary equivalence is not asserted', $data['groups']['9:173:']['notes'][0]);
    }

    public function test_numeric_record_ids_resolve_through_the_existing_official_sqlite_identity_index(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'community-identity-');
        $index = new \PDO('sqlite:'.$path);
        $index->exec('CREATE TABLE identities(lgd TEXT PRIMARY KEY,identity_json TEXT NOT NULL)');
        $index->prepare('INSERT INTO identities VALUES (?,?)')->execute(['700001', json_encode(['record_id' => 42])]);
        $parents = new \PDO('sqlite::memory:');
        $parents->exec("CREATE TABLE ancestry(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT); INSERT INTO ancestry VALUES ('700001','9','173','6867')");
        $map = $this->mock(ElectoralVillageMap::class);
        $map->shouldReceive('database')->andReturn($parents);
        $map->shouldReceive('directory')->andReturn(sys_get_temp_dir());
        $map->shouldReceive('connections')->with('700001')->andReturn(['constituencies' => []]);
        config(['community-map.pca_index_path' => $path]);
        try {
            $keys = (new CommunityMapCorrections)->parents((object) ['year' => 2001, 'level' => 'VILLAGE', 'source_key' => 'census-2001-villages-fixture', 'geography' => '{}'], 42);
            $this->assertSame(['9:173:', '9:173:6867'], $keys);
        } finally {
            $index = null;
            unlink($path);
        }
    }

    public function test_2001_pca_population_correction_replaces_the_original_directory_contribution(): void
    {
        $id = $this->record();
        $edition = DB::table('census_catalogue_rows')->where('id', $id)->value('edition_id');
        DB::table('census_editions')->where('id', $edition)->update(['year' => 2001, 'source_key' => 'census-2001-villages-fixture']);
        DB::table('census_publications')->where('edition_id', $edition)->update(['source_key' => 'census-2001-villages-fixture']);
        $service = $this->mock(CommunityMapCorrections::class)->makePartial();
        $service->shouldReceive('parents')->andReturn(['9:173:']);
        $service->shouldReceive('directoryBaseline')->with($id)->andReturn(['record_id' => 123, 'values' => ['T_P' => 90, 'T_HH' => 20]]);
        $this->approve($this->correction($id, 'TOT_P', '200'));
        $updated = $service->apply(['year' => 2001, 'groups' => ['9:173:' => ['population' => 90, 'households' => 20, 'literate' => 60, 'population_7_plus' => 80, 'literacy' => 75, 'counts' => ['records' => 1, 'population' => 1, 'households' => 1, 'literacy' => 1]]]], 'india-overview-2001-metrics.json');
        $this->assertSame(200, $updated['groups']['9:173:']['population']);
        $this->assertSame(33.33, $updated['groups']['9:173:']['literacy']);
    }

    public function test_newer_state_listing_keeps_an_editor_without_inventing_a_historical_census_identity(): void
    {
        $this->actingAs(User::factory()->create())->get(route('community.scope', ['state' => 'telangana', 'level' => 'STATE']))->assertOk()->assertSee('No published source record')->assertDontSee('No unambiguous Census state identity');
    }
    public function test_same_page_edit_login_returns_to_the_public_page_and_rejects_external_paths(): void
    {
        $path = '/india/districts/uttar-pradesh/pilibhit?edit=1#census-years';
        $this->get(route('community.login', ['next' => $path]))->assertOk()->assertSessionHas('url.intended', $path);
        foreach (['//evil.example/india', 'https://evil.example/india', '/india\\evil.example', '/india%0d%0aLocation:evil', '/admin'] as $unsafe) {
            $this->assertNull(CommunityData::publicEditReturn($unsafe));
        }
    }

    public function test_inline_suggestion_returns_to_the_same_public_page_without_changing_the_source(): void
    {
        $id = $this->record();
        $data = app(CommunityData::class);
        $record = $data->record($id);
        $path = '/india/census/places/'.$id.'?edit=1#census-years';
        $this->actingAs(User::factory()->create())->post(route('community.propose', $id), ['field' => 'TOT_P', 'value' => '120', 'value_type' => 'number', 'reason' => 'Corrected total from the official source.', 'expected' => $data->fingerprint($record), 'return_to' => $path])->assertRedirect($path);
        $this->assertSame(100, json_decode($data->record($id)->values, true)['TOT_P']);
        $this->assertDatabaseHas('community_data_proposals', ['record_id' => $id, 'field' => 'TOT_P', 'status' => 'pending']);
        $html = $this->get(route('community.record', $id))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'name="field" value="TOT_P"'));
        $this->assertStringNotContainsString('id="edit-field"', $html);
        $this->assertStringNotContainsString('Listing name', $html);
        $this->assertStringContainsString('data-source-record="'.$id.'"', $html);
    }

    public function test_chart_table_uses_the_same_source_record_for_editing_and_plotting(): void
    {
        $id = $this->record();
        $record = app(CommunityData::class)->record($id);
        $series = app(\App\Services\CensusProfileSummary::class)->series($record, [$record], 2011);
        $this->assertSame($id, $series['rows'][0]['record_id']);
        $html = view('census-profile-charts', ['censusSeries' => $series])->render();
        $this->assertStringContainsString('data-correction-record="'.$id.'" data-correction-field="TOT_P"', $html);
        $this->assertStringContainsString('data-correction-field="P_LIT"', $html);
    }
}
