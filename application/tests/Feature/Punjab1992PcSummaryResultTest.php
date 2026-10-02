<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use Tests\TestCase;

class Punjab1992PcSummaryResultTest extends TestCase
{
    public function test_official_summary_restores_reviewed_winner_and_margin_despite_serial_gap(): void
    {
        $record = ['code' => 9, 'name' => 'PUNJAB / LUDHIANA', 'number_of_seats' => 1, 'status' => 'needs_review',
            'error' => 'Candidate serial numbers are incomplete', 'electors' => 1202152, 'votes_polled' => 201686,
            'valid_candidate_votes' => 193760, 'summary_page' => 13,
            'summary_totals' => ['electors' => 1202152, 'votes_polled' => 201686, 'valid_candidate_votes' => 193760],
            'candidates' => [
                ['candidate_name' => 'GURCHARAN SINGH GALIB', 'party_at_election' => 'INC', 'votes' => 108811],
                ['candidate_name' => 'KRISHAN KANT JAIN', 'party_at_election' => 'BJP', 'votes' => 55363],
                ['candidate_name' => 'PRITPAL SINGH', 'party_at_election' => 'BSP', 'votes' => 18733],
                ['candidate_name' => 'SUBHASH CHANDER', 'party_at_election' => 'JD', 'votes' => 5969],
                ['candidate_name' => 'SATINDER SINGH', 'party_at_election' => 'IND', 'votes' => 2344],
                ['candidate_name' => 'KAMAL KUMAR', 'party_at_election' => 'IND', 'votes' => 1032],
                ['candidate_name' => 'TIRLOCHAN SINGH BHINDER', 'party_at_election' => 'IND', 'votes' => 645],
                ['candidate_name' => 'KAKA JOGINDER SINGH ALIAS DHARTI PAKAR', 'party_at_election' => 'IND', 'votes' => 451],
                ['candidate_name' => 'JOGINDER SINGH AZAD', 'party_at_election' => 'IND', 'votes' => 412],
            ],
        ];
        $analytics = app(HistoricalElectionAnalytics::class);
        $this->assertNull($analytics->singleSeatResult($record));
        $this->assertNull($analytics->summarize([$record])['margin']);

        $record['source_warning_code'] = 'official_pc_summary_reconciled_serial_gap';
        $record['summary_source_file'] = '713d20479c067ed485ca5260-9768.pdf';
        $record['summary_source_sha256'] = 'f28011a26fe579e9bdce103300f206ed9409b713e1630e73a382fa65a0e32fae';
        $record['summary_candidate_count'] = 9;
        $record['summary_result'] = ['winner' => 'GURCHARAN SINGH GALIB', 'winner_party' => 'INC', 'winner_votes' => 108811,
            'runner' => 'KRISHAN KANT JAIN', 'runner_party' => 'BJP', 'runner_votes' => 55363, 'margin' => 53448];
        $this->assertSame(['winner' => 'GURCHARAN SINGH GALIB', 'party' => 'INC', 'margin' => 53448, 'derived' => true], $analytics->singleSeatResult($record));
        $this->assertSame(53448, $analytics->summarize([$record])['margin']);
        $this->assertSame(1, $analytics->summarize([$record])['party_count']);

        $record['summary_result']['margin']++;
        $this->assertNull($analytics->singleSeatResult($record));
        $this->assertNull($analytics->summarize([$record])['margin']);
        $record['summary_result']['margin']--;
        $record['summary_candidate_count']--;
        $this->assertNull($analytics->singleSeatResult($record));
    }
}
