<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Banamalipur2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "GOPAL CHANDRA ROY",
      "sex": "M",
      "age": 59,
      "category": "GEN",
      "party_at_election": "INC",
      "postal_votes": 196,
      "votes": 12354,
      "general_votes": 12158,
      "reported_vote_percent": 54.68,
      "source_row": 1
    },
    {
      "candidate_name": "1 PRASANTA KAPALI",
      "sex": "M",
      "age": 58,
      "category": "GEN",
      "party_at_election": "CPI",
      "postal_votes": 298,
      "votes": 9546,
      "general_votes": 9248,
      "reported_vote_percent": 42.25,
      "source_row": 2
    },
    {
      "candidate_name": "2 SUDHINDRA CHANDRA DASGUPTA",
      "sex": "M",
      "age": 68,
      "category": "GEN",
      "party_at_election": "BJP",
      "postal_votes": 7,
      "votes": 365,
      "general_votes": 358,
      "reported_vote_percent": 1.62,
      "source_row": 3
    },
    {
      "candidate_name": "3 NISHITH DAS",
      "sex": "M",
      "age": 68,
      "category": "GEN",
      "party_at_election": "IND",
      "postal_votes": 1,
      "votes": 206,
      "general_votes": 205,
      "reported_vote_percent": 0.91,
      "source_row": 4
    },
    {
      "candidate_name": "6 RAKHAL RAJ DATTA",
      "sex": "M",
      "age": 59,
      "category": "GEN",
      "party_at_election": "AMB",
      "postal_votes": 0,
      "votes": 88,
      "general_votes": 88,
      "reported_vote_percent": 0.39,
      "source_row": 5
    },
    {
      "candidate_name": "5 BASANTI SINHA",
      "sex": "F",
      "age": 54,
      "category": "GEN",
      "party_at_election": "AITC",
      "postal_votes": 1,
      "votes": 36,
      "general_votes": 35,
      "reported_vote_percent": 0.16,
      "source_row": 6
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 9,
  "name": "Banamalipur",
  "state_name": "Tripura",
  "electors": 25956,
  "detail_page": 74,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 22696,
  "summary_totals": {
    "electors": 25956,
    "votes_polled": 22696,
    "valid_candidate_votes": 22595
  },
  "summary_page": 21,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "GOPAL CHANDRA ROY",
    "winner_party": "INC",
    "winner_votes": 12354,
    "runner": "PRASANTA KAPALI",
    "runner_party": "CPI",
    "runner_votes": 9546,
    "margin": 2808
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
        $this->assertSame(22696, $result['polled']);
        $this->assertEquals(2808, $result['margin']);
        $this->assertSame('GOPAL CHANDRA ROY', $result['winners'][0]['candidate']);
        $this->assertSame('CPI', $result['parties'][1]['party']);
        $this->assertSame([12354, 9546, 365, 206, 88, 36], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(12354 / 22595 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(22696 / 25956 * 100, $result['turnout'], 0.00001);
        $this->assertSame('GOPAL CHANDRA ROY', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 9, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Banamalipur', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 6, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Banamalipur']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(12354, $rows[0]['party0']);
            $this->assertEqualsWithDelta(12354 / 22595 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(22696, $rows[0]['polled']);
            $this->assertEquals(2808, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('1 PRASANTA KAPALI');
    }

    public function test_source_identity_summary_or_candidate_changes_do_not_gain_eligibility(): void
    {
        $analytics = app(HistoricalElectionAnalytics::class);
        foreach (['code' => 7, 'state_name' => 'Manipur', 'summary_source_sha256' => str_repeat('a', 64), 'summary_page' => 19, 'detail_page' => 75, 'original_extraction_warning' => 'Candidate rows are missing'] as $key => $value) {
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
        $r['candidates'][4] = $r['candidates'][3];
        $this->assertSame(0, $analytics->summarize([$r])['party_count']);
    }
}
