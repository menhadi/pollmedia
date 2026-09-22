<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('published constituency contests');

        $this->get('/india/state/maharashtra')
            ->assertOk()
            ->assertSee('Maharashtra election coverage')
            ->assertSee('Assembly archive')
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
}
