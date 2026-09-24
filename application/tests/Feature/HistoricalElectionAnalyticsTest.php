<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionAnalytics;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalElectionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function record(int $code, int $electors, int $polled, int $winner, int $runner): array
    {
        return ['code' => $code, 'status' => 'validated', 'electors' => $electors, 'votes_polled' => $polled, 'candidates' => [
            ['candidate_name' => 'A', 'party_at_election' => 'AAA', 'votes' => $winner],
            ['candidate_name' => 'B', 'party_at_election' => 'BBB', 'votes' => $runner],
        ]];
    }

    public function test_turnout_is_weighted_and_missing_or_disputed_rows_are_not_zero(): void
    {
        $rows = [$this->record(1, 100, 80, 50, 30), $this->record(2, 900, 450, 250, 200)];
        $warning = $this->record(3, 1000, 1000, 900, 100);
        $warning['status'] = 'needs_review';
        $rows[] = $warning;
        $result = app(HistoricalElectionAnalytics::class)->summarize($rows);
        $this->assertSame(530, $result['polled']);
        $this->assertEquals(53, $result['turnout']);
        $this->assertSame(2, $result['turnout_count']);
        $this->assertEquals(35, $result['margin']);
        $this->assertEqualsWithDelta(300 / 530 * 100, $result['parties'][0]['share'], 0.00001);
        $this->assertNull(app(HistoricalElectionAnalytics::class)->summarize([$warning])['turnout']);
    }

    public function test_repeated_seats_multi_member_and_incomplete_candidate_votes_are_excluded(): void
    {
        $one = $this->record(1, 100, 80, 50, 30);
        $two = $this->record(2, 100, 80, 50, 30);
        $two['official_ac_code'] = 1;
        $multi = $this->record(3, 100, 80, 50, 30);
        $multi['number_of_seats'] = 2;
        $missing = $this->record(4, 100, 80, 50, 30);
        $missing['candidates'][1]['votes'] = null;
        $result = app(HistoricalElectionAnalytics::class)->summarize([$one, $two, $multi, $missing]);
        $this->assertSame(1, $result['turnout_count']);
        $this->assertSame(0, $result['party_count']);
        $this->assertNull($result['margin']);
    }

    public function test_state_dashboard_keeps_filters_sources_and_constituency_links(): void
    {
        $this->seed(PilibhitSeeder::class);
        $summary = app(HistoricalElectionAnalytics::class)->summarize([$this->record(1, 100, 80, 50, 30)]) + ['id' => str_repeat('a', 24), 'year' => 2022, 'label' => '2022 report', 'source_url' => 'https://www.eci.gov.in/report', 'state' => 'Uttar Pradesh'];
        $this->mock(HistoricalElectionAnalytics::class, function ($mock) use ($summary): void {
            $mock->shouldReceive('forState')->with('Uttar Pradesh', 'ac')->andReturn([$summary]);
        });
        $this->get('/india/state/uttar-pradesh')->assertOk()->assertSee('How turnout changed')->assertSee('80.0%')->assertSee('Party vote shares')->assertSee('Browse 1 constituency tables')->assertSee('2022 report');
        $this->get('/india/state/uttar-pradesh?edition='.str_repeat('b',24))->assertNotFound();
    }
}
