<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Asharambari2008PartyShareTest extends TestCase
{
    use RefreshDatabase;

    private function record(): array
    {
        return json_decode(<<<'JSON'
{
  "candidates": [
    {
      "candidate_name": "SACHINDRA DEBBARMA",
      "sex": "M",
      "age": 44,
      "category": "ST",
      "party_at_election": "CPM",
      "postal_votes": 167,
      "votes": 13765,
      "general_votes": 13598,
      "reported_vote_percent": 56.78,
      "source_row": 1
    },
    {
      "candidate_name": "3 AMIYA KUMAR DEBBARMA",
      "sex": "M",
      "age": 52,
      "category": "ST",
      "party_at_election": "INPT",
      "postal_votes": 75,
      "votes": 9234,
      "general_votes": 9159,
      "reported_vote_percent": 38.09,
      "source_row": 2
    },
    {
      "candidate_name": "1 PRAFULLA DEBBARMA",
      "sex": "M",
      "age": 29,
      "category": "ST",
      "party_at_election": "IND",
      "postal_votes": 2,
      "votes": 441,
      "general_votes": 439,
      "reported_vote_percent": 1.82,
      "source_row": 3
    },
    {
      "candidate_name": "5 DHANBHAKTI JAMATIA",
      "sex": "F",
      "age": 31,
      "category": "ST",
      "party_at_election": "BJP",
      "postal_votes": 2,
      "votes": 419,
      "general_votes": 417,
      "reported_vote_percent": 1.73,
      "source_row": 4
    },
    {
      "candidate_name": "2 CAND SL. as per form 7 ASHIT DEBBARMA",
      "sex": "M",
      "age": 39,
      "category": "ST",
      "party_at_election": "IND",
      "postal_votes": 2,
      "votes": 383,
      "general_votes": 381,
      "reported_vote_percent": 1.58,
      "source_row": 5
    }
  ],
  "number_of_seats": 1,
  "status": "needs_review",
  "error": "Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review. Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout. Official summary declares the winner and margin.",
  "code": 25,
  "name": "Asharambari  (ST)",
  "state_name": "Tripura",
  "electors": 26225,
  "detail_page": 77,
  "original_extraction_warning": "Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; Some candidate text could not be parsed; see the original PDF.; Detailed totals are missing or use an unsupported layout.",
  "source_warning_code": "summary_turnout_with_detail_warnings",
  "votes_polled": 24258,
  "summary_totals": {
    "electors": 26225,
    "votes_polled": 24258,
    "valid_candidate_votes": 24242
  },
  "summary_page": 37,
  "summary_source_file": "c2b9ef2bc73bbcc70a271a58-7626.pdf",
  "summary_source_sha256": "0a3374adf6618574adb8268d9282c13dd30fa388b537696e94642b5447d9ca43",
  "summary_result": {
    "winner": "SACHINDRA DEBBARMA",
    "winner_party": "CPM",
    "winner_votes": 13765,
    "runner": "AMIYA KUMAR DEBBARMA",
    "runner_party": "INPT",
    "runner_votes": 9234,
    "margin": 4531
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
        $this->assertSame(24258, $result['polled']);
        $this->assertEquals(4531, $result['margin']);
        $this->assertSame('SACHINDRA DEBBARMA', $result['winners'][0]['candidate']);
        $this->assertSame([13765, 9234, 824, 419], array_column($result['parties'], 'votes'));
        $this->assertEqualsWithDelta(13765 / 24242 * 100, $result['parties'][0]['share'], 0.000001);
        $this->assertEqualsWithDelta(92.499523, $result['turnout'], 0.00001);
        $this->assertSame('SACHINDRA DEBBARMA', $analytics->singleSeatResult($record)['winner']);
        $this->assertSame($before, json_encode($record));
    }

    public function test_actual_record_party_votes_reach_public_history_charts(): void
    {
        $id = 'c2b9ef2bc73bbcc70a271a58';
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => 25, 'kind' => 'ac', 'year' => 2008, 'edition_label' => '2008', 'state_label' => 'Tripura', 'constituency_name' => 'Asharambari  (ST)', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 5, 'extraction_sha256' => str_repeat('a', 64)]);
        $record = $this->record();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($record): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://old.eci.gov.in/files/file/3309-tripura-2008/', 'source_sha256' => $record['summary_source_sha256'], 'records' => [$record]]]);
        });
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => 'Asharambari  (ST)']))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $rows = json_decode($chart->textContent, true)['rows'];
            $this->assertSame([2008], array_column($rows, 'year'));
            $this->assertEquals(13765, $rows[0]['party0']);
            $this->assertEqualsWithDelta(13765 / 24242 * 100, $rows[0]['party0_share'], 0.000001);
            $this->assertEquals(24258, $rows[0]['polled']);
            $this->assertEquals(4531, $rows[0]['margin']);
        }
        $response->assertSee('detailed candidate text still needs review')->assertSee('2 CAND SL. as per form 7 ASHIT DEBBARMA');
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
