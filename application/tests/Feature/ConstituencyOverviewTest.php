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
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($year === 2024 ? 'a' : 'b', 24), 'record_code' => 1, 'kind' => 'pc', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Uttar Pradesh', 'constituency_name' => 'Lucknow', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 1, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->getJson('/search?q=Lucknow')->assertOk()->assertJsonCount(1, 'suggestions')->assertJsonPath('suggestions.0.type', 'PC · Uttar Pradesh')->assertJsonPath('suggestions.0.label', 'Lucknow');
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [['code' => 1, 'status' => 'validated', 'winner' => 'Example winner', 'candidates' => [['candidate_name' => 'Example winner', 'party_at_election' => 'Example party', 'votes' => 100]], 'number_of_seats' => 1]]]]);
        });
        $url = '/india/constituency?kind=pc&state=Uttar%20Pradesh&name=lucknow';
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
}
