<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionArchive;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_groups_years_and_overview_defaults_to_history(): void
    {
        foreach ([2019, 2024] as $year) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($year === 2024 ? 'a' : 'b', 24), 'record_code' => 1, 'kind' => 'pc', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => $year === 2019 ? 'UTTAR PRADESH' : 'Uttar Pradesh', 'constituency_name' => 'Lucknow', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 1, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->getJson('/search?q=Lucknow')->assertOk()->assertJsonCount(1, 'suggestions')->assertJsonPath('suggestions.0.type', 'PC · Uttar Pradesh')->assertJsonPath('suggestions.0.label', 'Lucknow');
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [['code' => 1, 'status' => 'validated', 'winner' => 'Example winner', 'candidates' => [['candidate_name' => 'Example winner', 'party_at_election' => 'Example party', 'votes' => 100]], 'number_of_seats' => 1]]]]);
        });
        $url = '/india/constituency?kind=pc&state=UTTAR%20PRADESH&name=lucknow';
        $this->get($url)->assertOk()->assertSee('Election history')->assertSee('Example winner')->assertSee('Current office-holder status has not yet been verified')->assertSee('2019')->assertSee('2024')->assertSee('2024 results')->assertSee('How voting has changed')->assertSee('Election year')->assertSee('Area locator')->assertSee('Registered electors and votes polled')->assertSee('Absolute counts, not percentages');
        $this->get($url.'&edition='.str_repeat('a', 24))->assertOk()->assertSee('2024 results')->assertSee('Example party');
        $this->get($url.'&edition='.str_repeat('d', 24))->assertNotFound();
        $this->get('/india/elections/lok-sabha?edition='.str_repeat('a', 24).'&state=Uttar%20Pradesh&code=1')->assertRedirect(route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'Lucknow', 'edition' => str_repeat('a', 24)]));
    }

    public function test_legacy_pilibhit_profile_opens_shared_history_template(): void
    {
        $this->seed(PilibhitSeeder::class);
        DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat('a', 24), 'record_code' => 26, 'kind' => 'pc', 'year' => 2024, 'edition_label' => '2024', 'state_label' => 'Uttar Pradesh', 'constituency_name' => 'Pilibhit', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 1, 'extraction_sha256' => str_repeat('c', 64)]);
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [['code' => 26, 'status' => 'needs_review', 'candidates' => []]]]]);
        });
        $this->get(route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'Pilibhit']))->assertOk()->assertSee('Connected places')->assertSee('Baheri')->assertSee('Bareilly')->assertSee('Puranpur');
        $this->get('/india/pc/pilibhit')->assertRedirect(route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'Pilibhit']));
    }

    public function test_related_seats_use_official_mapping_without_legacy_profiles(): void
    {
        foreach ([['pc', 'Aonla', 1], ['ac', 'Bithari Chainpur', 2]] as [$kind,$name,$code]) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat('a', 24), 'record_code' => $code, 'kind' => $kind, 'year' => 2024, 'edition_label' => '2024', 'state_label' => 'Uttar Pradesh', 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 0, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['records' => []]]);
        });
        $this->get(route('constituency.overview', ['kind' => 'pc', 'state' => 'UTTAR PRADESH', 'name' => 'Aonla']))->assertOk()->assertSee('Connected places')->assertSee('Bithari Chainpur')->assertSee('Bareilly')->assertSee('Official district reference')->assertSee(route('constituency.overview', ['kind' => 'ac', 'state' => 'Uttar Pradesh', 'name' => 'Bithari Chainpur']));
        $this->assertDatabaseCount('places', 0);
    }

    public function test_state_case_and_official_codes_resolve_across_states(): void
    {
        foreach ([['KARNATAKA', 'Karnataka', 'S10', 'Example Karnataka'], ['ASSAM', 'Assam', 'S03', 'Example Assam'], ['UTTAR PRADESH', 'Uttar Pradesh', 'S24', 'Example UP']] as [$upper,$label,$code,$name]) {
            foreach ([$upper, $label, $code] as $i => $variant) {
                DB::table('historical_constituency_index')->insert(['edition_id' => substr(hash('sha256', $name.$i), 0, 24), 'record_code' => 1, 'kind' => 'pc', 'year' => 2004 + $i * 10, 'edition_label' => (string) (2004 + $i * 10), 'state_label' => $variant, 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 0, 'extraction_sha256' => str_repeat('c', 64)]);
            }
        }
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['records' => []]]);
        });
        foreach (['KARNATAKA' => 'Example Karnataka', 'ASSAM' => 'Example Assam', 'UTTAR PRADESH' => 'Example UP'] as $state => $name) {
            $this->get(route('constituency.overview', ['kind' => 'pc', 'state' => $state, 'name' => $name]))->assertOk()->assertSee('2004')->assertSee('2014')->assertSee('2024')->assertSee('3 available years');
            $this->getJson('/search?q='.urlencode($name))->assertOk()->assertJsonCount(1, 'suggestions');
        }
    }
}
