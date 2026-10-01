<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalElectionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function record(int $code, int $electors, int $polled, int $winner, int $runner): array
    {
        return ['code' => $code, 'status' => 'validated', 'electors' => $electors, 'votes_polled' => $polled, 'candidates' => [
            ['candidate_name' => 'A', 'party_at_election' => 'AAA', 'votes' => $winner],
            ['candidate_name' => 'B', 'party_at_election' => 'BBB', 'votes' => $runner],
        ]];
    }

    public function test_turnout_is_weighted_and_missing_or_disputed_rows_are_not_zero(): void
    {
        $rows = [$this->record(1, 100, 80, 50, 30), $this->record(2, 900, 450, 250, 200)];
        $warning = $this->record(3, 1000, 1000, 900, 100);
        $warning['status'] = 'needs_review';
        $rows[] = $warning;
        $result = app(HistoricalElectionAnalytics::class)->summarize($rows);
        $this->assertSame(530, $result['polled']);
        $this->assertEquals(53, $result['turnout']);
        $this->assertSame(2, $result['turnout_count']);
        $this->assertEquals(35, $result['margin']);
        $this->assertEqualsWithDelta(300 / 530 * 100, $result['parties'][0]['share'], 0.00001);
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$warning])['turnout']);
    }

    public function test_repeated_seats_multi_member_and_incomplete_candidate_votes_are_excluded(): void
    {
        $one = $this->record(1, 100, 80, 50, 30);
        $two = $this->record(2, 100, 80, 50, 30);
        $two['official_ac_code'] = 1;
        $multi = $this->record(3, 100, 80, 50, 30);
        $multi['number_of_seats'] = 2;
        $missing = $this->record(4, 100, 80, 50, 30);
        $missing['candidates'][1]['votes'] = null;
        $result = app(HistoricalElectionAnalytics::class)->summarize([$one, $two, $multi, $missing]);
        $this->assertSame(1, $result['turnout_count']);
        $this->assertSame(0, $result['party_count']);
        $this->assertNull($result['margin']);
    }

    public function test_source_corroborated_turnout_is_shown_with_review_marker_without_accepting_disputed_votes(): void
    {
        $record = $this->record(404, 1310007, 837929, 419539, 112576);
        $record['status'] = 'needs_review';
        $record['detail_page'] = 149;
        $record['summary_page'] = 404;
        $record['valid_candidate_votes'] = 837577;
        $record['summary_totals'] = ['electors' => 1310007, 'votes_polled' => 837929, 'valid_candidate_votes' => 837567];

        $result = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(1, $result['turnout_count']);
        $this->assertSame(1, $result['turnout_review_count']);
        $this->assertSame(837929, $result['polled']);
        $this->assertEqualsWithDelta(63.96, $result['turnout'], 0.005);
        $this->assertSame(0, $result['party_count']);
        $this->assertNull($result['margin']);

        $record['summary_totals']['votes_polled']--;
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$record])['turnout']);
    }

    public function test_internally_reconciled_legacy_detail_figures_remain_visible_with_review_markers(): void
    {
        $record = $this->record(1, 100, 80, 50, 30);
        $record['status'] = 'needs_review';
        $record['error'] = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.';
        $record['detail_page'] = 12;
        $record['valid_candidate_votes'] = 80;

        $result = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(1, $result['turnout_count']);
        $this->assertSame(1, $result['turnout_review_count']);
        $this->assertSame(1, $result['turnout_detail_count']);
        $this->assertEquals(80, $result['turnout']);
        $this->assertSame(1, $result['party_review_count']);
        $this->assertSame(1, $result['margin_review_count']);
        $this->assertSame(2, $result['candidate_rows']);

        $reconciled = $record;
        $reconciled['error'] = 'Candidate rows transcribed from the detailed PDF; summary totals reconcile; publication review pending.';
        $reconciled['summary_page'] = 6;
        $reconciled['summary_totals'] = ['electors' => 100, 'votes_polled' => 80, 'valid_candidate_votes' => 80];
        $result = app(HistoricalElectionAnalytics::class)->summarize([$reconciled]);
        $this->assertSame(1, $result['turnout_count']);
        $this->assertSame(0, $result['turnout_detail_count']);
        $this->assertSame(1, $result['party_count']);

        $record['votes_polled'] = null;
        $result = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertNull($result['turnout']);
        $this->assertSame(1, $result['party_count']);
        $this->assertSame(1, $result['margin_count']);

        $record['valid_candidate_votes']--;
        $result = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(0, $result['party_count']);
        $this->assertNull($result['margin']);
    }

    public function test_ambiguous_workbook_total_can_support_candidate_comparisons_but_not_turnout(): void
    {
        $record = $this->record(1, 100, 80, 50, 30);
        $record['status'] = 'needs_review';
        $record['votes_polled'] = null;
        $record['error'] = 'Candidate cells transcribed from the official workbook; independent summary reconciliation is pending.; The source total column is preserved by its original label; voter and valid-vote meanings require summary verification.';
        $record['reported_totals'] = [['label' => 'Total Votes', 'value' => 80]];
        foreach ($record['candidates'] as $index => &$candidate) {
            $candidate['general_votes'] = $candidate['votes'] - 1;
            $candidate['postal_votes'] = 1;
            $candidate['source_sheet'] = 'DetailedResult';
            $candidate['workbook_row'] = $index + 4;
        }
        unset($candidate);

        $result = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertNull($result['turnout']);
        $this->assertSame(1, $result['party_count']);
        $this->assertSame(1, $result['margin_count']);

        $record['reported_totals'][0]['value']++;
        $result = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(0, $result['party_count']);
        $this->assertNull($result['margin']);
    }

    public function test_legacy_detail_with_separately_reported_nota_keeps_candidate_metrics_and_reconciled_turnout(): void
    {
        $record = $this->record(1, 120, 93, 55, 35);
        $record['status'] = 'needs_review';
        $record['error'] = 'Candidate rows transcribed from the detailed PDF; summary totals reconcile; publication review pending.';
        $record['detail_page'] = 12;
        $record['summary_page'] = 5;
        $record['valid_candidate_votes'] = 90;
        $record['summary_totals'] = ['electors' => 120, 'votes_polled' => 93, 'valid_candidate_votes' => 90, 'nota_votes' => 2];
        $record['candidates'][] = ['candidate_name' => 'None of the Above', 'party_at_election' => 'NOTA', 'votes' => 2, 'is_nota' => true];

        $result = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(1, $result['turnout_count']);
        $this->assertSame(1, $result['party_count']);
        $this->assertSame(1, $result['margin_count']);

        $record['summary_totals']['nota_votes'] = 3;
        $this->assertSame(0, app(HistoricalElectionAnalytics::class)->summarize([$record])['party_count']);
    }

    public function test_state_dashboard_keeps_filters_sources_and_constituency_links(): void
    {
        $this->seed(PilibhitSeeder::class);
        $summary = app(HistoricalElectionAnalytics::class)->summarize([$this->record(1, 100, 80, 50, 30)]) + ['id' => str_repeat('a', 24), 'year' => 2022, 'label' => '2022 report', 'source_url' => 'https://www.eci.gov.in/report', 'state' => 'Uttar Pradesh'];
        $this->mock(HistoricalElectionAnalytics::class, function ($mock) use ($summary): void {
            $mock->shouldReceive('forState')->with('Uttar Pradesh', 'pc')->andReturn([$summary]);
        });
        $this->get('/india/state/uttar-pradesh')->assertOk()->assertSee('How turnout changed')->assertSee('80.0%')->assertSee('Party vote shares')->assertDontSee('Go deeper into')->assertSee('2022 report')->assertSee('data-sortable', false)->assertSee('trend-chart')->assertSee('All parties')->assertSee('All available years')->assertSee('Map of Uttar Pradesh')->assertSeeInOrder(['>Lok Sabha</a>', '>State Assembly</a>'], false);
        $this->get('/india/state/uttar-pradesh?edition='.str_repeat('b', 24))->assertNotFound();
    }

    public function test_state_dashboard_keeps_years_with_candidate_tables_visible_when_turnout_is_unknown(): void
    {
        $known = app(HistoricalElectionAnalytics::class)->summarize([$this->record(1, 100, 80, 50, 30)]) + ['id' => str_repeat('a', 24), 'year' => 2021, 'label' => '2021 report', 'source_url' => 'https://www.eci.gov.in/report', 'state' => 'Assam'];
        $candidateOnly = $this->record(2, 100, 80, 50, 30);
        $candidateOnly['status'] = 'needs_review';
        $candidateOnly['votes_polled'] = null;
        $candidateOnly['error'] = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.';
        $candidateOnly['detail_page'] = 12;
        $candidateOnly['valid_candidate_votes'] = 80;
        $older = app(HistoricalElectionAnalytics::class)->summarize([$candidateOnly]) + ['id' => str_repeat('b', 24), 'year' => 2011, 'label' => '2011 report', 'source_url' => 'https://www.eci.gov.in/older-report', 'state' => 'Assam', 'review_count' => 1];
        $this->mock(HistoricalElectionAnalytics::class, function ($mock) use ($known, $older): void {
            $mock->shouldReceive('forState')->with('Assam', 'ac')->andReturn([$known, $older]);
        });

        $this->get('/india/state/assam?election=ac')
            ->assertOk()
            ->assertSee('2021 report')
            ->assertSee('2011 report')
            ->assertSee('80.0%')
            ->assertSee('2 candidate rows')
            ->assertSee('View tables →')
            ->assertDontSee('No data');
    }

    public function test_2009_source_state_heading_makes_goa_lok_sabha_tables_available(): void
    {
        $edition = collect(app(HistoricalElectionAnalytics::class)->forState('Goa', 'pc'))->firstWhere('year', 2009);

        $this->assertNotNull($edition);
        $this->assertSame('Goa', $edition['state']);
        $this->assertSame(2, $edition['tables']);
        $this->assertSame(2, $edition['review_count']);
        $this->assertSame(2, $edition['turnout_count']);
        $this->assertSame(2, $edition['turnout_review_count']);
        $this->assertNotNull($edition['turnout']);

        $this->get('/india/state/goa?election=pc&edition='.$edition['id'])
            ->assertOk()
            ->assertSee($edition['label'].' †')
            ->assertSee('2 of 2 results have data notes')
            ->assertSee('Turnout in 2 of these results agrees')
            ->assertSee('Map of Goa')
            ->assertSee('View the tables and notes');

        $this->get('/india/elections/lok-sabha?edition='.$edition['id'].'&state=Goa')
            ->assertOk()
            ->assertSee('North Goa')
            ->assertSee('South Goa')
            ->assertSee('Summary and detailed totals differ');
    }
}
