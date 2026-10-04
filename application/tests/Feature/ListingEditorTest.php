<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DataCorrections;
use App\Services\HistoricalElectionArchive;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListingEditorTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $raw = ['code' => 1, 'name' => 'AMETHI', 'constituency_name' => 'AMETHI', 'state_name' => 'Uttar Pradesh', 'status' => 'validated', 'electors' => 100, 'votes_polled' => 80, 'valid_candidate_votes' => 80, 'number_of_seats' => 1, 'winner' => 'Candidate A', 'margin' => 20, 'candidates' => [['candidate_name' => 'Candidate A', 'party_at_election' => 'Party A', 'votes' => 50, 'general_votes' => 50, 'postal_votes' => 0], ['candidate_name' => 'Candidate B', 'party_at_election' => 'Party B', 'votes' => 30, 'general_votes' => 30, 'postal_votes' => 0]]];
        $data = ['source_url' => 'https://eci.gov.in/results', 'source_sha256' => str_repeat('c', 64), 'records' => [$raw]];
        $this->mock(HistoricalElectionArchive::class)->shouldReceive('load')->andReturn([$data]);
        $ids = [];
        foreach ([2024, 2019] as $year) {
            $ids[] = DB::table('historical_constituency_index')->insertGetId(['edition_id' => str_repeat($year === 2024 ? 'a' : 'b', 24), 'record_code' => 1, 'kind' => 'pc', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Uttar Pradesh', 'constituency_name' => 'AMETHI', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 2, 'extraction_sha256' => str_repeat('c', 64)]);
        }

        return [$raw, $data, $ids];
    }

    public function test_selecting_a_constituency_opens_every_year_and_candidate_field(): void
    {
        [$raw, $data, $ids] = $this->fixture();
        $this->get('/admin/listings/elections?q=Amethi')->assertOk()->assertSee('AMETHI')->assertSee('Edit complete listing')->assertDontSee('Record collection');
        $this->get('/admin/listings/elections?id='.$ids[0])->assertOk()->assertSee('AMETHI · 2024')->assertSee('AMETHI · 2019')->assertSee('Candidate A')->assertSee('Candidate B')->assertSee('Registered electors', false)->assertSee('https://eci.gov.in/results')->assertSee('Save and update public listing');
    }

    public function test_complete_listing_save_updates_winner_history_and_chart_input_and_rejects_stale_edits(): void
    {
        [$raw, $data, $ids] = $this->fixture();
        $changed = $raw;
        $changed['candidates'][0]['candidate_name'] = 'Corrected MP';
        $changed['candidates'][0]['party_at_election'] = 'Corrected party';
        $changed['electors'] = 200;
        $input = ['table' => 'historical_constituency_index', 'id' => $ids[0], 'expected' => hash('sha256', json_encode([$data['source_sha256'], $raw, 0])), 'record' => json_encode($changed), 'reason' => 'Verified the official PDF figures.', 'reference_url' => $data['source_url']];
        $this->post('/admin/listings/save', $input)->assertSessionHasNoErrors();
        $this->post('/admin/listings/save', $input)->assertStatus(409);
        $response = $this->get(route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'AMETHI']))->assertOk()->assertSee('Corrected MP')->assertSee('Corrected party')->assertSee('40.00');
        $review = json_decode(DB::table('historical_election_reviews')->first()->record, true);
        $this->assertSame(200, $review['electors']);
        $this->assertSame('Corrected MP', $review['winner']);
        $this->assertSame(20, $review['margin']);
        $this->assertTrue($review['admin_listing_correction']);
    }

    public function test_election_seo_uses_dynamic_years_and_publishes_without_drafts(): void
    {
        $this->fixture();
        $this->get('/admin/seo?type=pc')->assertOk()->assertSee('Election year')->assertSee('2024')->assertSee('2019')->assertDontSee('2001')->assertDontSee('2011')->assertDontSee('Recent drafts')->assertSee('AMETHI');
        $this->post('/admin/seo/publish', ['type' => 'pc', 'year' => 2024, 'state' => 'Uttar Pradesh', 'bulk' => 'all'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('seo_batches', 0);
        $this->assertDatabaseCount('seo_metadata', 1);
        $path = DB::table('seo_metadata')->value('path');
        $this->get($path)->assertOk()->assertSee('name="keywords"', false)->assertSee('AMETHI Lok Sabha');
        $this->get('/admin/seo?type=sir')->assertOk()->assertDontSee('Election year')->assertDontSee('Census year');
        $this->post('/admin/seo/publish', ['type' => 'pc', 'year' => 2001, 'bulk' => 'all'])->assertSessionHasErrors('paths');
    }

    public function test_census_and_sir_editors_show_source_measures_and_save_without_json_editing(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $connector = DB::table('import_connectors')->insertGetId(['name' => 'Census fixture', 'url' => 'https://censusindia.gov.in/source.xlsx', 'format' => 'xlsx', 'record_key' => 'code', 'options' => '{}']);
        $run = DB::table('import_runs')->insertGetId(['import_connector_id' => $connector, 'source_url' => 'https://censusindia.gov.in/source.xlsx', 'origin' => 'url', 'status' => 'accepted', 'created_at' => now()]);
        $edition = DB::table('census_editions')->insertGetId(['import_run_id' => $run, 'source_key' => 'india-basic-1991-total', 'name' => 'Census 1991', 'year' => 1991, 'status' => 'published', 'sha256' => str_repeat('a', 64), 'source_url' => 'https://censusindia.gov.in/source.xlsx', 'landing_url' => 'https://censusindia.gov.in/', 'scope' => 'Source scope', 'fields' => '["TOT_P","No_HH"]', 'row_count' => 1, 'retrieved_at' => now()]);
        $id = DB::table('census_catalogue_rows')->insertGetId(['edition_id' => $edition, 'record_key' => str_repeat('a', 64), 'state_code' => '09', 'district_code' => '151', 'level' => 'STATE', 'residence' => 'Total', 'name' => 'Source area', 'geography' => '{"Subdistt":"00000","Town/Village":"000000","Ward":"0000"}', 'values' => '{"TOT_P":100,"No_HH":null}', 'flags' => '[]', 'source_row' => 1]);
        $this->get('/admin/listings/census?id='.$id)->assertOk()->assertSee('Total population (TOT_P)')->assertSee('Households (No_HH)')->assertSee('https://censusindia.gov.in/source.xlsx');
        $record = DB::table('census_catalogue_rows')->find($id);
        $fields = (array) $record;
        foreach (['geography', 'values', 'flags'] as $key) {
            $fields[$key] = json_decode($fields[$key], true);
        }
        $fields['values']['TOT_P'] = 150;
        $this->post('/admin/listings/save', ['table' => 'census_catalogue_rows', 'id' => $id, 'expected' => hash('sha256', json_encode($record)), 'record' => json_encode($fields), 'reason' => 'Reviewed official census source.'])->assertSessionHasNoErrors();
        $this->get('/india/census/places/'.$id)->assertOk()->assertSee('150');
        $this->get('/admin/seo?type=census')->assertOk()->assertSee('1991')->assertDontSee('2011')->assertDontSee('2001');
        $this->seed(PilibhitSeeder::class);
        $sir = app(DataCorrections::class)->sirRows()->first();
        $this->get('/admin/listings/sir?id='.$sir->id)->assertOk()->assertSee('Listed records')->assertSee('Attach reference file');
    }
}
