<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Dharmanagar2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "BISWA BANDHU SEN",
      "sex": "M",
      "age": 54,
      "category": "GEN",
      "party_at_election": "INC",
      "postal_votes": 293,
      "votes": 15987,
      "general_votes": 15694,
      "reported_vote_percent": 51.65,
      "source_row": 1
    },
    {
      "candidate_name": "2 AMITABHA DATTA",
      "sex": "M",
      "age": 48,
      "category": "GEN",
      "party_at_election": "CPM",
      "postal_votes": 428,
      "votes": 13577,
      "general_votes": 13149,
      "reported_vote_percent": 43.86,
      "source_row": 2
    },
    {
      "candidate_name": "1 TAMAL KANTI DEB",
      "sex": "M",
      "age": 38,
      "category": "GEN",
      "party_at_election": "BJP",
      "postal_votes": 8,
      "votes": 805,
      "general_votes": 797,
      "reported_vote_percent": 2.6,
      "source_row": 3
    },
    {
      "candidate_name": "3 SANJAY CHAUDHURY",
      "sex": "M",
      "age": 41,
      "category": "GEN",
      "party_at_election": "IND",
      "postal_votes": 1,
      "votes": 213,
      "general_votes": 212,
      "reported_vote_percent": 0.69,
      "source_row": 4
    },
    {
      "candidate_name": "7 ANAMIKA ROY(SAHA)",
      "sex": "F",
      "age": 29,
      "category": "GEN",
      "party_at_election": "AIFB",
      "postal_votes": 0,
      "votes": 143,
      "general_votes": 143,
      "reported_vote_percent": 0.46,
      "source_row": 5
    },
    {
      "candidate_name": "4 ANJAN SUKLA BAIDYA",
      "sex": "M",
      "age": 47,
      "category": "SC",
      "party_at_election": "IND",
      "postal_votes": 0,
      "votes": 117,
      "general_votes": 117,
      "reported_vote_percent": 0.38,
      "source_row": 6
    },
    {
      "candidate_name": "6 GOPAL KRISHNA DEB",
      "sex": "M",
      "age": 58,
      "category": "GEN",
      "party_at_election": "AMB",
      "postal_votes": 0,
      "votes": 110,
      "general_votes": 110,
      "reported_vote_percent": 0.36,
      "source_row": 7
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 56,
  "name": "Dharmanagar",
  "state_name": "Tripura",
  "electors": 34419,
  "detail_page": 84,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 30993,
  "summary_totals": {
    "electors": 34419,
    "votes_polled": 30993,
    "valid_candidate_votes": 30952
  },
  "summary_page": 68,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "BISWA BANDHU SEN",
    "winner_party": "INC",
    "winner_votes": 15987,
    "runner": "AMITABHA DATTA",
    "runner_party": "CPM",
    "runner_votes": 13577,
    "margin": 2410
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
        $this->assertSame(30993, $result['polled']);
        $this->assertEquals(2410, $result['margin']);
        $this->assertSame('BISWA BANDHU SEN', $result['winners'][0]['candidate']);
        $this->assertSame([15987, 13577, 805, 330, 143, 110], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(15987 / 30952 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(30993 / 34419 * 100, $result['turnout'], 0.00001);
        $this->assertSame('BISWA BANDHU SEN', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 56, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Dharmanagar', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 7, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Dharmanagar']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(15987, $rows[0]['party0']);
            $this->assertEqualsWithDelta(15987 / 30952 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(30993, $rows[0]['polled']);
            $this->assertEquals(2410, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('2 AMITABHA DATTA')->assertSee('3 SANJAY CHAUDHURY')->assertSee('4 ANJAN SUKLA BAIDYA');
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
