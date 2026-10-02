<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use Tests\TestCase;

class HistoricalPc1989SummaryResultTest extends TestCase
{
    public function test_official_summary_restores_a_reviewed_result_with_a_documented_name_variant(): void
    {
        $record = ['code' => 76, 'name' => 'BIHAR / MONGHYR', 'number_of_seats' => 1,
            'status' => 'needs_review', 'error' => 'Detailed and summary constituency names differ',
            'original_extraction_warning' => 'Detailed and summary constituency names differ',
            'electors' => 100, 'votes_polled' => 80, 'valid_candidate_votes' => 70,
            'summary_totals' => ['electors' => 100, 'votes_polled' => 80, 'valid_candidate_votes' => 70],
            'detail_page' => 4, 'summary_page' => 5,
            'candidates' => [
                ['candidate_name' => 'Candidate A', 'party_at_election' => 'AAA', 'votes' => 45],
                ['candidate_name' => 'Candidate B', 'party_at_election' => 'BBB', 'votes' => 25],
            ],
        ];
        $analytics = app(HistoricalElectionAnalytics::class);
        $this->assertNull($analytics->singleSeatResult($record));

        $record['source_warning_code'] = 'official_pc_summary_reconciled_detail_warning';
        $record['detail_candidate_count'] = 2;
        $record['summary_source_file'] = 'summary.pdf';
        $record['summary_source_sha256'] = str_repeat('a', 64);
        $record['detail_source_file'] = 'detail.pdf';
        $record['detail_source_sha256'] = str_repeat('b', 64);
        $record['summary_result'] = ['winner' => 'Candidate A', 'winner_party' => 'AAA',
            'winner_votes' => 45, 'runner' => 'Candidate B', 'runner_party' => 'BBB',
            'runner_votes' => 25, 'margin' => 20];
        $this->assertSame(['winner' => 'Candidate A', 'party' => 'AAA', 'margin' => 20, 'derived' => true],
            $analytics->singleSeatResult($record));
        $this->assertSame(20, $analytics->summarize([$record])['margin']);

        unset($record['detail_source_sha256']);
        $this->assertNull($analytics->singleSeatResult($record));
        $record['detail_source_sha256'] = str_repeat('b', 64);
        $record['summary_result']['margin']++;
        $this->assertNull($analytics->singleSeatResult($record));
    }
}
