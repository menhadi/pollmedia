<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateElectionSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sections_keep_histories_and_year_selections_separate(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        $summary = function ($year, $id, $name, $electors, $polled) use ($analytics) {
            return $analytics->summarize([['code' => 1, 'status' => 'validated', 'has_warning' => false, 'constituency_name' => $name, 'number_of_seats' => 1, 'electors' => $electors, 'votes_polled' => $polled, 'candidates' => [['candidate_name' => $name.' winner', 'party_at_election' => 'A', 'votes' => $polled * 0.75], ['candidate_name' => 'Runner up', 'party_at_election' => 'B', 'votes' => $polled * 0.25]]]]) + ['id' => str_repeat($id, 24), 'year' => $year, 'label' => $year.' source report', 'state' => 'Uttar Pradesh', 'source_url' => 'https://www.eci.gov.in/'.$id, 'review_count' => 0];
        };
        $pc = [$summary(2024, 'a', 'Parliament Seat', 1000, 800), $summary(2019, 'b', 'Old Parliament Seat', 1000, 600)];
        $ac = [$summary(2022, 'c', 'Assembly Seat', 500, 400)];
        $this->mock(HistoricalElectionAnalytics::class, function ($mock) use ($pc, $ac) {
            $mock->shouldReceive('forState')->with('Uttar Pradesh', 'pc')->andReturn($pc);
            $mock->shouldReceive('forState')->with('Uttar Pradesh', 'ac')->andReturn($ac);
        });
        $page = $this->get('/india/state/uttar-pradesh')->assertOk()->assertSeeInOrder(['id="pc-history"', 'id="pc-results"', 'id="ac-history"', 'id="ac-results"'], false)->assertSee('2024 Lok Sabha results')->assertSee('2022 State Assembly results')->assertSee('name="pc_edition"', false)->assertSee('name="ac_edition"', false);
        preg_match_all('/class="history-chart-data">(.*?)<\/script>/s', $page->getContent(), $charts);
        $this->assertCount(10, $charts[1]);
        $this->assertSame([2019, 2024], array_column(json_decode($charts[1][0], true)['rows'], 'year'));
        $this->assertSame([2022], array_column(json_decode($charts[1][5], true)['rows'], 'year'));
        $this->get('/india/state/uttar-pradesh?pc_edition='.str_repeat('b', 24).'&ac_edition='.str_repeat('c', 24))->assertOk()->assertSee('2019 Lok Sabha results')->assertSee('2022 State Assembly results');
        $this->get('/india/state/uttar-pradesh?pc_edition='.str_repeat('c', 24))->assertNotFound();
        $this->get('/india/state/uttar-pradesh?election=ac&edition='.str_repeat('c',24))->assertOk()->assertSee('2022 State Assembly results');
    }
}
