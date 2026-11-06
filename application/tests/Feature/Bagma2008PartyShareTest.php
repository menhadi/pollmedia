<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Bagma2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "NARESH CHANDRA JAMATIA",
      "sex": "M",
      "age": 47,
      "category": "ST",
      "party_at_election": "CPM",
      "postal_votes": 170,
      "votes": 14979,
      "general_votes": 14809,
      "reported_vote_percent": 52.05,
      "source_row": 1
    },
    {
      "candidate_name": "1 RATI MOHAN JAMATIA",
      "sex": "M",
      "age": 70,
      "category": "ST",
      "party_at_election": "INC",
      "postal_votes": 102,
      "votes": 13064,
      "general_votes": 12962,
      "reported_vote_percent": 45.39,
      "source_row": 2
    },
    {
      "candidate_name": "3 RAJ KUMAR JAMATIA",
      "sex": "M",
      "age": 40,
      "category": "ST",
      "party_at_election": "BJP",
      "postal_votes": 3,
      "votes": 736,
      "general_votes": 733,
      "reported_vote_percent": 2.56,
      "source_row": 3
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 30,
  "name": "Bagma  (ST)",
  "state_name": "Tripura",
  "electors": 30930,
  "detail_page": 78,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 28829,
  "summary_totals": {
    "electors": 30930,
    "votes_polled": 28829,
    "valid_candidate_votes": 28779
  },
  "summary_page": 42,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "NARESH CHANDRA JAMATIA",
    "winner_party": "CPM",
    "winner_votes": 14979,
    "runner": "RATI MOHAN JAMATIA",
    "runner_party": "INC",
    "runner_votes": 13064,
    "margin": 1915
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
        $this->assertSame(28829, $result['polled']);
        $this->assertEquals(1915, $result['margin']);
        $this->assertSame('NARESH CHANDRA JAMATIA', $result['winners'][0]['candidate']);
        $this->assertSame([14979, 13064, 736], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(14979 / 28779 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(28829 / 30930 * 100, $result['turnout'], 0.00001);
        $this->assertSame('NARESH CHANDRA JAMATIA', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 30, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Bagma  (ST)', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 3, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Bagma  (ST)']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(14979, $rows[0]['party0']);
            $this->assertEqualsWithDelta(14979 / 28779 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(28829, $rows[0]['polled']);
            $this->assertEquals(1915, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('1 RATI MOHAN JAMATIA');
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
        $r['candidates'][2] = $r['candidates'][1];
        $this->assertSame(0, $analytics->summarize([$r])['party_count']);
    }
}
