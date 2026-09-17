<?php

namespace Tests\Feature;

use App\Services\ElectionResults;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ElectionResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_results_year_selection_and_geographic_scope(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class]);
        $place = DB::table('places')->where('slug', 'pc-pilibhit')->value('id');
        $results = app(ElectionResults::class)->forPlace($place);
        $this->assertSame([2024, 2019], $results->pluck('year')->all());
        $this->assertSame(164935, $results[0]->margin);
        $this->assertSame(255627, $results[1]->margin);
        $this->assertEqualsWithDelta(52.2855, 100 * $results[0]->winner->votes / $results[0]->votes_polled, 0.001);
        $this->assertSame(6741, $results[0]->nota);
        $this->assertSame(288, $results[0]->unallocated);
        $this->get('/india/pc/pilibhit')->assertOk()->assertSee('Full candidate results · 2024')->assertSee('164,935');
        $this->get('/india/pc/pilibhit?year=2019')->assertOk()->assertSee('Full candidate results · 2019')->assertSee('255,627');
        $this->get('/india/pc/pilibhit?year=2004')->assertNotFound();
        $this->get('/india/district/pilibhit')->assertOk()->assertDontSee('Full candidate results');
        $this->seed(PilibhitElectionSeeder::class);
        $this->assertDatabaseCount('election_contests', 2);
        $this->assertDatabaseCount('election_candidate_results', 25);
    }

    public function test_inconsistent_candidate_totals_are_rejected(): void
    {
        $fixture = json_decode(file_get_contents(database_path('fixtures/pilibhit-elections.json')), true)[0];
        $fixture['candidates'][0]['postal_votes']++;
        $this->expectException(InvalidArgumentException::class);
        app(ElectionResults::class)->validate($fixture);
    }
}
