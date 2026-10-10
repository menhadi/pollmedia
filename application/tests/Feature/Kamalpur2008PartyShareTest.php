<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Kamalpur2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "SRI MANOJ KANTI DEB",
      "sex": "M",
      "age": 37,
      "category": "GEN",
      "party_at_election": "INC",
      "postal_votes": 213,
      "votes": 11839,
      "general_votes": 11626,
      "reported_vote_percent": 48.2,
      "source_row": 1
    },
    {
      "candidate_name": "3 SMT BIJOY LAKSHMI SINGHA",
      "sex": "F",
      "age": 48,
      "category": "GEN",
      "party_at_election": "CPM",
      "postal_votes": 197,
      "votes": 11704,
      "general_votes": 11507,
      "reported_vote_percent": 47.65,
      "source_row": 2
    },
    {
      "candidate_name": "2 SRI UMA KANTA DEBNATH",
      "sex": "M",
      "age": 47,
      "category": "GEN",
      "party_at_election": "BJP",
      "postal_votes": 4,
      "votes": 350,
      "general_votes": 346,
      "reported_vote_percent": 1.43,
      "source_row": 3
    },
    {
      "candidate_name": "1 SRI BIR KUMAR SINHA",
      "sex": "M",
      "age": 38,
      "category": "GEN",
      "party_at_election": "AIFB",
      "postal_votes": 1,
      "votes": 225,
      "general_votes": 224,
      "reported_vote_percent": 0.92,
      "source_row": 4
    },
    {
      "candidate_name": "4 CAND SL. as per form 7 SRI SUSHIL MALAKAR",
      "sex": "M",
      "age": 57,
      "category": "GEN",
      "party_at_election": "IND",
      "postal_votes": 0,
      "votes": 222,
      "general_votes": 222,
      "reported_vote_percent": 0.9,
      "source_row": 5
    },
    {
      "candidate_name": "6 MD. GUNU MIA",
      "sex": "M",
      "age": 50,
      "category": "GEN",
      "party_at_election": "IND",
      "postal_votes": 1,
      "votes": 221,
      "general_votes": 220,
      "reported_vote_percent": 0.9,
      "source_row": 6
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 45,
  "name": "Kamalpur",
  "state_name": "Tripura",
  "electors": 26416,
  "detail_page": 81,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 24617,
  "summary_totals": {
    "electors": 26416,
    "votes_polled": 24617,
    "valid_candidate_votes": 24561
  },
  "summary_page": 57,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "SRI MANOJ KANTI DEB",
    "winner_party": "INC",
    "winner_votes": 11839,
    "runner": "SMT BIJOY LAKSHMI SINGHA",
    "runner_party": "CPM",
    "runner_votes": 11704,
    "margin": 135
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
        $this->assertSame(24617, $result['polled']);
        $this->assertEquals(135, $result['margin']);
        $this->assertSame('SRI MANOJ KANTI DEB', $result['winners'][0]['candidate']);
        $this->assertSame([11839, 11704, 443, 350, 225], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(11839 / 24561 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(24617 / 26416 * 100, $result['turnout'], 0.00001);
        $this->assertSame('SRI MANOJ KANTI DEB', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 45, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Kamalpur', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 6, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Kamalpur']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(11839, $rows[0]['party0']);
            $this->assertEqualsWithDelta(11839 / 24561 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(24617, $rows[0]['polled']);
            $this->assertEquals(135, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('3 SMT BIJOY LAKSHMI SINGHA')->assertSee('6 MD. GUNU MIA')->assertSee('4 CAND SL. as per form 7 SRI SUSHIL MALAKAR');
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
