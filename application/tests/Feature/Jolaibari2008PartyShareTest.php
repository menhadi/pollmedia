<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Jolaibari2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "JASHABIR TRIPURA",
      "sex": "M",
      "age": 52,
      "category": "ST",
      "party_at_election": "CPM",
      "postal_votes": 78,
      "votes": 13864,
      "general_votes": 13786,
      "reported_vote_percent": 59.44,
      "source_row": 1
    },
    {
      "candidate_name": "2 BRAJENDRA MOG CHAUDHURI",
      "sex": "M",
      "age": 44,
      "category": "ST",
      "party_at_election": "INC",
      "postal_votes": 29,
      "votes": 8356,
      "general_votes": 8327,
      "reported_vote_percent": 35.82,
      "source_row": 2
    },
    {
      "candidate_name": "1 PURNA CHANDRA TRIPURA",
      "sex": "M",
      "age": 27,
      "category": "ST",
      "party_at_election": "IND",
      "postal_votes": 0,
      "votes": 473,
      "general_votes": 473,
      "reported_vote_percent": 2.03,
      "source_row": 3
    },
    {
      "candidate_name": "5 MADHU SUDAN TRIPURA",
      "sex": "M",
      "age": 25,
      "category": "ST",
      "party_at_election": "AITC",
      "postal_votes": 4,
      "votes": 396,
      "general_votes": 392,
      "reported_vote_percent": 1.7,
      "source_row": 4
    },
    {
      "candidate_name": "3 KEJARI MOG CHOWDHURY",
      "sex": "M",
      "age": 58,
      "category": "ST",
      "party_at_election": "IND",
      "postal_votes": 0,
      "votes": 237,
      "general_votes": 237,
      "reported_vote_percent": 1.02,
      "source_row": 5
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 39,
  "name": "Jolaibari  (ST)",
  "state_name": "Tripura",
  "electors": 25111,
  "detail_page": 80,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 23476,
  "summary_totals": {
    "electors": 25111,
    "votes_polled": 23476,
    "valid_candidate_votes": 23326
  },
  "summary_page": 51,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "JASHABIR TRIPURA",
    "winner_party": "CPM",
    "winner_votes": 13864,
    "runner": "BRAJENDRA MOG CHAUDHURI",
    "runner_party": "INC",
    "runner_votes": 8356,
    "margin": 5508
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
        $this->assertSame(23476, $result['polled']);
        $this->assertEquals(5508, $result['margin']);
        $this->assertSame('JASHABIR TRIPURA', $result['winners'][0]['candidate']);
        $this->assertSame([13864, 8356, 710, 396], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(13864 / 23326 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(23476 / 25111 * 100, $result['turnout'], 0.00001);
        $this->assertSame('JASHABIR TRIPURA', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 39, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Jolaibari  (ST)', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 5, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Jolaibari  (ST)']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(13864, $rows[0]['party0']);
            $this->assertEqualsWithDelta(13864 / 23326 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(23476, $rows[0]['polled']);
            $this->assertEquals(5508, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('2 BRAJENDRA MOG CHAUDHURI')->assertSee('1 PURNA CHANDRA TRIPURA')->assertSee('3 KEJARI MOG CHOWDHURY');
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
