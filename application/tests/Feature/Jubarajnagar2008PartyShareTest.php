<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Jubarajnagar2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "RAMENDRA CHANDRA DEBNATH",
      "sex": "M",
      "age": 54,
      "category": "GEN",
      "party_at_election": "CPM",
      "postal_votes": 151,
      "votes": 14710,
      "general_votes": 14559,
      "reported_vote_percent": 51.22,
      "source_row": 1
    },
    {
      "candidate_name": "1 BIVA RANI NATH",
      "sex": "F",
      "age": 49,
      "category": "GEN",
      "party_at_election": "INC",
      "postal_votes": 111,
      "votes": 13094,
      "general_votes": 12983,
      "reported_vote_percent": 45.59,
      "source_row": 2
    },
    {
      "candidate_name": "2 PIJUSH NATH",
      "sex": "M",
      "age": 33,
      "category": "GEN",
      "party_at_election": "BJP",
      "postal_votes": 3,
      "votes": 500,
      "general_votes": 497,
      "reported_vote_percent": 1.74,
      "source_row": 3
    },
    {
      "candidate_name": "3 RAMESH CHANDRA DEBNATH",
      "sex": "M",
      "age": 38,
      "category": "GEN",
      "party_at_election": "AMB",
      "postal_votes": 1,
      "votes": 415,
      "general_votes": 414,
      "reported_vote_percent": 1.45,
      "source_row": 4
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 57,
  "name": "Jubarajnagar",
  "state_name": "Tripura",
  "electors": 30898,
  "detail_page": 84,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 28721,
  "summary_totals": {
    "electors": 30898,
    "votes_polled": 28721,
    "valid_candidate_votes": 28719
  },
  "summary_page": 69,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "RAMENDRA CHANDRA DEBNATH",
    "winner_party": "CPM",
    "winner_votes": 14710,
    "runner": "BIVA RANI NATH",
    "runner_party": "INC",
    "runner_votes": 13094,
    "margin": 1616
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
        $this->assertSame(28721, $result['polled']);
        $this->assertEquals(1616, $result['margin']);
        $this->assertSame('RAMENDRA CHANDRA DEBNATH', $result['winners'][0]['candidate']);
        $this->assertSame([14710, 13094, 500, 415], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(14710 / 28719 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(28721 / 30898 * 100, $result['turnout'], 0.00001);
        $this->assertSame('RAMENDRA CHANDRA DEBNATH', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 57, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Jubarajnagar', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 4, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Jubarajnagar']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(14710, $rows[0]['party0']);
            $this->assertEqualsWithDelta(14710 / 28719 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(28721, $rows[0]['polled']);
            $this->assertEquals(1616, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('1 BIVA RANI NATH')->assertSee('2 PIJUSH NATH')->assertSee('3 RAMESH CHANDRA DEBNATH');
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
