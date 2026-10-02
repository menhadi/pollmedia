<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StateElectionSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sections_keep_histories_and_year_selections_separate(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        $summary = function ($year, $id, $name, $electors, $polled) use ($analytics) {
            return $analytics->summarize([['code' => 1, 'status' => 'validated', 'has_warning' => false, 'constituency_name' => $name, 'number_of_seats' => 1, 'electors' => $electors, 'votes_polled' => $polled, 'candidates' => [['candidate_name' => $name.' winner', 'party_at_election' => 'A', 'votes' => (int) ($polled * 0.75)], ['candidate_name' => 'Runner up', 'party_at_election' => 'B', 'votes' => (int) ($polled * 0.25)]]]]) + ['id' => str_repeat($id, 24), 'year' => $year, 'label' => $year.' source report', 'state' => 'Uttar Pradesh', 'source_url' => 'https://www.eci.gov.in/'.$id, 'review_count' => 0];
        };
        $pc = [$summary(2024, 'a', 'Parliament Seat', 1000, 800), $summary(2019, 'b', 'Old Parliament Seat', 1000, 600)];
        $ac = [$summary(2022, 'c', 'Assembly Seat', 500, 400)];
        $this->mock(HistoricalElectionAnalytics::class, function ($mock) use ($pc, $ac) {
            $mock->shouldReceive('forState')->with('Uttar Pradesh', 'pc')->andReturn($pc);
            $mock->shouldReceive('forState')->with('Uttar Pradesh', 'ac')->andReturn($ac);
        });
        $page = $this->get('/india/state/uttar-pradesh')->assertOk()->assertSeeInOrder(['id="pc-history"', 'id="pc-results"', 'id="ac-history"', 'id="ac-results"'], false)->assertSee('2024 Lok Sabha results')->assertSee('2022 State Assembly results')->assertSee('name="pc_edition"', false)->assertSee('name="ac_edition"', false);
        foreach (['pc' => 'Lok Sabha · PC', 'ac' => 'State Assembly · AC'] as $reportKind => $reportLabel) {
            $report = $this->get('/india/state/uttar-pradesh?format=report&election='.$reportKind)->assertOk()->assertSee($reportLabel)->assertSee('Print / Save as PDF')->assertSee('Sources and coverage by election year')->assertSee('Methodology & disclaimer', false)->assertDontSee('<select', false)->assertDontSee('<details', false)->assertDontSee('data-history-chart');
            $this->assertSame(5, substr_count($report->getContent(), 'class="report-plot"'));
            $report->assertSeeInOrder(['Chart values', 'Sources and coverage by election year', 'Methodology & disclaimer'], false);
        }
        preg_match_all('/class="history-chart-data">(.*?)<\/script>/s', $page->getContent(), $charts);
        $this->assertCount(10, $charts[1]);
        $this->assertSame([2019, 2024], array_column(json_decode($charts[1][0], true)['rows'], 'year'));
        $this->assertSame([2022], array_column(json_decode($charts[1][5], true)['rows'], 'year'));
        $this->get('/india/state/uttar-pradesh?pc_edition='.str_repeat('b', 24).'&ac_edition='.str_repeat('c', 24))->assertOk()->assertSee('2019 Lok Sabha results')->assertSee('2022 State Assembly results');
        $this->get('/india/state/uttar-pradesh?pc_edition='.str_repeat('c', 24))->assertNotFound();
        $this->get('/india/state/uttar-pradesh?election=ac&edition='.str_repeat('c', 24))->assertOk()->assertSee('2022 State Assembly results');
    }

    public function test_current_directory_excludes_former_names_and_keeps_election_types_separate(): void
    {
        foreach ([['pc', 2024, 'a', 'Present PC'], ['pc', 2019, 'b', 'Former PC'], ['ac', 2022, 'c', 'Present AC'], ['ac', 2017, 'd', 'Former AC']] as [$kind,$year,$id,$name]) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($id, 24), 'record_code' => 1, 'kind' => $kind, 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Maharashtra', 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 0, 'extraction_sha256' => str_repeat('e', 64)]);
        }
        $this->mock(HistoricalElectionAnalytics::class, function ($mock) {
            $mock->shouldReceive('forState')->with('Maharashtra', 'pc')->andReturn([]);
            $mock->shouldReceive('forState')->with('Maharashtra', 'ac')->andReturn([]);
        });
        $this->get('/india/state/maharashtra')->assertOk()->assertSee('Present Pc')->assertSee('Present Ac')->assertDontSee('Former Pc')->assertDontSee('Former Ac')->assertSee('data-fragment-form', false);
        $this->get('/india/state/maharashtra?pc_scope=archive')->assertOk()->assertSee('Former Pc')->assertSee('Present Ac')->assertDontSee('Present Pc')->assertDontSee('Former Ac');
        $this->get('/india/state/maharashtra?ac_scope=archive')->assertOk()->assertSee('Former Ac')->assertSee('Present Pc')->assertDontSee('Present Ac');
        $this->getJson('/india/state/maharashtra?suggest=pc&pc_q=Present')->assertOk()->assertJsonCount(1, 'suggestions')->assertJsonPath('suggestions.0.label', 'Present Pc')->assertJsonPath('suggestions.0.type', 'PC');
        $this->getJson('/india/state/maharashtra?suggest=ac&ac_scope=archive&ac_q=Former')->assertOk()->assertJsonCount(1, 'suggestions')->assertJsonPath('suggestions.0.label', 'Former Ac')->assertJsonPath('suggestions.0.type', 'Archive · AC');
        $this->get('/india/state/maharashtra?pc_q=missing')->assertOk()->assertDontSee('Present Pc')->assertSee('Present Ac');
    }
}
