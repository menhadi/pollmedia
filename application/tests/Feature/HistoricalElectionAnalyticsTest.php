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

    public function test_officially_uncontested_arunachal_winners_with_nota_rows_are_shown_without_turnout(): void
    {
        $edition = '08d56c7504299ea9043a1782';
        $evidence = json_decode(file_get_contents(database_path('fixtures/official-uncontested-results.json')), true, 512, JSON_THROW_ON_ERROR);
        $results = array_filter($evidence, fn (array $source): bool => $source['source_file'] === $edition.'-9583.pdf');
        $this->assertCount(11, $results);

        $analytics = app(HistoricalElectionAnalytics::class);
        foreach ($results as $key => $source) {
            $record = ['code' => (int) substr($key, 25), 'name' => $source['name'], 'number_of_seats' => 1, 'votes_polled' => null, 'margin' => null, 'candidates' => [
                ['candidate_name' => 'None of the Above', 'party_at_election' => 'NOTA', 'is_nota' => true, 'votes' => null, 'general_votes' => 0, 'postal_votes' => 0],
                ['candidate_name' => $source['candidate'], 'party_at_election' => $source['party'], 'is_nota' => false, 'votes' => null, 'general_votes' => 0, 'postal_votes' => 0],
            ]];

            $this->assertSame(['winner' => $source['candidate'], 'party' => $source['party'], 'margin' => null, 'derived' => false, 'uncontested' => true], $analytics->singleSeatResult($record, $edition));
            $this->assertNull($analytics->summarize([$record])['turnout']);

            $record['candidates'][0]['votes'] = 1;
            $this->assertNull($analytics->singleSeatResult($record, $edition));
            $record['candidates'][0]['votes'] = null;
            $record['candidates'][] = ['candidate_name' => 'Another candidate', 'party_at_election' => 'IND', 'votes' => null];
            $this->assertNull($analytics->singleSeatResult($record, $edition));
        }
    }

    public function test_official_2019_successful_candidates_with_one_contested_nominee_are_uncontested(): void
    {
        $edition = '503520e18f2426da13ccb809';
        $evidence = json_decode(file_get_contents(database_path('fixtures/official-uncontested-results.json')), true, 512, JSON_THROW_ON_ERROR);
        $results = array_filter($evidence, fn (array $source): bool => $source['source_file'] === $edition.'-31359.pdf');
        $this->assertCount(3, $results);

        $analytics = app(HistoricalElectionAnalytics::class);
        foreach ($results as $key => $source) {
            $record = ['code' => (int) substr($key, 25), 'name' => $source['name'], 'number_of_seats' => 1, 'votes_polled' => 0, 'margin' => null, 'candidates' => [
                ['candidate_name' => $source['candidate'], 'party_at_election' => $source['party'], 'votes' => null],
                ['candidate_name' => 'NOTA', 'party_at_election' => 'NOTA', 'is_nota' => true, 'votes' => null],
            ]];

            $this->assertSame(['winner' => $source['candidate'], 'party' => $source['party'], 'margin' => null, 'derived' => false, 'uncontested' => true], $analytics->singleSeatResult($record, $edition));
            $this->assertNull($analytics->summarize([$record])['turnout']);

            $record['candidates'][0]['candidate_name'] = 'Different candidate';
            $this->assertNull($analytics->singleSeatResult($record, $edition));
        }
    }

    public function test_unverified_single_candidate_zero_does_not_become_an_uncontested_winner(): void
    {
        $record = ['code' => 144, 'name' => 'FALTA', 'candidates' => [
            ['candidate_name' => 'Candidate A', 'party_at_election' => 'AAA', 'votes' => 0],
        ]];
        $this->assertNull(app(HistoricalElectionAnalytics::class)->singleSeatResult($record, '43f931b20e1fe26e3e1f72ec'));
    }

    public function test_source_candidate_is_displayable_without_claiming_a_winner(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        $record = ['candidates' => [
            ['candidate_name' => 'Candidate One', 'party_at_election' => 'AAA', 'votes' => null],
            ['candidate_name' => 'None of the Above', 'party_at_election' => 'NOTA', 'votes' => null],
        ]];
        $this->assertSame(['name' => 'Candidate One', 'party' => 'AAA'], $analytics->sourceOnlyCandidate($record));
        $this->assertNull($analytics->singleSeatResult($record));
        $record['candidates'][] = ['candidate_name' => 'Candidate Two', 'party_at_election' => 'BBB', 'votes' => null];
        $this->assertNull($analytics->sourceOnlyCandidate($record));
        $this->assertNull($analytics->sourceOnlyCandidate(['candidates' => [$record['candidates'][1]]]));
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

    public function test_official_pdf_summary_result_is_shown_when_detailed_candidate_rows_are_incomplete(): void
    {
        $record = $this->record(6, 10000, 7000, 4000, 1000);
        $record['status'] = 'needs_review';
        $record['error'] = 'Official summary reports turnout and result; detailed candidate rows remain incomplete.';
        $record['source_warning_code'] = 'official_summary_turnout_only';
        $record['summary_source_file'] = 'official-summary.pdf';
        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['summary_page'] = 22;
        $record['summary_totals'] = ['electors' => 10000, 'votes_polled' => 7000, 'valid_candidate_votes' => 6900];
        $record['summary_result'] = ['winner' => 'A', 'winner_party' => 'SAD', 'winner_votes' => 4000,
            'runner' => 'B', 'runner_party' => 'INC', 'runner_votes' => 2500, 'margin' => 1500];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(0, $summary['party_count']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(['winner' => 'A', 'party' => 'SAD', 'margin' => 1500, 'derived' => false],
            $analytics->singleSeatResult($record));

        $record['summary_result']['margin'] = 1501;
        $this->assertSame(0, $analytics->summarize([$record])['margin_count']);
        $this->assertNull($analytics->singleSeatResult($record));
    }

    public function test_official_summary_polled_total_can_replace_documented_detail_discrepancy(): void
    {
        $record = $this->record(3, 10000, 6990, 4000, 1000);
        $record['status'] = 'needs_review';
        $record['source_warning_code'] = 'official_summary_turnout_only';
        $record['summary_source_file'] = 'assam-1996.pdf';
        $record['summary_source_sha256'] = str_repeat('b', 64);
        $record['summary_page'] = 19;
        $record['valid_candidate_votes'] = 6800;
        $record['summary_totals'] = ['electors' => 10000, 'votes_polled' => 7000, 'valid_candidate_votes' => 6780];
        $record['source_discrepancy'] = ['field' => 'votes_polled', 'detail_value' => 6990,
            'summary_value' => 7000, 'valid_detail_value' => 6800, 'valid_summary_value' => 6780];
        $record['summary_result'] = ['winner' => 'A', 'winner_party' => 'AAA', 'winner_votes' => 4000,
            'runner' => 'B', 'runner_party' => 'BBB', 'runner_votes' => 2500, 'margin' => 1500];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(7000, $summary['polled']);
        $this->assertSame(1, $summary['turnout_discrepancy_count']);
        $this->assertSame(1, $summary['margin_count']);

        $record['source_discrepancy']['summary_value'] = 7001;
        $this->assertNull($analytics->summarize([$record])['turnout']);
        $this->assertNull($analytics->singleSeatResult($record));
    }

    public function test_official_summary_polled_only_difference_keeps_result_visible_with_review(): void
    {
        $record = $this->record(3, 10000, 6993, 4000, 1000);
        $record['status'] = 'needs_review';
        $record['source_warning_code'] = 'official_summary_turnout_only';
        $record['summary_source_file'] = 'west-bengal-1982.pdf';
        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['summary_page'] = 268;
        $record['summary_totals'] = ['electors' => 10000, 'votes_polled' => 7000, 'valid_candidate_votes' => 6800];
        $record['valid_candidate_votes'] = 6800;
        $record['source_discrepancy'] = ['field' => 'votes_polled', 'detail_value' => 6993,
            'summary_value' => 7000, 'valid_detail_value' => 6800, 'valid_summary_value' => 6800];
        $record['summary_result'] = ['winner' => 'A', 'winner_party' => 'AAA', 'winner_votes' => 4000,
            'runner' => 'B', 'runner_party' => 'BBB', 'runner_votes' => 2500, 'margin' => 1500];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(7000, $summary['polled']);
        $this->assertSame(1, $summary['turnout_discrepancy_count']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(['winner' => 'A', 'party' => 'AAA', 'margin' => 1500, 'derived' => false],
            $analytics->singleSeatResult($record));
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

    public function test_official_pdf_result_survives_incomplete_candidate_text_only_with_pdf_provenance(): void
    {
        $record = $this->record(1, 100, 82, 50, 30);
        $record['status'] = 'needs_review';
        $record['error'] = 'Official summary confirms turnout; detailed candidate text needs review.';
        $record['source_warning_code'] = 'summary_turnout_with_detail_warnings';
        $record['summary_page'] = 4;
        $record['summary_source_file'] = 'official.pdf';
        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['summary_totals'] = ['electors' => 100, 'votes_polled' => 82, 'valid_candidate_votes' => 80];
        $record['summary_result'] = ['winner' => 'A', 'winner_party' => 'AAA', 'winner_votes' => 50,
            'runner' => 'B', 'runner_party' => 'BBB', 'runner_votes' => 30, 'margin' => 20];

        $analytics = app(HistoricalElectionAnalytics::class);
        $this->assertSame(1, $analytics->summarize([$record])['margin_count']);
        $this->assertSame('A', $analytics->singleSeatResult($record)['winner']);

        unset($record['summary_source_sha256']);
        $this->assertSame(0, $analytics->summarize([$record])['margin_count']);
        $this->assertNull($analytics->singleSeatResult($record));
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

    public function test_official_summary_can_supply_result_when_detailed_candidate_rows_are_incomplete(): void
    {
        $record = $this->record(1, 100, 82, 50, 20);
        $record['status'] = 'needs_review';
        $record['error'] = 'Official summary confirms constituency turnout; detailed candidate rows remain unverified.';
        $record['source_warning_code'] = 'summary_only_turnout';
        $record['detail_page'] = 9;
        $record['summary_page'] = 4;
        $record['summary_totals'] = ['electors' => 100, 'votes_polled' => 82, 'valid_candidate_votes' => 80];
        $record['summary_source_file'] = 'official.pdf';
        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['summary_result'] = ['winner' => 'A', 'winner_party' => 'AAA', 'winner_votes' => 50,
            'runner' => 'B', 'runner_party' => 'BBB', 'runner_votes' => 30, 'margin' => 20];
        $record['candidates'] = [];

        $analytics = app(HistoricalElectionAnalytics::class);
        $this->assertSame(1, $analytics->summarize([$record])['turnout_count']);
        $this->assertSame(1, $analytics->summarize([$record])['margin_count']);
        $this->assertSame(0, $analytics->summarize([$record])['party_count']);
        $this->assertSame('A', $analytics->singleSeatResult($record)['winner']);

        unset($record['summary_source_sha256']);
        $this->assertSame(0, $analytics->summarize([$record])['margin_count']);
        $this->assertNull($analytics->singleSeatResult($record));
    }

    public function test_bihar_2005_february_and_october_summaries_keep_separate_declared_results(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        $rounds = [
            '2005-feb' => ['faf93ea0918e67d6bc68067a-9224.pdf', '61b09fc09a7d06373b63174ea5cda8f276cec6f2419b1b91415d7c3d8550ca84', 100000],
            '2005-oct' => ['faf93ea0918e67d6bc68067a-9236.pdf', 'ff4f6192840cd830eabbac40ee7f06650e348f12e22b20ea4ee66575c44d963e', 200000],
        ];
        foreach ($rounds as $round => [$file, $sha, $offset]) {
            $record = $this->record($offset + 1, 100, 82, 50, 20);
            $record['name'] = 'Sample / '.$round;
            $record['official_ac_code'] = 1;
            $record['election_round'] = $round;
            $record['number_of_seats'] = 1;
            $record['status'] = 'needs_review';
            $record['error'] = 'Official summary confirms turnout and the declared result; detailed candidate rows remain under review.';
            $record['original_extraction_warning'] = 'Detailed candidate rows need review.';
            $record['previous_review_note'] = 'Official summary confirms turnout.';
            $record['source_warning_code'] = 'round_specific_summary';
            $record['detail_page'] = 9;
            $record['summary_page'] = 4;
            $record['valid_candidate_votes'] = 80;
            $record['summary_totals'] = ['electors' => 100, 'votes_polled' => 82, 'valid_candidate_votes' => 80];
            $record['summary_source_file'] = $file;
            $record['summary_source_sha256'] = $sha;
            $record['summary_result'] = ['winner' => 'A', 'winner_party' => 'AAA', 'winner_votes' => 50,
                'runner' => 'B', 'runner_party' => 'BBB', 'runner_votes' => 30, 'margin' => 20];

            $this->assertSame(['winner' => 'A', 'party' => 'AAA', 'margin' => 20, 'derived' => false],
                $analytics->singleSeatResult($record));
            $this->assertSame(1, $analytics->summarize([$record])['turnout_count']);
            foreach ([
                ['election_round', '2005-other'],
                ['code', 1],
                ['summary_source_sha256', str_repeat('0', 64)],
                ['original_extraction_warning', null],
                ['previous_review_note', null],
            ] as [$field, $value]) {
                $altered = $record;
                $altered[$field] = $value;
                $this->assertNull($analytics->singleSeatResult($altered));
            }
        }
    }

    public function test_official_arunachal_result_is_visible_when_2004_report_omits_voter_total(): void
    {
        $record = $this->record(22, 100, 82, 50, 30);
        $record['status'] = 'needs_review';
        $record['votes_polled'] = null;
        $record['valid_candidate_votes'] = 80;
        $record['source_warning_code'] = 'official_summary_result_without_turnout';
        $record['original_extraction_warning'] = 'Detailed candidate rows need review.';
        $record['previous_review_note'] = 'Voter total is absent from the official summary.';
        $record['summary_source_file'] = 'dc469be7915c7a5a9e699aac-9579.pdf';
        $record['summary_source_sha256'] = 'a6a2d830c8969fc7364cd70457593b46bc5edf5b854284bae52668ee9d05f76b';
        $record['summary_page'] = 34;
        $record['summary_totals'] = ['electors' => 100, 'votes_polled' => null, 'valid_candidate_votes' => 80];
        $record['summary_result'] = ['winner' => 'A', 'winner_party' => 'AAA', 'winner_votes' => 50,
            'runner' => 'B', 'runner_party' => 'BBB', 'runner_votes' => 30, 'margin' => 20];
        $analytics = app(HistoricalElectionAnalytics::class);

        $this->assertSame(['winner' => 'A', 'party' => 'AAA', 'margin' => 20, 'derived' => false],
            $analytics->singleSeatResult($record));
        $summary = $analytics->summarize([$record]);
        $this->assertSame(0, $summary['turnout_count']);
        $this->assertSame(1, $summary['margin_count']);
        foreach ([
            ['code', 21],
            ['summary_page', 35],
            ['summary_source_sha256', str_repeat('0', 64)],
            ['votes_polled', 80],
            ['original_extraction_warning', null],
            ['previous_review_note', null],
        ] as [$field, $value]) {
            $altered = $record;
            $altered[$field] = $value;
            $this->assertNull($analytics->singleSeatResult($altered));
        }
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

        $record['summary_source_file'] = 'official.pdf';
        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['summary_result'] = ['winner' => 'Declared A', 'winner_party' => 'AAA',
            'winner_votes' => 90000, 'runner' => 'Declared B', 'runner_party' => 'BBB',
            'runner_votes' => 62690, 'margin' => 27310];
        $this->assertSame('Declared A', app(HistoricalElectionAnalytics::class)->singleSeatResult($record)['winner']);
        unset($record['summary_source_sha256']);
        $this->assertNotSame('Declared A', app(HistoricalElectionAnalytics::class)->singleSeatResult($record)['winner'] ?? null);

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
            $mock->shouldReceive('forState')->with('Uttar Pradesh', 'ac')->andReturn([]);
        });
        $this->get('/india/state/uttar-pradesh')->assertOk()->assertSee('Lok Sabha voting history')->assertDontSee('Assembly voting history')->assertSee('Mean winning margin')->assertSee('Registered electors and votes polled')->assertSee('2022 Lok Sabha results')->assertSee('80.00%')->assertSee('Party vote shares')->assertDontSee('Go deeper into')->assertSee('2022 report')->assertSee('data-sortable', false)->assertSee('data-history-chart')->assertSee('pc-results')->assertSee('ac-results')->assertSee('Uttar Pradesh PC constituency map')->assertSeeInOrder(['>Lok Sabha</a>', '>State Assembly</a>'], false);
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
            $mock->shouldReceive('forState')->with('Assam', 'pc')->andReturn([]);
        });

        $this->get('/india/state/assam?election=ac')
            ->assertOk()->assertSee('Assembly voting history')->assertDontSee('Lok Sabha voting history')
            ->assertSee('2021 report')
            ->assertSee('2011 report')
            ->assertSee('80.00%')
            ->assertSee('2 candidate rows')
            ->assertSee('View candidate tables →')
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

    public function test_puranpur_1996_preserves_documented_detail_and_summary_turnout_difference(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('1cc8415ab4d57b66831417e8', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 60);
        $this->assertSame('PURANPUR', $record['name']);

        $analytics = app(HistoricalElectionAnalytics::class);
        $summary = $analytics->summarize([$record]);
        $this->assertSame(170352, $summary['polled']);
        $this->assertSame(1, $summary['turnout_review_count']);
        $this->assertSame(1, $summary['turnout_discrepancy_count']);
        $this->assertSame(4452, $analytics->singleSeatResult($record)['margin']);

        $record['summary_totals']['votes_polled']++;
        $this->assertNull($analytics->summarize([$record])['polled']);
    }

    public function test_puranpur_2007_shows_source_result_with_small_documented_total_difference(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('174ec81b511a8fb1aeca553f', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 44);
        $this->assertSame('PURANPUR', $record['name']);

        $analytics = app(HistoricalElectionAnalytics::class);
        $summary = $analytics->summarize([$record]);
        $this->assertSame(170064, $summary['polled']);
        $this->assertSame(1, $summary['turnout_review_count']);
        $this->assertSame(1, $summary['turnout_discrepancy_count']);
        $this->assertSame(['winner' => 'ARSHAD KHAN', 'party' => 'BSP', 'margin' => 6267, 'derived' => true], $analytics->singleSeatResult($record));

        $record['candidates'][1]['votes']++;
        $this->assertNull($analytics->singleSeatResult($record));
        $record['candidates'][1]['votes']--;
        $record['summary_page'] = null;
        $this->assertNull($analytics->summarize([$record])['polled']);
        $this->assertNull($analytics->singleSeatResult($record));
    }

    public function test_2019_and_2020_official_workbook_summary_turnout_survives_candidate_warnings(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        foreach ([['267cd82b74f76a034f14dc7b', 4, 217768, 11335],
            ['eedecf943f2ac2ddf717246e', 1, 195791, 21585],
            ['eedecf943f2ac2ddf717246e', 184, 188259, 18300]] as [$edition, $code, $polled, $margin]) {
            [$data] = app(HistoricalElectionArchive::class)->load($edition, app(ElectionArchive::class));
            $record = collect($data['records'])->firstWhere('code', $code);
            $this->assertSame($polled, $analytics->summarize([$record])['polled']);
            $this->assertSame(1, $analytics->summarize([$record])['turnout_review_count']);
            $this->assertSame($margin, $analytics->summarize([$record])['margin']);
            $this->assertSame(0, $analytics->summarize([$record])['party_count']);

            $record['summary_source_rows'] = [];
            $this->assertNull($analytics->summarize([$record])['polled']);
            $this->assertNull($analytics->summarize([$record])['margin']);

            $record = collect($data['records'])->firstWhere('code', $code);
            $record['constituency_name'] = 'Different seat';
            $this->assertNull($analytics->summarize([$record])['polled']);

            $record = collect($data['records'])->firstWhere('code', $code);
            $record['summary_totals']['valid_candidate_votes'] = $record['votes_polled'] + 1;
            $this->assertNull($analytics->summarize([$record])['margin']);

            $record = collect($data['records'])->firstWhere('code', $code);
            $record['winner'] = 'Different candidate';
            $this->assertNull($analytics->summarize([$record])['margin']);
        }
    }

    public function test_2024_small_official_detail_and_summary_turnout_difference_is_shown_with_note(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('349e04305ee4652986f79497', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 32);
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(1602455, $summary['polled']);
        $this->assertSame(1, $summary['turnout_review_count']);
        $this->assertSame(1, $summary['turnout_discrepancy_count']);

        $record['summary_totals']['votes_polled'] += 10;
        $this->assertNull($analytics->summarize([$record])['polled']);
    }

    public function test_1960_kerala_detail_turnout_survives_candidate_text_warning_without_accepting_candidates(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('c7c15b329cbd2123a34e5b3e', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 1);
        $this->assertSame('PARASSALA', $record['name']);
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(52975, $summary['polled']);
        $this->assertSame(1, $summary['turnout_detail_count']);
        $this->assertSame(0, $summary['party_count']);

        $record['error'] .= '; Reported elector and voter totals are inconsistent.';
        $this->assertNull($analytics->summarize([$record])['polled']);
    }

    public function test_official_page_turnout_is_shown_when_imported_evidence_matches(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        $record = [
            'code' => 4,
            'name' => 'Anjar',
            'number_of_seats' => 1,
            'status' => 'needs_review',
            'error' => 'Official source prints the constituency turnout total; previous candidate/source warnings remain available for review.',
            'source_warning_code' => 'official_turnout_from_residual_source',
            'electors' => 191018,
            'votes_polled' => 137376,
            'turnout_totals' => ['electors' => 191018, 'votes_polled' => 137376, 'source_page' => 205, 'method' => 'visual transcription of official scanned turnout row; OCR geometry verified'],
            'turnout_source_page' => 205,
            'turnout_source_file' => '503135d3e838d38c93d3bce7-9045.pdf',
            'turnout_source_sha256' => str_repeat('a', 64),
            'candidates' => [],
        ];

        $summary = $analytics->summarize([$record]);
        $this->assertSame(137376, $summary['polled']);
        $this->assertSame(1, $summary['turnout_review_count']);
        $this->assertSame(0, $summary['party_count']);

        $changed = $record;
        $changed['turnout_totals']['votes_polled']++;
        $this->assertNull($analytics->summarize([$changed])['polled']);
        $changed = $record;
        $changed['turnout_source_page']++;
        $this->assertNull($analytics->summarize([$changed])['polled']);
        $changed = $record;
        $changed['turnout_source_sha256'] = '';
        $this->assertNull($analytics->summarize([$changed])['polled']);
        $changed = $record;
        $changed['error'] = 'Different warning';
        $this->assertNull($analytics->summarize([$changed])['polled']);

        $record['source_warning_code'] = 'official_detailed_pdf_turnout';
        $record['error'] = 'Official detailed-result PDF prints turnout. Original extraction and candidate warnings remain available for review.';
        $record['turnout_totals']['method'] = 'official detailed-result PDF turnout row; candidates reconcile';
        $this->assertSame(137376, $analytics->summarize([$record])['polled']);
    }

    public function test_reconciled_workbook_rows_and_official_pdf_turnout_show_reviewed_result(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        $candidate = static function (int $workbookRow, string $name, string $party, int $general, int $postal, bool $nota = false): array {
            $votes = $general + $postal;

            return ['candidate_name' => $name, 'party_at_election' => $party,
                'general_votes' => $general, 'postal_votes' => $postal, 'votes' => $votes,
                'is_nota' => $nota, 'source_sheet' => 'DetailedResult', 'workbook_row' => $workbookRow,
                'source_values' => [11, 'Sagolband ', $name, null, null, null, $party,
                    $general, $postal, $votes, 23064, 19567, null]];
        };
        $record = [
            'code' => 11, 'name' => 'Sagolband', 'number_of_seats' => 1,
            'status' => 'needs_review',
            'error' => 'Official detailed-result PDF prints turnout. Original extraction and candidate warnings remain available for review.',
            'source_warning_code' => 'official_detailed_pdf_turnout',
            'electors' => 23064, 'votes_polled' => 19567, 'valid_candidate_votes' => 19416,
            'turnout_totals' => ['electors' => 23064, 'votes_polled' => 19567,
                'general_votes' => 19283, 'postal_votes' => 284, 'source_page' => 3,
                'method' => 'official detailed-result PDF turnout row; candidates reconcile'],
            'turnout_source_page' => 3, 'turnout_source_file' => 'official.pdf',
            'turnout_source_sha256' => str_repeat('a', 64),
            'candidates' => [
                $candidate(57, 'RAJKUMAR IMO SINGH', 'INC', 9056, 155),
                $candidate(58, 'DR. KHWAIRAKPAM LOKEN SINGH', 'BJP', 9066, 126),
                $candidate(59, 'G. SATYABATI DEVI', 'NPEP', 980, 2),
                $candidate(60, 'None of the Above', 'NOTA', 151, 0, true),
                $candidate(61, 'LAISHRAM GYANESHWAR', 'IND', 30, 1),
            ],
        ];

        $this->assertSame(['winner' => 'RAJKUMAR IMO SINGH', 'party' => 'INC',
            'margin' => 19, 'derived' => true], $analytics->singleSeatResult($record));
        $summary = $analytics->summarize([$record]);
        $this->assertSame(19567, $summary['polled']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(1, $summary['party_count']);

        $changed = $record;
        $changed['candidates'][1]['source_values'][9]++;
        $this->assertNull($analytics->singleSeatResult($changed));
        $this->assertSame(0, $analytics->summarize([$changed])['margin_count']);
        $changed = $record;
        $changed['turnout_totals']['postal_votes']++;
        $this->assertNull($analytics->singleSeatResult($changed));
        $changed = $record;
        $changed['number_of_seats'] = 2;
        $this->assertNull($analytics->singleSeatResult($changed));
    }

    public function test_reconciled_official_detail_can_show_a_reviewed_winner_and_margin(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        $record = [
            'code' => 288, 'name' => 'Satyavedu (SC)', 'number_of_seats' => 1,
            'status' => 'needs_review',
            'error' => 'Official source prints the constituency turnout total; previous candidate/source warnings remain available for review.',
            'source_warning_code' => 'official_turnout_from_residual_source',
            'electors' => 1000, 'votes_polled' => 800, 'valid_candidate_votes' => 750,
            'detail_page' => 42,
            'turnout_totals' => ['electors' => 1000, 'votes_polled' => 800, 'general_votes' => 790,
                'postal_votes' => 10, 'source_page' => 42, 'method' => 'official detailed turnout row'],
            'turnout_source_page' => 42, 'turnout_source_file' => 'official.pdf',
            'turnout_source_sha256' => str_repeat('a', 64),
            'candidates' => [
                ['source_row' => 1, 'source_page' => 42, 'candidate_name' => 'First', 'party_at_election' => 'A',
                    'general_votes' => 395, 'postal_votes' => 5, 'votes' => 400, 'is_nota' => false],
                ['source_row' => 2, 'source_page' => 42, 'candidate_name' => 'Second', 'party_at_election' => 'B',
                    'general_votes' => 345, 'postal_votes' => 5, 'votes' => 350, 'is_nota' => false],
                ['source_row' => 3, 'source_page' => 42, 'candidate_name' => 'None of the Above', 'party_at_election' => 'NOTA',
                    'general_votes' => 50, 'postal_votes' => 0, 'votes' => 50, 'is_nota' => true],
            ],
            'official_detail_result' => ['winner' => 'First', 'winner_party' => 'A', 'winner_votes' => 400,
                'runner' => 'Second', 'runner_party' => 'B', 'runner_votes' => 350, 'margin' => 50,
                'source_page' => 42, 'source_file' => 'official.pdf', 'source_sha256' => str_repeat('a', 64)],
        ];

        $this->assertSame(1, $analytics->summarize([$record])['turnout_count']);
        $this->assertSame(1, $analytics->summarize([$record])['margin_count']);
        $this->assertSame(1, $analytics->summarize([$record])['party_count']);
        $this->assertSame('First', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame(50, $analytics->singleSeatResult($record)['margin']);

        $changed = $record;
        $changed['candidates'][0]['votes']++;
        $this->assertSame(0, $analytics->summarize([$changed])['margin_count']);
        $this->assertSame(0, $analytics->summarize([$changed])['party_count']);
        $this->assertNull($analytics->singleSeatResult($changed));
        $changed = $record;
        $changed['official_detail_result']['source_sha256'] = str_repeat('b', 64);
        $this->assertNull($analytics->singleSeatResult($changed));

        $split = $record;
        $split['turnout_source_page'] = 43;
        $split['turnout_totals']['source_page'] = 43;
        $split['candidates'][2]['source_page'] = 43;
        $split['official_detail_result']['source_pages'] = [42, 43];
        $this->assertSame(50, $analytics->singleSeatResult($split)['margin']);

        $changed = $split;
        unset($changed['official_detail_result']['source_pages']);
        $this->assertNull($analytics->singleSeatResult($changed));
        $changed = $split;
        $changed['official_detail_result']['source_pages'] = [42, 44];
        $this->assertNull($analytics->singleSeatResult($changed));
        $changed = $split;
        $changed['candidates'][1]['source_page'] = 43;
        $changed['candidates'][2]['source_page'] = 42;
        $this->assertNull($analytics->singleSeatResult($changed));

        $duplicatePreview = $record;
        $duplicatePreview['detail_page'] = 16;
        $duplicatePreview['turnout_source_page'] = 107;
        $duplicatePreview['turnout_totals']['source_page'] = 107;
        $duplicatePreview['valid_candidate_votes'] = null;
        foreach ($duplicatePreview['candidates'] as &$candidate) {
            $candidate['source_page'] = 107;
        }
        unset($candidate);
        $previewRow = $duplicatePreview['candidates'][0];
        $previewRow['source_page'] = 16;
        $previewRow['general_votes'] = null;
        array_unshift($duplicatePreview['candidates'], $previewRow);
        $duplicatePreview['official_detail_result']['source_page'] = 107;
        $duplicatePreview['official_detail_result']['duplicate_preview_page'] = 16;
        $duplicatePreview['official_detail_result']['verified_valid_candidate_votes'] = 750;
        $this->assertSame(50, $analytics->singleSeatResult($duplicatePreview)['margin']);
        $this->assertSame(1, $analytics->summarize([$duplicatePreview])['margin_count']);
        $this->assertSame(0, $analytics->summarize([$duplicatePreview])['party_count']);
        $changed = $duplicatePreview;
        $changed['candidates'][0]['votes']++;
        $this->assertNull($analytics->singleSeatResult($changed));
        $this->assertSame(0, $analytics->summarize([$changed])['margin_count']);
        $changed = $duplicatePreview;
        unset($changed['official_detail_result']['duplicate_preview_page']);
        $this->assertNull($analytics->singleSeatResult($changed));
        $changed = $duplicatePreview;
        $changed['official_detail_result']['verified_valid_candidate_votes']++;
        $this->assertNull($analytics->singleSeatResult($changed));
    }

    public function test_1996_pc_detailed_result_can_be_shown_with_review_note_but_not_without_matching_evidence(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('35f16085183f0c8bd7ef6124', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 43);
        $this->assertSame('Matching state/constituency summary is unavailable; Independent summary totals could not be reconciled', $record['error']);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'Official detailed result prints turnout and candidate votes; no independent constituency summary was available. Review the official PDF.';
        $record['source_warning_code'] = 'official_pc_detailed_result_verified';
        $record['detail_source_file'] = $data['source_file'];
        $record['detail_source_sha256'] = $data['source_sha256'];
        $record['detail_verified_totals'] = ['electors' => 311147, 'votes_polled' => 169788,
            'valid_candidate_votes' => 166591, 'source_page' => 217,
            'method' => 'official detailed-result PDF; top candidate rows and totals checked'];
        $record['detail_verified_result'] = ['winner' => 'TOMO RIBA', 'winner_party' => 'IND', 'winner_votes' => 88718,
            'runner' => 'P.K. THUNGON', 'runner_party' => 'INC', 'runner_votes' => 49102, 'margin' => 39616];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(169788, $summary['polled']);
        $this->assertSame(1, $summary['turnout_review_count']);
        $this->assertSame(1, $summary['party_count']);
        $this->assertSame(39616, $analytics->singleSeatResult($record)['margin']);

        foreach (['detail_source_sha256', 'original_extraction_warning', 'detail_verified_totals', 'detail_verified_result', 'number_of_seats'] as $field) {
            $changed = $record;
            $changed[$field] = null;
            $this->assertNull($analytics->summarize([$changed])['polled'], $field);
            $this->assertNull($analytics->singleSeatResult($changed), $field);
        }
        $changed = $record;
        $changed['candidates'][0]['votes']++;
        $this->assertNull($analytics->summarize([$changed])['polled']);
        $changed = $record;
        $changed['candidates'][] = $record['candidates'][0];
        $this->assertNull($analytics->singleSeatResult($changed));
    }

    public function test_2014_pc_summary_turnout_is_available_despite_duplicate_candidate_identities(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('3a136496a89deb7c38ecfe18', app(ElectionArchive::class));
        $analytics = app(HistoricalElectionAnalytics::class);
        foreach ([323 => 1089771, 508 => 1348744, 537 => 1090583, 541 => 1131254] as $code => $polled) {
            $record = collect($data['records'])->firstWhere('code', $code);
            $summary = $analytics->summarize([$record]);
            $this->assertSame($polled, $summary['polled']);
            $this->assertSame(1, $summary['turnout_review_count']);
            $this->assertSame(0, $summary['party_count']);

            $record['summary_locator'] = 'Another constituency';
            $this->assertNull($analytics->summarize([$record])['polled']);
        }
    }

    public function test_2014_pc_official_summary_result_is_shown_without_accepting_repeated_candidate_names(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('3a136496a89deb7c38ecfe18', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 508);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'Some separate candidate rows share a name. Candidate votes, including NOTA, reconcile with the official summary, which confirms turnout, winner and margin; candidate identities remain under review.';
        $record['summary_result_source_sheet'] = 'U05-WEST DELHI                ';
        $record['summary_result_source_file'] = '51512f85716a7b6349923689-6469.xlsx';
        $record['summary_result_source_sha256'] = str_repeat('a', 64);
        $record['summary_totals']['nota_votes'] = 7932;
        $record['summary_candidate_count'] = 17;
        $record['summary_result'] = ['winner' => 'Parvesh Sahib Singh Verma', 'winner_party' => 'BJP', 'winner_votes' => 651395,
            'runner' => 'Jarnail Singh', 'runner_party' => 'AAAP', 'runner_votes' => 382809, 'margin' => 268586];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(1348744, $summary['polled']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(0, $summary['party_count']);
        $this->assertSame(['winner' => 'Parvesh Sahib Singh Verma', 'party' => 'BJP', 'margin' => 268586, 'derived' => false],
            $analytics->singleSeatResult($record));

        $record['summary_result_source_sha256'] = '';
        $this->assertNull($analytics->singleSeatResult($record));
        $record['summary_result_source_sha256'] = str_repeat('a', 64);
        $record['summary_result']['runner_votes']++;
        $this->assertNull($analytics->singleSeatResult($record));
    }

    public function test_2009_source_state_heading_makes_goa_lok_sabha_tables_available(): void
    {
        $edition = collect(app(HistoricalElectionAnalytics::class)->forState('Goa', 'pc'))->firstWhere('year', 2009);

        $this->assertNotNull($edition);
        $this->assertSame('Goa', $edition['state']);
        $this->assertSame(2, $edition['tables']);
        $this->assertCount(2, $edition['constituency_results']);
        $this->assertTrue($edition['constituency_results'][0]['has_warning']);
        $this->assertNotNull($edition['constituency_results'][0]['result']);
        $this->assertSame(2, $edition['review_count']);
        $this->assertSame(2, $edition['turnout_count']);
        $this->assertSame(2, $edition['turnout_review_count']);
        $this->assertSame(2, $edition['margin_count']);
        $this->assertNotNull($edition['turnout']);

        $this->get('/india/state/goa?election=pc&edition='.$edition['id'])
            ->assertOk()
            ->assertSee($edition['label'])
            ->assertSee('This election includes records with data notes.')
            ->assertSee('† marks figures with source notes.')
            ->assertSee('Goa · Lok Sabha map')
            ->assertSee('View results and notes');

        $this->get('/india/elections/lok-sabha?edition='.$edition['id'].'&state=Goa')
            ->assertOk()
            ->assertSee('North Goa')
            ->assertSee('South Goa')
            ->assertSee('Summary and detailed totals differ');
    }

    public function test_official_1971_tamil_nadu_results_are_shown_without_impossible_turnout(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('7a130d7480f6fd17a797d5aa', app(ElectionArchive::class));
        $analytics = app(HistoricalElectionAnalytics::class);
        foreach ([
            152 => ['page' => 166, 'electors' => 55108, 'polled' => 74732, 'valid' => 70623,
                'winner' => 'J. S. RAJU', 'party' => 'DMK', 'winner_votes' => 39043,
                'runner' => 'K. PERIYANAN', 'runner_party' => 'NCO', 'runner_votes' => 23335, 'margin' => 15708],
            195 => ['page' => 209, 'electors' => 58857, 'polled' => 75258, 'valid' => 73482,
                'winner' => 'MALAIKANNAN V.', 'party' => 'DMK', 'winner_votes' => 45551,
                'runner' => 'RAMAKRISHNA THEVAR S.', 'runner_party' => 'NCO', 'runner_votes' => 24138, 'margin' => 21413],
        ] as $code => $expected) {
            $record = collect($data['records'])->firstWhere('code', $code);
            $record['original_extraction_warning'] = $record['error'];
            $record['error'] = 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.';
            $record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout';
            $record['official_summary_state'] = 'Tamil Nadu';
            $record['official_source_url'] = $data['source_url'];
            $record['summary_source_file'] = $data['source_file'];
            $record['summary_source_sha256'] = $data['source_sha256'];
            $record['summary_page'] = $expected['page'];
            $record['summary_totals'] = ['electors' => $expected['electors'],
                'votes_polled' => $expected['polled'], 'valid_candidate_votes' => $expected['valid']];
            $record['summary_result'] = ['winner' => $expected['winner'], 'winner_party' => $expected['party'],
                'winner_votes' => $expected['winner_votes'], 'runner' => $expected['runner'],
                'runner_party' => $expected['runner_party'], 'runner_votes' => $expected['runner_votes'],
                'margin' => $expected['margin']];

            $summary = $analytics->summarize([$record]);
            $this->assertNull($summary['turnout']);
            $this->assertNull($summary['polled']);
            $this->assertSame(1, $summary['margin_count']);
            $this->assertSame(['winner' => $expected['winner'], 'party' => $expected['party'],
                'margin' => $expected['margin'], 'derived' => false], $analytics->singleSeatResult($record));

            $altered = $record;
            $altered['summary_result']['winner_votes']++;
            $this->assertNull($analytics->singleSeatResult($altered));
        }
    }

    public function test_official_1971_deganga_summary_shows_result_and_documented_turnout(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('fed20e0bd380929811cd1a1f', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 84);
        $record['original_extracted_totals'] = ['electors' => 74781, 'votes_polled' => 47151, 'valid_candidate_votes' => 43369];
        $record['original_extraction_warning'] = $record['error'];
        $record['votes_polled'] = 46831;
        $record['error'] = 'The official summary reports 46,831 votes polled and declares the winner. The detailed table prints 47,151 and repeats a 320-vote candidate row; review the linked report.';
        $record['source_warning_code'] = 'summary_only_turnout';
        $record['source_discrepancy'] = ['field' => 'votes_polled', 'detail_value' => 47151,
            'summary_value' => 46831, 'difference' => 320];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_page'] = 100;
        $record['summary_totals'] = ['electors' => 74781, 'votes_polled' => 46831, 'valid_candidate_votes' => 43369];
        $record['summary_result'] = ['winner' => 'HARUN OP RASHID', 'winner_party' => 'IND', 'winner_votes' => 20142,
            'runner' => 'M. SAWKFTALI', 'runner_party' => 'INC', 'runner_votes' => 9191, 'margin' => 10951];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(46831, $summary['polled']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(['winner' => 'HARUN OP RASHID', 'party' => 'IND', 'margin' => 10951, 'derived' => false],
            $analytics->singleSeatResult($record));

        $altered = $record;
        $altered['summary_result']['winner_votes']++;
        $this->assertNull($analytics->singleSeatResult($altered));
    }

    public function test_official_1962_mahad_tie_uses_declared_winner_and_zero_margin(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('c6fecebc52d31001b62978a5', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 43);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'The official summary declares a winner after equal candidate votes; the winning margin is zero. Check the linked report.';
        $record['source_warning_code'] = 'official_declared_tie';
        $record['official_source_url'] = $data['source_url'];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_page'] = 61;
        $record['summary_totals'] = ['electors' => 58162, 'votes_polled' => 36311, 'valid_candidate_votes' => 34013];
        $record['summary_result'] = ['winner' => 'SHANKAR BABAJI SAWANT', 'winner_party' => 'INC', 'winner_votes' => 12664,
            'runner' => 'SAKHARAM VITHOBA SALUNKE', 'runner_party' => 'PSP', 'runner_votes' => 12664, 'margin' => 0];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(0, $summary['margin']);
        $this->assertSame(['winner' => 'SHANKAR BABAJI SAWANT', 'party' => 'INC', 'margin' => 0, 'derived' => false],
            $analytics->singleSeatResult($record));

        $altered = $record;
        $altered['summary_result']['winner_votes']++;
        $this->assertNull($analytics->singleSeatResult($altered));
    }

    public function test_official_1988_kherapara_tie_uses_declared_winner_and_zero_margin(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('59e71c23b10c39b1a339d4c9', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 54);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'The official summary declares a winner after equal candidate votes; the winning margin is zero. Check the linked report.';
        $record['source_warning_code'] = 'official_declared_tie';
        $record['official_source_url'] = $data['source_url'];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_page'] = 65;
        $record['summary_totals'] = ['electors' => 12209, 'votes_polled' => 8947, 'valid_candidate_votes' => 8623];
        $record['summary_result'] = ['winner' => 'CHAMBERUN MARAK', 'winner_party' => 'IND', 'winner_votes' => 2591,
            'runner' => 'ROSTER M. SANGMA', 'runner_party' => 'INC', 'runner_votes' => 2591, 'margin' => 0];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertSame(1, $summary['turnout_count']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(0, $summary['margin']);
        $this->assertSame(['winner' => 'CHAMBERUN MARAK', 'party' => 'IND', 'margin' => 0, 'derived' => false],
            $analytics->singleSeatResult($record));

        foreach ([['summary_result', ['winner' => 'ROSTER M. SANGMA']],
            ['summary_source_sha256', str_repeat('0', 64)], ['number_of_seats', 2]] as [$field, $value]) {
            $altered = $record;
            $altered[$field] = $value;
            $this->assertNull($analytics->singleSeatResult($altered));
        }
    }

    public function test_official_1955_sattenpalli_result_is_shown_without_impossible_turnout(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('165392d9f968ef073166ef32', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 96);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.';
        $record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout';
        $record['official_summary_state'] = 'Andhra Pradesh';
        $record['official_source_url'] = $data['source_url'];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_page'] = 109;
        $record['summary_totals'] = ['electors' => 2473, 'votes_polled' => 40566, 'valid_candidate_votes' => 40566];
        $record['summary_result'] = ['winner' => 'VAVILAL GOPALKRISHNAIAH', 'winner_party' => 'CPI', 'winner_votes' => 19893,
            'runner' => 'BANDARU VANDANAM', 'runner_party' => 'INC', 'runner_votes' => 19018, 'margin' => 875];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertNull($summary['turnout']);
        $this->assertNull($summary['polled']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(['winner' => 'VAVILAL GOPALKRISHNAIAH', 'party' => 'CPI', 'margin' => 875, 'derived' => false],
            $analytics->singleSeatResult($record));

        $altered = $record;
        $altered['summary_result']['winner_votes']++;
        $this->assertNull($analytics->singleSeatResult($altered));
    }

    public function test_official_1951_kanpur_result_is_shown_without_impossible_turnout(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('402db61ff727c908b4ac3170', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 130);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.';
        $record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout';
        $record['official_summary_state'] = 'Uttar Pradesh';
        $record['official_source_url'] = $data['source_url'];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_result'] = ['winner' => 'SURYA PRASAD AWASTHI', 'winner_party' => 'INC', 'winner_votes' => 12158,
            'runner' => 'RAJA RAM SHASTRI', 'runner_party' => 'SP', 'runner_votes' => 11104, 'margin' => 1054];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertNull($summary['turnout']);
        $this->assertNull($summary['polled']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(['winner' => 'SURYA PRASAD AWASTHI', 'party' => 'INC', 'margin' => 1054, 'derived' => false],
            $analytics->singleSeatResult($record));

        $altered = $record;
        $altered['summary_result']['winner_votes']++;
        $this->assertNull($analytics->singleSeatResult($altered));
    }

    public function test_official_1969_declared_result_is_shown_without_impossible_turnout(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('7ce40cf47befc2b48ff776e3', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 315);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.';
        $record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout';
        $record['official_summary_state'] = 'Uttar Pradesh';
        $record['official_source_url'] = $data['source_url'];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_result'] = ['winner' => 'JAGDISHWAR DAYAL', 'winner_party' => 'INC', 'winner_votes' => 22690,
            'runner' => 'RAM PRAKASH TRIPATHI', 'runner_party' => 'BJS', 'runner_votes' => 18485, 'margin' => 4205];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertNull($summary['turnout']);
        $this->assertNull($summary['polled']);
        $this->assertSame(0, $summary['party_count']);
        $this->assertSame(1, $summary['margin_count']);
        $this->assertSame(4205, $summary['margin']);
        $this->assertSame(['winner' => 'JAGDISHWAR DAYAL', 'party' => 'INC', 'margin' => 4205, 'derived' => false],
            $analytics->singleSeatResult($record));

        foreach ([
            ['summary_source_sha256', str_repeat('0', 64)],
            ['number_of_seats', 2],
            ['valid_candidate_votes', 78014],
        ] as [$field, $value]) {
            $altered = $record;
            $altered[$field] = $value;
            $this->assertNull($analytics->singleSeatResult($altered));
            $this->assertSame(0, $analytics->summarize([$altered])['margin_count']);
        }
        $altered = $record;
        $altered['summary_result']['winner_votes']++;
        $this->assertNull($analytics->singleSeatResult($altered));
        $altered = $record;
        $altered['candidates'][0]['votes']++;
        $this->assertNull($analytics->singleSeatResult($altered));
    }

    public function test_official_1957_hata_result_is_shown_without_impossible_turnout(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('d31cb3f180e44ec4b9e59209', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 234);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.';
        $record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout';
        $record['official_summary_state'] = 'Uttar Pradesh';
        $record['official_source_url'] = $data['source_url'];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_result'] = ['winner' => 'SURYA BALI', 'winner_party' => 'INC', 'winner_votes' => 11915,
            'runner' => 'BANKEY LAL', 'runner_party' => 'PSP', 'runner_votes' => 11161, 'margin' => 754];
        $analytics = app(HistoricalElectionAnalytics::class);

        $this->assertSame(['winner' => 'SURYA BALI', 'party' => 'INC', 'margin' => 754, 'derived' => false],
            $analytics->singleSeatResult($record));
        $summary = $analytics->summarize([$record]);
        $this->assertSame(0, $summary['turnout_count']);
        $this->assertSame(1, $summary['margin_count']);
        foreach ([
            ['summary_source_sha256', str_repeat('0', 64)],
            ['summary_page', 255],
            ['number_of_seats', 2],
            ['valid_candidate_votes', 30961],
        ] as [$field, $value]) {
            $altered = $record;
            $altered[$field] = $value;
            $this->assertNull($analytics->singleSeatResult($altered));
        }
    }

    public function test_official_1957_sausar_result_is_shown_without_impossible_turnout(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('6dfd6b3caf24c34e288769cf', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 116);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.';
        $record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout';
        $record['official_summary_state'] = 'Madhya Pradesh';
        $record['official_source_url'] = $data['source_url'];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_page'] = 134;
        $record['summary_totals'] = ['electors' => 52018, 'votes_polled' => 96630, 'valid_candidate_votes' => 96630];
        $record['summary_result'] = ['winner' => 'RAICHANDBHAI NARSIBHAI', 'winner_party' => 'INC', 'winner_votes' => 25497,
            'runner' => 'RANCHUSINGH DOMAJI (ST)', 'runner_party' => 'INC', 'runner_votes' => 24234, 'margin' => 1263];
        $analytics = app(HistoricalElectionAnalytics::class);

        $this->assertSame(['winner' => 'RAICHANDBHAI NARSIBHAI', 'party' => 'INC', 'margin' => 1263, 'derived' => false],
            $analytics->singleSeatResult($record));
        $summary = $analytics->summarize([$record]);
        $this->assertSame(0, $summary['turnout_count']);
        $this->assertSame(1, $summary['margin_count']);
        foreach ([
            ['summary_source_sha256', str_repeat('0', 64)],
            ['summary_page', 133],
            ['number_of_seats', 2],
            ['valid_candidate_votes', 96629],
        ] as [$field, $value]) {
            $altered = $record;
            $altered[$field] = $value;
            $this->assertNull($analytics->singleSeatResult($altered));
        }
    }

    public function test_official_1989_declared_result_is_shown_without_impossible_turnout(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('3250a94d4b625ec2bea29016', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 103);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.';
        $record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout';
        $record['official_summary_state'] = 'Tamil Nadu';
        $record['official_source_url'] = $data['source_url'];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_page'] = 120;
        $record['summary_totals'] = ['electors' => 123266, 'votes_polled' => 151869, 'valid_candidate_votes' => 148168];
        $record['summary_result'] = ['winner' => 'VELLINGIRI, U.K.', 'winner_party' => 'CPM', 'winner_votes' => 62305,
            'runner' => 'SHANMUGAM, P.', 'runner_party' => 'ADK(JL)', 'runner_votes' => 40702, 'margin' => 21603];
        $analytics = app(HistoricalElectionAnalytics::class);

        $summary = $analytics->summarize([$record]);
        $this->assertNull($summary['turnout']);
        $this->assertNull($summary['polled']);
        $this->assertSame(0, $summary['party_count']);
        $this->assertSame(21603, $summary['margin']);
        $this->assertSame(['winner' => 'VELLINGIRI, U.K.', 'party' => 'CPM', 'margin' => 21603, 'derived' => false],
            $analytics->singleSeatResult($record));

        $altered = $record;
        $altered['summary_page']++;
        $this->assertNull($analytics->singleSeatResult($altered));
        $altered = $record;
        $altered['candidates'][0]['votes']++;
        $this->assertNull($analytics->singleSeatResult($altered));
        $altered = $record;
        $altered['summary_result']['winner_party'] = 'INC';
        $this->assertNull($analytics->singleSeatResult($altered));
    }

    public function test_official_1982_champdani_result_is_shown_without_impossible_turnout(): void
    {
        [$data] = app(HistoricalElectionArchive::class)->load('9982b63a332a67579dae045f', app(ElectionArchive::class));
        $record = collect($data['records'])->firstWhere('code', 181);
        $record['original_extraction_warning'] = $record['error'];
        $record['error'] = 'The official report prints more voters than electors; turnout is withheld. Its declared winner and margin are shown for review.';
        $record['source_warning_code'] = 'official_ac_declared_result_invalid_turnout';
        $record['official_summary_state'] = 'West Bengal';
        $record['official_source_url'] = $data['source_url'];
        $record['summary_source_file'] = $data['source_file'];
        $record['summary_source_sha256'] = $data['source_sha256'];
        $record['summary_page'] = 197;
        $record['summary_totals'] = ['electors' => 87335, 'votes_polled' => 91850, 'valid_candidate_votes' => 89899];
        $record['summary_result'] = ['winner' => 'SAILENDRA NATH CHATTOPADHYAY', 'winner_party' => 'CPM',
            'winner_votes' => 47301, 'runner' => 'SWARAJ MUKHOPADHYAY', 'runner_party' => 'INC',
            'runner_votes' => 40682, 'margin' => 6619];
        $analytics = app(HistoricalElectionAnalytics::class);

        $this->assertSame(['winner' => 'SAILENDRA NATH CHATTOPADHYAY', 'party' => 'CPM', 'margin' => 6619, 'derived' => false],
            $analytics->singleSeatResult($record));
        $summary = $analytics->summarize([$record]);
        $this->assertSame(0, $summary['turnout_count']);
        $this->assertNull($summary['turnout']);
        $this->assertSame(1, $summary['margin_count']);

        foreach ([['summary_page', 196], ['summary_source_sha256', str_repeat('0', 64)],
            ['valid_candidate_votes', 89898], ['number_of_seats', 2]] as [$field, $value]) {
            $altered = $record;
            $altered[$field] = $value;
            $this->assertNull($analytics->singleSeatResult($altered));
        }
    }
}
