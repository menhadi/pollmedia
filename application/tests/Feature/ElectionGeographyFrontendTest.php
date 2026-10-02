<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ElectionGeographyFrontendTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_and_state_pages_expose_the_election_hierarchy(): void
    {
        $this->seed(PilibhitSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertSeeText('States & Union Territories')
            ->assertSee('/india/state/maharashtra', false)
            ->assertSee('id="historical-results"', false)
            ->assertSee(route('elections.history'))
            ->assertSee(route('elections.assembly'))
            ->assertSee(route('elections.by-election-results'))
            ->assertDontSeeText('Find a linked constituency or district profile')
            ->assertDontSee('published constituency contests')->assertSeeText('States & Union Territories to explore');

        $this->get('/india/state/maharashtra')
            ->assertOk()
            ->assertSee('Maharashtra election coverage')
            ->assertSee('Assembly archive')
            ->assertSee(route('elections.assembly'))
            ->assertSeeText('Browse Maharashtra Assembly sources');
    }

    public function test_district_and_pc_pages_link_each_other_through_verified_assembly_links(): void
    {
        $this->seed(PilibhitSeeder::class);

        $this->get('/india/district/pilibhit')
            ->assertOk()
            ->assertSee('Related parliamentary constituencies')
            ->assertSee('/india/pc/pilibhit', false);

        $this->get('/india/pc/pilibhit')
            ->assertOk()
            ->assertSee('Related administrative districts')
            ->assertSee('/india/district/pilibhit', false);
    }

    public function test_constituency_results_show_distinct_historical_turnout_and_vote_totals(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class]);

        $this->get('/india/pc/pilibhit')
            ->assertOk()
            ->assertSee('Official turnout')
            ->assertSee('Turnout by election year')
            ->assertSee('Electors and votes cast')
            ->assertSee('Recorded candidate + NOTA participation');
    }

    public function test_state_menus_and_directories_link_available_pc_and_ac_histories(): void
    {
        $this->seed(PilibhitSeeder::class);
        foreach ([['pc', 'Example Parliament', 'Maharashtra'], ['ac', 'Example Assembly', 'Maharashtra'], ['pc', 'Other State Seat', 'Assam']] as [$kind,$name,$state]) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($kind === 'pc' ? 'a' : 'b', 24), 'record_code' => $state === 'Assam' ? 2 : 1, 'kind' => $kind, 'year' => 2024, 'edition_label' => '2024', 'state_label' => $state, 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 0, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->mock(HistoricalElectionAnalytics::class, function ($mock) {
            $mock->shouldReceive('forState')->with('Maharashtra', 'pc')->andReturn([]);
            $mock->shouldReceive('forState')->with('Maharashtra', 'ac')->andReturn([]);
        });
        $page = $this->get('/india/state/maharashtra')->assertOk()->assertSee('state-megamenu')->assertSee('Example Parliament')->assertSee('Example Assembly')->assertDontSee('Other State Seat')->assertSee(route('constituency.overview', ['kind' => 'pc', 'state' => 'Maharashtra', 'name' => 'example parliament']));
        $page->assertSeeInOrder(['id="pc-history"', 'id="pc-constituencies"', 'Example Parliament', 'id="ac-history"', 'id="ac-constituencies"', 'Example Assembly', 'id="politics"'], false)->assertSee('href="#politics">Districts & constituencies', false)->assertDontSee('PC &amp; AC directory', false);
        $page->assertSeeInOrder(['Elections <svg', 'Census <svg'], false)->assertSee(route('civic.index', ['state' => 'maharashtra']));
        $this->get('/india/state/maharashtra?election=ac&seat_q=Assembly')->assertOk()->assertSee('Example Assembly')->assertDontSee('Example Parliament');
    }
}
