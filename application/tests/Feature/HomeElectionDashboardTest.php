<?php

namespace Tests\Feature;

use App\Services\HomeElectionSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomeElectionDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
    }

    private function record(int $code, string $name, ?int $electors, ?int $polled): array
    {
        return ['code' => $code, 'constituency_name' => $name, 'status' => 'validated', 'electors' => $electors, 'votes_polled' => $polled, 'candidates' => $polled === null ? [] : [
            ['candidate_name' => 'Winner', 'party_at_election' => 'AAA', 'votes' => (int) ($polled * .6)],
            ['candidate_name' => 'Runner', 'party_at_election' => 'BBB', 'votes' => $polled - (int) ($polled * .6)],
        ]];
    }

    private function edition(string $letter, string $kind, int $year, string $state, array $records): void
    {
        $id = str_repeat($letter, 24);
        $body = json_encode(['year' => $year, 'kind' => $kind, 'source_sha256' => str_repeat('f', 64), 'records' => $records], JSON_THROW_ON_ERROR);
        Storage::disk('local')->put('election-archive/'.$id.'/extraction.json', $body);
        foreach ($records as $record) {
            DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => $record['code'], 'kind' => $kind, 'year' => $year, 'edition_label' => (string) $year, 'state_label' => $state, 'constituency_name' => $record['constituency_name'], 'status' => 'validated', 'has_warning' => false, 'candidate_count' => count($record['candidates']), 'extraction_sha256' => hash('sha256', $body)]);
        }
    }

    public function test_weighted_national_turnout_counts_one_edition_per_state_year_and_preserves_missing_values(): void
    {
        $this->edition('a', 'pc', 2024, 'Uttar Pradesh', [$this->record(1, 'Seat A', 100, 50), $this->record(2, 'Seat B', 100, 50)]);
        $this->edition('b', 'pc', 2024, 'Uttar Pradesh', [$this->record(1, 'Seat A', 100, 50)]);
        $this->edition('c', 'pc', 2024, 'Punjab', [$this->record(1, 'Seat C', 900, 100)]);
        $this->edition('d', 'pc', 2026, 'Punjab', [$this->record(1, 'Seat C', null, null)]);
        $summary = app(HomeElectionSummary::class)->dashboard()['pc'];
        $this->assertEqualsWithDelta(100 * 200 / 1100, $summary['rows'][0]['turnout'], .0001);
        $this->assertSame(200, $summary['rows'][0]['polled']);
        $this->assertSame(3, $summary['rows'][0]['tables']);
        $this->assertSame(6, $summary['stats']['candidates']);
        $this->assertSame(2, $summary['stats']['parties']);
        $this->assertNull($summary['rows'][1]['turnout']);
        $this->assertNull($summary['rows'][1]['polled']);
        $this->assertEquals(60.0, $summary['rows'][0]['party0_share']);
        $this->assertSame(['AAA', 'BBB'], $summary['top_parties']);
    }

    public function test_assembly_sums_only_states_voting_in_same_calendar_year_and_cache_refreshes_with_index(): void
    {
        $this->edition('a', 'ac', 2020, 'Punjab', [$this->record(1, 'Seat A', 100, 50)]);
        $this->edition('b', 'ac', 2020, 'Bihar', [$this->record(1, 'Seat B', 300, 150)]);
        $summary = app(HomeElectionSummary::class)->dashboard()['ac'];
        $this->assertEquals(50.0, $summary['rows'][0]['turnout']);
        $this->assertSame(2, $summary['stats']['elections']);
        $this->edition('c', 'ac', 2021, 'Punjab', [$this->record(1, 'Seat C', 90, 40)]);
        $updated = app(HomeElectionSummary::class)->dashboard()['ac'];
        $this->assertCount(2, $updated['rows']);
        $this->assertSame(3, $updated['stats']['elections']);
        $this->assertSame(40, $updated['rows'][1]['polled']);
    }

    public function test_top_five_excludes_nota_from_candidate_count_but_keeps_its_votes_in_share_denominator(): void
    {
        $record = $this->record(1, 'Seat A', 200, 100);
        $record['candidates'] = [];
        foreach ([30, 25, 20, 10, 7, 5, 3] as $i => $votes) {
            $record['candidates'][] = ['candidate_name' => 'Candidate '.$i, 'party_at_election' => $i === 6 ? 'NOTA' : 'Party '.$i, 'votes' => $votes, 'is_nota' => $i === 6];
        }
        $this->edition('a', 'pc', 2024, 'Punjab', [$record]);
        $summary = app(HomeElectionSummary::class)->dashboard()['pc'];
        $this->assertCount(5, $summary['top_parties']);
        $this->assertSame('Party 0', $summary['top_parties'][0]);
        $this->assertEquals(30, $summary['rows'][0]['party0_share']);
        $this->assertEquals(7, $summary['rows'][0]['party4_share']);
        $this->assertSame(6, $summary['stats']['parties']);
        $this->assertSame(6, $summary['stats']['candidates']);
    }

    public function test_changed_source_bytes_are_not_graphed_until_the_index_matches(): void
    {
        $this->edition('a', 'pc', 2024, 'Punjab', [$this->record(1, 'Seat A', 100, 50)]);
        Storage::disk('local')->put('election-archive/'.str_repeat('a', 24).'/extraction.json', '{"records":[]}');
        $summary = app(HomeElectionSummary::class)->dashboard()['pc'];
        $this->assertSame([], $summary['rows']);
        $this->assertSame(0, $summary['stats']['results']);
    }

    public function test_home_has_three_full_width_sections_and_finder_returns_latest_constituencies_by_kind_and_state(): void
    {
        $this->edition('a', 'pc', 2019, 'Punjab', [$this->record(1, 'Former Seat', 100, 50)]);
        $this->edition('b', 'pc', 2024, 'Punjab', [$this->record(1, 'Current Seat', 100, 60)]);
        $this->edition('c', 'ac', 2022, 'Punjab', [$this->record(1, 'Assembly Seat', 100, 60)]);
        $page = $this->get('/')->assertOk()->assertSeeInOrder(['id="lok-sabha"', 'id="assembly"', 'id="census-places"'], false)->assertSee('Top five parties')->assertDontSee('dashboard-sidebar')->assertSee('/india/state/punjab#pc-history', false)->assertSee('/india/state/punjab#ac-history', false);
        $this->assertSame(6, substr_count($page->getContent(), 'data-history-chart'));
        $this->getJson('/?finder=1&kind=pc&state=Punjab')->assertOk()->assertJsonCount(1, 'seats')->assertJsonPath('seats.0.name', 'Current Seat')->assertJsonPath('seats.0.url', route('constituency.overview', ['kind' => 'pc', 'state' => 'Punjab', 'name' => 'Current Seat']));
        $this->getJson('/?finder=1&kind=ac&state=Punjab')->assertOk()->assertJsonPath('seats.0.name', 'Assembly Seat');
        $this->getJson('/?finder=1&kind=pc&state=Goa')->assertOk()->assertJsonCount(0, 'seats');
        $this->getJson('/?finder=1&kind=other&state=Punjab')->assertUnprocessable();
    }

    public function test_delhi_finder_recognizes_both_official_state_labels_and_codes(): void
    {
        $this->edition('a', 'pc', 2019, 'u05', [$this->record(1, 'New Delhi', 100, 50)]);
        $this->edition('b', 'pc', 2024, 'NCT OF Delhi', [$this->record(1, 'New Delhi', 100, 60)]);
        $this->getJson('/?finder=1&kind=pc&state=Delhi')->assertOk()->assertJsonCount(1, 'seats')->assertJsonPath('seats.0.url', route('constituency.overview', ['kind' => 'pc', 'state' => 'Delhi', 'name' => 'New Delhi']));
    }
}
