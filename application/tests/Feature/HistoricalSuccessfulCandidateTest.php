<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use Tests\TestCase;

class HistoricalSuccessfulCandidateTest extends TestCase
{
    public function test_verified_lists_supply_only_winners_and_reject_changed_evidence(): void
    {
        $analytics = new HistoricalElectionAnalytics;
        $fixtures = json_decode(file_get_contents(database_path('fixtures/official-successful-candidates.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $key => $source) {
            [$edition, $code] = explode(':', $key);
            $record = [
                'code' => (int) $code, 'number_of_seats' => 1, 'state_name' => $source['state'],
                'constituency_name' => $source['name'], 'official_pc_code' => $source['official_pc_code'],
                'electors' => 100000, 'votes_polled' => 0, 'valid_candidate_votes' => 0,
                'status' => 'needs_review', 'error' => 'Original zero vote fields remain unresolved.',
                'source_warning_code' => 'official_successful_candidate_only',
                'official_successful_candidate' => $source,
                'candidates' => [['candidate_name' => $source['winner'], 'party_at_election' => $source['party'], 'votes' => 0]],
            ];
            $this->assertSame(['winner' => $source['winner'], 'party' => $source['party'], 'margin' => null, 'derived' => false, 'winner_only' => true], $analytics->singleSeatResult($record, $edition));
            $summary = $analytics->summarize([$record]);
            $this->assertSame(0, $summary['turnout_count']);
            $this->assertSame(0, $summary['margin_count']);
            $this->assertNull($analytics->singleSeatResult($record));
            $this->assertNull($analytics->singleSeatResult(array_replace($record, ['number_of_seats' => 2]), $edition));
            $this->assertNull($analytics->singleSeatResult(array_replace($record, ['official_pc_code' => 999]), $edition));
            $changed = $record;
            $changed['official_successful_candidate']['source_sha256'] = str_repeat('0', 64);
            $this->assertNull($analytics->singleSeatResult($changed, $edition));
            $changed = $record;
            unset($changed['official_successful_candidate']);
            $this->assertNull($analytics->singleSeatResult($changed, $edition));
        }
    }
}
