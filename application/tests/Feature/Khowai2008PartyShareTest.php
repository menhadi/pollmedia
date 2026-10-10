<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Khowai2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "SAMIR DEB SARKAR",
      "sex": "M",
      "age": 58,
      "category": "GEN",
      "party_at_election": "CPM",
      "postal_votes": 327,
      "votes": 15385,
      "general_votes": 15058,
      "reported_vote_percent": 54.33,
      "source_row": 1
    },
    {
      "candidate_name": "3 ARUN KUMAR KAR",
      "sex": "M",
      "age": 69,
      "category": "GEN",
      "party_at_election": "INC",
      "postal_votes": 150,
      "votes": 12062,
      "general_votes": 11912,
      "reported_vote_percent": 42.59,
      "source_row": 2
    },
    {
      "candidate_name": "1 SAILEN ROY",
      "sex": "M",
      "age": 42,
      "category": "GEN",
      "party_at_election": "AMB",
      "postal_votes": 0,
      "votes": 310,
      "general_votes": 310,
      "reported_vote_percent": 1.09,
      "source_row": 3
    },
    {
      "candidate_name": "4 DHANANJOY DEBNATH",
      "sex": "M",
      "age": 34,
      "category": "GEN",
      "party_at_election": "BJP",
      "postal_votes": 1,
      "votes": 241,
      "general_votes": 240,
      "reported_vote_percent": 0.85,
      "source_row": 4
    },
    {
      "candidate_name": "2 GAYATRI DEBNATH",
      "sex": "F",
      "age": 26,
      "category": "GEN",
      "party_at_election": "IND",
      "postal_votes": 0,
      "votes": 167,
      "general_votes": 167,
      "reported_vote_percent": 0.59,
      "source_row": 5
    },
    {
      "candidate_name": "6 ADHIR SARKAR",
      "sex": "M",
      "age": 37,
      "category": "GEN",
      "party_at_election": "IND",
      "postal_votes": 0,
      "votes": 155,
      "general_votes": 155,
      "reported_vote_percent": 0.55,
      "source_row": 6
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 24,
  "name": "Khowai",
  "state_name": "Tripura",
  "electors": 29560,
  "detail_page": 77,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 28337,
  "summary_totals": {
    "electors": 29560,
    "votes_polled": 28337,
    "valid_candidate_votes": 28320
  },
  "summary_page": 36,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "SAMIR DEB SARKAR",
    "winner_party": "CPM",
    "winner_votes": 15385,
    "runner": "ARUN KUMAR KAR",
    "runner_party": "INC",
    "runner_votes": 12062,
    "margin": 3323
  }
}
JSON, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_actual_preserved_rows_project_reviewed_party_shares_and_declared_result(): void
    {
        $record = $this->record();
        $before = json_encode($record);
        $analytics = app(HistoricalElectionAnalytics::class);
        $result = $analytics->summarize([$record]);
        $this->assertSame(1, $result['party_count']);
        $this->assertSame(1, $result['party_review_count']);
        $this->assertSame(28337, $result['polled']);
        $this->assertEquals(3323, $result['margin']);
        $this->assertSame('SAMIR DEB SARKAR', $result['winners'][0]['candidate']);
        $this->assertSame([15385, 12062, 322, 310, 241], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(15385 / 28320 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(28337 / 29560 * 100, $result['turnout'], 0.00001);
        $this->assertSame('SAMIR DEB SARKAR', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 24, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Khowai', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 6, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Khowai']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(15385, $rows[0]['party0']);
            $this->assertEqualsWithDelta(15385 / 28320 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(28337, $rows[0]['polled']);
            $this->assertEquals(3323, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('3 ARUN KUMAR KAR')->assertSee('6 ADHIR SARKAR')->assertSee('2 GAYATRI DEBNATH');
    }

    public function test_source_identity_summary_or_candidate_changes_do_not_gain_eligibility(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        foreach (['code' => 7, 'state_name' => 'Manipur', 'summary_source_sha256' => str_repeat('a', 64), 'summary_page' => 19, 'detail_page' => 74, 'original_extraction_warning' => 'Candidate rows are missing'] as $key => $value) {
            $r = $this->record();
            $r[$key] = $value;
            $this->assertSame(0, $analytics->summarize([$r])['party_count']);
        }
        $r = $this->record();
        unset($r['summary_result']);
        $this->assertSame(0, $analytics->summarize([$r])['party_count']);
        $r = $this->record();
        $r['candidates'][0]['votes']--;
        $r['candidates'][1]['votes']++;
        $this->assertSame(0, $analytics->summarize([$r])['party_count']);
        $r = $this->record();
        array_pop($r['candidates']);
        $this->assertSame(0, $analytics->summarize([$r])['party_count']);
        $r = $this->record();
        $r['candidates'][2] = $r['candidates'][3];
        $this->assertSame(0, $analytics->summarize([$r])['party_count']);
    }
}
