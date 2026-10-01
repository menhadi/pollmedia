<?php

namespace Tests\Feature;

use App\Services\ElectionArchive;
use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
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

    public function test_officially_uncontested_winner_is_shown_without_votes_or_margin(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        $record = ['code' => 150, 'name' => 'DESURI (SC)', 'votes_polled' => 0, 'candidates' => [
            ['candidate_name' => 'DINESH RAI DANGI', 'party_at_election' => 'INC', 'votes' => 0],
        ]];
        $result = $analytics->singleSeatResult($record, '00fa45113ca5b5cde46a2802');
        $this->assertSame(['winner' => 'DINESH RAI DANGI', 'party' => 'INC', 'margin' => null, 'derived' => false, 'uncontested' => true], $result);
        $this->assertNull($analytics->summarize([$record])['turnout']);
        $this->assertNull($analytics->summarize([$record])['margin']);

        $record['candidates'][0]['candidate_name'] = 'NOTA';
        $this->assertNull($analytics->singleSeatResult($record, '00fa45113ca5b5cde46a2802'));
        $record['candidates'][0]['candidate_name'] = 'DINESH RAI DANGI';
        $record['candidates'][0]['votes'] = 1;
        $this->assertNull($analytics->singleSeatResult($record, '00fa45113ca5b5cde46a2802'));
    }

    public function test_unverified_single_candidate_zero_does_not_become_an_uncontested_winner(): void
    {
        $record = ['code' => 144, 'name' => 'FALTA', 'candidates' => [
            ['candidate_name' => 'Candidate A', 'party_at_election' => 'AAA', 'votes' => 0],
        ]];
        $this->assertNull(app(HistoricalElectionAnalytics::class)->singleSeatResult($record, '43f931b20e1fe26e3e1f72ec'));
    }

    public function test_source_summary_can_confirm_a_recent_uncontested_winner(): void
    {
        $record = ['code' => 31, 'name' => 'Akuluto (ST)', 'candidates' => [
            ['candidate_name' => 'Kazheto', 'party_at_election' => 'BJP', 'votes' => 0],
        ], 'summary_source_rows' => [
            ['Constituency Name', '31-Akuluto-(ST)'],
            ['Winner', 'BJP', 'Kazheto'],
            ['*THE ELECTION IN AC-31: AKULUTO (ST) WAS UNCONTESTED.'],
        ]];
        $this->assertTrue(app(HistoricalElectionAnalytics::class)->singleSeatResult($record, '060caae725598a42799ba636')['uncontested']);
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

    public function test_same_official_seat_in_separate_election_rounds_counts_each_round_once(): void
    {
        $february = $this->record(100001, 100, 80, 50, 30);
        $february['official_ac_code'] = 1;
        $february['election_round'] = '2005-feb';
        $october = $this->record(200001, 200, 100, 60, 40);
        $october['official_ac_code'] = 1;
        $october['election_round'] = '2005-oct';

        $summary = app(HistoricalElectionAnalytics::class)->summarize([$february, $october]);

        $this->assertSame(2, $summary['turnout_count']);
        $this->assertSame(180, $summary['polled']);
        $this->assertSame(2, $summary['margin_count']);
        $this->assertSame(2, $summary['party_count']);

        $duplicate = $october;
        $duplicate['code'] = 200002;
        $summary = app(HistoricalElectionAnalytics::class)->summarize([$february, $october, $duplicate]);
        $this->assertSame(1, $summary['turnout_count']);
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

    public function test_official_summary_turnout_can_show_printed_vote_discrepancy_with_note(): void
    {
        $record = $this->record(1, 7007, 6122, 3318, 2851);
        $record['status'] = 'needs_review';
        $record['error'] = 'The official report prints 6,169 valid votes but 6,122 voters.';
        $record['source_warning_code'] = 'official_summary_turnout_only';
        $record['summary_source_file'] = 'official-summary.pdf';
        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['summary_page'] = 13;
        $record['summary_totals'] = ['electors' => 7007, 'votes_polled' => 6122, 'valid_candidate_votes' => 6169];

        $summary = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(1, $summary['turnout_review_count']);
        $this->assertSame(6122, $summary['polled']);

        $record['summary_source_sha256'] = '';
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$record])['turnout']);

        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['electors'] = 7006;
        $record['source_discrepancy'] = ['field' => 'electors', 'detail_value' => 7006, 'summary_value' => 7007];
        $summary = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(1, $summary['turnout_discrepancy_count']);
        $this->assertSame(7007, $summary['electors']);
    }

    public function test_official_detail_turnout_can_show_source_total_without_accepting_candidate_rows(): void
    {
        $record = $this->record(1, 195191, 143451, 60704, 53091);
        $record['status'] = 'needs_review';
        $record['error'] = 'Source turnout total confirmed; candidate rows remain under review.';
        $record['source_warning_code'] = 'official_detail_turnout_only';
        $record['turnout_source_file'] = 'official-detail.pdf';
        $record['turnout_source_sha256'] = str_repeat('a', 64);
        $record['turnout_ocr_sha256'] = str_repeat('b', 64);
        $record['turnout_source_page'] = 204;
        $record['turnout_totals'] = ['electors' => 195191, 'general_votes' => 142331,
            'postal_votes' => 1120, 'votes_polled' => 143451];

        $summary = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(1, $summary['turnout_review_count']);
        $this->assertSame(143451, $summary['polled']);
        $this->assertSame(0, $summary['party_count']);
        $this->assertNull($summary['margin']);

        $record['turnout_totals']['votes_polled']--;
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$record])['turnout']);
    }

    public function test_summary_conflict_keeps_source_candidate_margin_visible_with_note(): void
    {
        $record = $this->record(404, 1310007, 837929, 419539, 138038);
        $record['status'] = 'needs_review';
        $record['error'] = 'Summary and detailed totals differ';
        $record['detail_page'] = 149;
        $record['summary_page'] = 404;
        $record['valid_candidate_votes'] = 557577;
        $record['summary_totals'] = ['electors' => 1310007, 'votes_polled' => 837929, 'valid_candidate_votes' => 557567];

        $analytics = app(HistoricalElectionAnalytics::class);
        $this->assertSame(['winner' => 'A', 'party' => 'AAA', 'margin' => 281501, 'derived' => true], $analytics->singleSeatResult($record));
        $summary = $analytics->summarize([$record]);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(1, $summary['margin_review_count']);
        $this->assertSame(281501, $summary['margin']);

        $record['candidates'][1]['votes']++;
        $this->assertNull($analytics->singleSeatResult($record));
        $this->assertNull($analytics->summarize([$record])['margin']);

        $record['candidates'][1]['votes']--;
        $record['error'] = 'Detailed and summary constituency names differ';
        $this->assertNull($analytics->singleSeatResult($record));
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

    public function test_official_workbook_summary_restores_turnout_without_guessing_from_candidate_votes(): void
    {
        $record = $this->record(87, 162019, 135812, 65768, 60599);
        $record['status'] = 'needs_review';
        $record['winner'] = null;
        $record['margin'] = null;
        $record['error'] = 'Official constituency summary confirms voters and candidate votes; its valid-vote total includes NOTA. Publication review pending.';
        $record['source_warning_code'] = 'workbook_pdf_summary';
        $record['summary_source_file'] = 'summary.pdf';
        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['summary_page'] = 87;
        $record['summary_totals'] = ['electors' => 162019, 'votes_polled' => 135812, 'valid_candidate_votes' => 135790];
        $record['reported_totals'] = [['label' => 'Total Votes', 'value' => 135790]];
        $record['candidates'][0]['votes'] = 65768;
        $record['candidates'][1]['votes'] = 60599;
        $record['candidates'][] = ['candidate_name' => 'Other', 'party_at_election' => 'IND', 'votes' => 8238, 'is_nota' => false];
        $record['candidates'][] = ['candidate_name' => 'None of the Above', 'party_at_election' => 'NOTA', 'votes' => 1185, 'is_nota' => true];
        foreach ($record['candidates'] as $index => &$candidate) {
            $candidate['general_votes'] = $candidate['votes'];
            $candidate['postal_votes'] = 0;
            $candidate['source_sheet'] = 'DetailedResult';
            $candidate['workbook_row'] = $index + 858;
        }
        unset($candidate);

        $summary = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(1, $summary['turnout_review_count']);
        $this->assertSame(135812, $summary['polled']);
        $this->assertSame(5169, $summary['margin']);
        $this->assertSame(5169, app(HistoricalElectionAnalytics::class)->singleSeatResult($record)['margin']);

        $record['summary_totals']['votes_polled']++;
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$record])['turnout']);

        $record['summary_totals']['votes_polled']--;
        $record['summary_totals']['valid_candidate_votes'] = 134605;
        $record['summary_totals']['nota_votes'] = 1185;
        $record['reported_totals'][0]['value'] = 136000;
        $record['error'] = 'Official constituency summary confirms voters and candidate votes; the workbook total differs and is preserved for review.';
        $record['source_discrepancy'] = ['field' => 'reported_total', 'workbook_value' => 136000,
            'candidate_sum' => 135790, 'summary_value' => 135790];
        $this->assertSame(135812, app(HistoricalElectionAnalytics::class)->summarize([$record])['polled']);
        $this->assertSame(5169, app(HistoricalElectionAnalytics::class)->singleSeatResult($record)['margin']);

        $record['summary_totals']['nota_votes']++;
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$record])['turnout']);

        $record['summary_totals']['nota_votes']--;
        $record['summary_totals']['valid_candidate_votes']--;
        $record['reported_totals'][0]['value'] = 135790;
        $record['error'] = 'Official constituency summary confirms voters; its valid-vote total differs slightly from the preserved candidate rows. Publication review pending.';
        $record['source_discrepancy'] = ['field' => 'candidate_total', 'candidate_sum' => 135790,
            'summary_value' => 135789, 'difference' => 1];
        $this->assertSame(135812, app(HistoricalElectionAnalytics::class)->summarize([$record])['polled']);
        $this->assertSame(5169, app(HistoricalElectionAnalytics::class)->singleSeatResult($record)['margin']);

        $record['source_discrepancy']['difference'] = 200;
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$record])['turnout']);
    }

    public function test_verified_summary_turnout_survives_detailed_candidate_text_warning(): void
    {
        $record = $this->record(1, 100, 82, 50, 30);
        $record['status'] = 'needs_review';
        $record['error'] = 'Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed.';
        $record['source_warning_code'] = 'summary_turnout_with_detail_warnings';
        $record['detail_page'] = 9;
        $record['summary_page'] = 4;
        $record['summary_totals'] = ['electors' => 100, 'votes_polled' => 82, 'valid_candidate_votes' => 80];
        $record['valid_candidate_votes'] = null;

        $summary = app(HistoricalElectionAnalytics::class)->summarize([$record]);

        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(82, $summary['polled']);
        $this->assertSame(0, $summary['party_count']);
        $this->assertNull(app(HistoricalElectionAnalytics::class)->singleSeatResult($record));

        $record['candidates'][1]['votes']--;
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$record])['turnout']);
    }

    public function test_summary_only_turnout_does_not_publish_incomplete_candidate_metrics(): void
    {
        $record = $this->record(1, 100, 82, 50, 20);
        $record['status'] = 'needs_review';
        $record['error'] = 'Official summary confirms constituency turnout; detailed candidate rows remain unverified. Candidate cells are unreadable.';
        $record['source_warning_code'] = 'summary_only_turnout';
        $record['detail_page'] = 9;
        $record['summary_page'] = 4;
        $record['summary_totals'] = ['electors' => 100, 'votes_polled' => 82, 'valid_candidate_votes' => 80];
        $record['valid_candidate_votes'] = null;

        $summary = app(HistoricalElectionAnalytics::class)->summarize([$record]);

        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(82, $summary['polled']);
        $this->assertSame(0, $summary['party_count']);
        $this->assertNull(app(HistoricalElectionAnalytics::class)->singleSeatResult($record));

        $record['summary_totals']['valid_candidate_votes'] = 83;
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$record])['turnout']);
    }

    public function test_recovered_workbook_rows_use_official_voter_total_with_component_note(): void
    {
        $record = $this->record(87, 100, 82, 50, 30);
        $record['status'] = 'needs_review';
        $record['error'] = 'Official constituency summary confirms electors, voters and candidate votes; the source elector components differ slightly. Publication review pending.';
        $record['source_warning_code'] = 'workbook_pdf_summary';
        $record['source_discrepancy'] = ['field' => 'elector_components', 'component_value' => 99, 'summary_value' => 100];
        $record['summary_source_file'] = 'summary.pdf';
        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['summary_page'] = 87;
        $record['summary_totals'] = ['electors' => 100, 'votes_polled' => 82, 'valid_candidate_votes' => 80];
        $record['reported_totals'] = null;
        foreach ($record['candidates'] as $index => &$candidate) {
            $candidate['general_votes'] = $candidate['votes'];
            $candidate['postal_votes'] = 0;
            $candidate['source_sheet'] = 'DetailedResult';
            $candidate['workbook_row'] = $index + 4;
        }
        unset($candidate);

        $summary = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(82, $summary['polled']);
        $this->assertSame(20, app(HistoricalElectionAnalytics::class)->singleSeatResult($record)['margin']);

        $record['source_discrepancy']['component_value'] = 96;
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$record])['turnout']);
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

    public function test_small_documented_elector_difference_uses_summary_turnout_with_warning(): void
    {
        $record = $this->record(1, 189696, 152927, 90000, 62690);
        $record['status'] = 'needs_review';
        $record['detail_page'] = 300;
        $record['summary_page'] = 25;
        $record['valid_candidate_votes'] = 152690;
        $record['original_extraction_warning'] = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.';
        $record['source_warning_code'] = 'summary_elector_difference';
        $record['source_discrepancy'] = ['field' => 'electors', 'detail_value' => 189696, 'summary_value' => 189698];
        $record['summary_totals'] = ['electors' => 189698, 'votes_polled' => 152927, 'valid_candidate_votes' => 152690];
        $record['error'] = 'Detailed result lists 189,696 electors; official summary lists 189,698. Turnout uses the summary totals; candidate votes match. Review of this difference is pending.';

        $result = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertSame(189698, $result['electors']);
        $this->assertSame(152927, $result['polled']);
        $this->assertSame(1, $result['turnout_discrepancy_count']);
        $this->assertSame(1, $result['party_review_count']);
        $this->assertSame(1, $result['margin_review_count']);

        $record['source_discrepancy']['summary_value']++;
        $result = app(HistoricalElectionAnalytics::class)->summarize([$record]);
        $this->assertNull($result['turnout']);
        $this->assertSame(0, $result['party_count']);

        $record['source_discrepancy']['summary_value']--;
        $record['status'] = 'accepted';
        $this->assertSame(27310, app(HistoricalElectionAnalytics::class)->singleSeatResult($record)['margin']);
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

    public function test_2009_pilibhit_margin_uses_preserved_candidate_rows_with_source_note(): void
    {
        $archive = app(HistoricalElectionArchive::class);
        [$data] = $archive->load('e5346f9160ad32fb68a34578', app(ElectionArchive::class));
        $record = collect($data['records'])->first(fn (array $row): bool => ($row['state_name'] ?? '') === 'Uttar Pradesh'
            && ($row['constituency_name'] ?? '') === 'Pilibhit' && ($row['code'] ?? null) === 404);

        $this->assertNotNull($record);
        $this->assertSame('https://old.eci.gov.in/files/category/98-general-election-2009/', $data['source_url']);
        $this->assertSame(837577, $record['valid_candidate_votes']);
        $this->assertSame(837567, $record['summary_totals']['valid_candidate_votes']);
        $this->assertSame(281501, app(HistoricalElectionAnalytics::class)->singleSeatResult($record)['margin']);
        $this->assertSame(1, app(HistoricalElectionAnalytics::class)->summarize([$record])['margin_review_count']);
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
        $this->assertSame(2, $edition['margin_count']);
        $this->assertNotNull($edition['turnout']);

        $this->get('/india/state/goa?election=pc&edition='.$edition['id'])
            ->assertOk()
            ->assertSee($edition['label'].' †')
            ->assertSee('2 of 2 results have data notes')
            ->assertSee('Turnout in 2 results agrees')
            ->assertSee('Map of Goa')
            ->assertSee('View results and notes');

        $this->get('/india/elections/lok-sabha?edition='.$edition['id'].'&state=Goa')
            ->assertOk()
            ->assertSee('North Goa')
            ->assertSee('South Goa')
            ->assertSee('Summary and detailed totals differ');
    }
}
