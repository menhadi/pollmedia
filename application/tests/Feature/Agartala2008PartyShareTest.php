<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Agartala2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "CAND SL. as per form 7 SUDIP ROY BARMAN",
      "sex": "M",
      "age": 44,
      "category": "GEN",
      "party_at_election": "INC",
      "postal_votes": 261,
      "votes": 21019,
      "general_votes": 20758,
      "reported_vote_percent": 50.82,
      "source_row": 1
    },
    {
      "candidate_name": "3 BIKASH ROY",
      "sex": "M",
      "age": 66,
      "category": "GEN",
      "party_at_election": "CPM",
      "postal_votes": 336,
      "votes": 19194,
      "general_votes": 18858,
      "reported_vote_percent": 46.41,
      "source_row": 2
    },
    {
      "candidate_name": "1 MILAN CHAKRABORTY",
      "sex": "F",
      "age": 59,
      "category": "GEN",
      "party_at_election": "BJP",
      "postal_votes": 5,
      "votes": 528,
      "general_votes": 523,
      "reported_vote_percent": 1.28,
      "source_row": 3
    },
    {
      "candidate_name": "2 SHIBANI BHOWMIK",
      "sex": "F",
      "age": 48,
      "category": "SC",
      "party_at_election": "IND",
      "postal_votes": 2,
      "votes": 389,
      "general_votes": 387,
      "reported_vote_percent": 0.94,
      "source_row": 4
    },
    {
      "candidate_name": "5 LALIT MOHAN GOSWAMI",
      "sex": "M",
      "age": 71,
      "category": "GEN",
      "party_at_election": "AITC",
      "postal_votes": 1,
      "votes": 231,
      "general_votes": 230,
      "reported_vote_percent": 0.56,
      "source_row": 5
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 6,
  "name": "Agartala",
  "state_name": "Tripura",
  "electors": 47407,
  "detail_page": 73,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 41788,
  "summary_totals": {
    "electors": 47407,
    "votes_polled": 41788,
    "valid_candidate_votes": 41361
  },
  "summary_page": 18,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "SUDIP ROY BARMAN",
    "winner_party": "INC",
    "winner_votes": 21019,
    "runner": "BIKASH ROY",
    "runner_party": "CPM",
    "runner_votes": 19194,
    "margin": 1825
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
        $this->assertSame(41788, $result['polled']);
        $this->assertEquals(1825, $result['margin']);
        $this->assertSame('SUDIP ROY BARMAN', $result['winners'][0]['candidate']);
        $this->assertSame([21019, 19194, 528, 389, 231], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(21019 / 41361 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(88.147319, $result['turnout'], 0.00001);
        $this->assertSame('SUDIP ROY BARMAN', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 6, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Agartala', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 5, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'agartala']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(21019, $rows[0]['party0']);
            $this->assertEqualsWithDelta(21019 / 41361 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(41788, $rows[0]['polled']);
            $this->assertEquals(1825, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('CAND SL. as per form 7 SUDIP ROY BARMAN');
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
