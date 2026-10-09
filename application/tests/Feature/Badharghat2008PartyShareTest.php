<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Badharghat2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "DILIP SARKAR",
      "sex": "M",
      "age": 50,
      "category": "SC",
      "party_at_election": "INC",
      "postal_votes": 359,
      "votes": 29724,
      "general_votes": 29365,
      "reported_vote_percent": 48.43,
      "source_row": 1
    },
    {
      "candidate_name": "3 SUBRATA CHAKRABORTY",
      "sex": "M",
      "age": 60,
      "category": "GEN",
      "party_at_election": "CPM",
      "postal_votes": 472,
      "votes": 29349,
      "general_votes": 28877,
      "reported_vote_percent": 47.82,
      "source_row": 2
    },
    {
      "candidate_name": "2 RAMA PRASAD PAUL",
      "sex": "M",
      "age": 38,
      "category": "GEN",
      "party_at_election": "BJP",
      "postal_votes": 5,
      "votes": 731,
      "general_votes": 726,
      "reported_vote_percent": 1.19,
      "source_row": 3
    },
    {
      "candidate_name": "1 SUBRATA CHAKRABORTY",
      "sex": "M",
      "age": 47,
      "category": "GEN",
      "party_at_election": "IND",
      "postal_votes": 16,
      "votes": 689,
      "general_votes": 673,
      "reported_vote_percent": 1.12,
      "source_row": 4
    },
    {
      "candidate_name": "7 DILIP DUTTA",
      "sex": "M",
      "age": 54,
      "category": "GEN",
      "party_at_election": "AIFB",
      "postal_votes": 1,
      "votes": 380,
      "general_votes": 379,
      "reported_vote_percent": 0.62,
      "source_row": 5
    },
    {
      "candidate_name": "5 DWIJENDRA SAHAJI",
      "sex": "M",
      "age": 54,
      "category": "GEN",
      "party_at_election": "NCP",
      "postal_votes": 3,
      "votes": 310,
      "general_votes": 307,
      "reported_vote_percent": 0.51,
      "source_row": 6
    },
    {
      "candidate_name": "4 DEBASISH DATTA",
      "sex": "M",
      "age": 44,
      "category": "GEN",
      "party_at_election": "AITC",
      "postal_votes": 0,
      "votes": 188,
      "general_votes": 188,
      "reported_vote_percent": 0.31,
      "source_row": 7
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 14,
  "name": "Badharghat",
  "state_name": "Tripura",
  "electors": 66149,
  "detail_page": 75,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 61494,
  "summary_totals": {
    "electors": 66149,
    "votes_polled": 61494,
    "valid_candidate_votes": 61371
  },
  "summary_page": 26,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "DILIP SARKAR",
    "winner_party": "INC",
    "winner_votes": 29724,
    "runner": "SUBRATA CHAKRABORTY",
    "runner_party": "CPM",
    "runner_votes": 29349,
    "margin": 375
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
        $this->assertSame(61494, $result['polled']);
        $this->assertEquals(375, $result['margin']);
        $this->assertSame('DILIP SARKAR', $result['winners'][0]['candidate']);
        $this->assertSame([29724, 29349, 731, 689, 380, 310, 188], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(29724 / 61371 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(61494 / 66149 * 100, $result['turnout'], 0.00001);
        $this->assertSame('DILIP SARKAR', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 14, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Badharghat', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 7, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Badharghat']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(29724, $rows[0]['party0']);
            $this->assertEqualsWithDelta(29724 / 61371 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(61494, $rows[0]['polled']);
            $this->assertEquals(375, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('3 SUBRATA CHAKRABORTY')->assertSee('1 SUBRATA CHAKRABORTY');
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
        $r['candidates'][4] = $r['candidates'][3];
        $this->assertSame(0, $analytics->summarize([$r])['party_count']);
    }
}
