<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Mandaibazar2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "MONORANJAN DEBBARMA",
      "sex": "M",
      "age": 47,
      "category": "ST",
      "party_at_election": "CPM",
      "postal_votes": 142,
      "votes": 16605,
      "general_votes": 16463,
      "reported_vote_percent": 49.44,
      "source_row": 1
    },
    {
      "candidate_name": "1 CAND SL. as per form 7 JAGADISH DEBBARMA",
      "sex": "M",
      "age": 51,
      "category": "ST",
      "party_at_election": "INPT",
      "postal_votes": 184,
      "votes": 15638,
      "general_votes": 15454,
      "reported_vote_percent": 46.56,
      "source_row": 2
    },
    {
      "candidate_name": "2 BUDHU KUMAR DEBBARMA",
      "sex": "M",
      "age": 52,
      "category": "ST",
      "party_at_election": "IND",
      "postal_votes": 3,
      "votes": 650,
      "general_votes": 647,
      "reported_vote_percent": 1.94,
      "source_row": 3
    },
    {
      "candidate_name": "5 NARENDRA DEBBARMA",
      "sex": "M",
      "age": 35,
      "category": "ST",
      "party_at_election": "LJP",
      "postal_votes": 3,
      "votes": 392,
      "general_votes": 389,
      "reported_vote_percent": 1.17,
      "source_row": 4
    },
    {
      "candidate_name": "4 SATISH DEBBARMA",
      "sex": "M",
      "age": 28,
      "category": "ST",
      "party_at_election": "AITC",
      "postal_votes": 7,
      "votes": 301,
      "general_votes": 294,
      "reported_vote_percent": 0.9,
      "source_row": 5
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 11,
  "name": "Mandaibazar  (ST)",
  "state_name": "Tripura",
  "electors": 37050,
  "detail_page": 74,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 33593,
  "summary_totals": {
    "electors": 37050,
    "votes_polled": 33593,
    "valid_candidate_votes": 33586
  },
  "summary_page": 23,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "MONORANJAN DEBBARMA",
    "winner_party": "CPM",
    "winner_votes": 16605,
    "runner": "JAGADISH DEBBARMA",
    "runner_party": "INPT",
    "runner_votes": 15638,
    "margin": 967
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
        $this->assertSame(33593, $result['polled']);
        $this->assertEquals(967, $result['margin']);
        $this->assertSame('MONORANJAN DEBBARMA', $result['winners'][0]['candidate']);
        $this->assertSame([16605, 15638, 650, 392, 301], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(16605 / 33586 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(33593 / 37050 * 100, $result['turnout'], 0.00001);
        $this->assertSame('MONORANJAN DEBBARMA', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 11, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Mandaibazar  (ST)', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 5, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Mandaibazar  (ST)']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(16605, $rows[0]['party0']);
            $this->assertEqualsWithDelta(16605 / 33586 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(33593, $rows[0]['polled']);
            $this->assertEquals(967, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('1 CAND SL. as per form 7 JAGADISH DEBBARMA')->assertSee('2 BUDHU KUMAR DEBBARMA')->assertSee('4 SATISH DEBBARMA');
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
        $r['candidates'][2] = $r['candidates'][3];
        $this->assertSame(0, $analytics->summarize([$r])['party_count']);
    }
}
