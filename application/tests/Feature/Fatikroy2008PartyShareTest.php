<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Fatikroy2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "BIJOY ROY",
      "sex": "M",
      "age": 54,
      "category": "GEN",
      "party_at_election": "CPM",
      "postal_votes": 168,
      "votes": 14457,
      "general_votes": 14289,
      "reported_vote_percent": 51.04,
      "source_row": 1
    },
    {
      "candidate_name": "1 SUNIL CHANDRA DAS",
      "sex": "M",
      "age": 65,
      "category": "SC",
      "party_at_election": "INC",
      "postal_votes": 99,
      "votes": 12144,
      "general_votes": 12045,
      "reported_vote_percent": 42.88,
      "source_row": 2
    },
    {
      "candidate_name": "3 BIRESWAR SINGHA",
      "sex": "M",
      "age": 49,
      "category": "GEN",
      "party_at_election": "BJP",
      "postal_votes": 5,
      "votes": 617,
      "general_votes": 612,
      "reported_vote_percent": 2.18,
      "source_row": 3
    },
    {
      "candidate_name": "2 RATHINDRA DEBNATH",
      "sex": "M",
      "age": 42,
      "category": "GEN",
      "party_at_election": "IND",
      "postal_votes": 1,
      "votes": 572,
      "general_votes": 571,
      "reported_vote_percent": 2.02,
      "source_row": 4
    },
    {
      "candidate_name": "8 BASUDEB GHOSH",
      "sex": "M",
      "age": 44,
      "category": "GEN",
      "party_at_election": "CPI(ML)(L)",
      "postal_votes": 0,
      "votes": 188,
      "general_votes": 188,
      "reported_vote_percent": 0.66,
      "source_row": 5
    },
    {
      "candidate_name": "5 JYOTIRMOY DEB",
      "sex": "M",
      "age": 56,
      "category": "GEN",
      "party_at_election": "AITC",
      "postal_votes": 0,
      "votes": 144,
      "general_votes": 144,
      "reported_vote_percent": 0.51,
      "source_row": 6
    },
    {
      "candidate_name": "6 PRANAY BHUSAN BASAK",
      "sex": "M",
      "age": 43,
      "category": "GEN",
      "party_at_election": "AMB",
      "postal_votes": 1,
      "votes": 107,
      "general_votes": 106,
      "reported_vote_percent": 0.38,
      "source_row": 7
    },
    {
      "candidate_name": "7 PRATIK SEN",
      "sex": "M",
      "age": 46,
      "category": "GEN",
      "party_at_election": "AIFB",
      "postal_votes": 0,
      "votes": 94,
      "general_votes": 94,
      "reported_vote_percent": 0.33,
      "source_row": 8
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 51,
  "name": "Fatikroy",
  "state_name": "Tripura",
  "electors": 30661,
  "detail_page": 83,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 28363,
  "summary_totals": {
    "electors": 30661,
    "votes_polled": 28363,
    "valid_candidate_votes": 28323
  },
  "summary_page": 63,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "BIJOY ROY",
    "winner_party": "CPM",
    "winner_votes": 14457,
    "runner": "SUNIL CHANDRA DAS",
    "runner_party": "INC",
    "runner_votes": 12144,
    "margin": 2313
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
        $this->assertSame(28363, $result['polled']);
        $this->assertEquals(2313, $result['margin']);
        $this->assertSame('BIJOY ROY', $result['winners'][0]['candidate']);
        $this->assertSame([14457, 12144, 617, 572, 188, 144, 107, 94], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(14457 / 28323 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(28363 / 30661 * 100, $result['turnout'], 0.00001);
        $this->assertSame('BIJOY ROY', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame('CPI(ML)(L)', $result['parties'][4]['party']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 51, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Fatikroy', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 8, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Fatikroy']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(14457, $rows[0]['party0']);
            $this->assertEqualsWithDelta(14457 / 28323 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(28363, $rows[0]['polled']);
            $this->assertEquals(2313, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('1 SUNIL CHANDRA DAS')->assertSee('8 BASUDEB GHOSH')->assertSee('7 PRATIK SEN');
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
