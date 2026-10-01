<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionArchive;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_groups_years_and_overview_defaults_to_history(): void
    {
        foreach ([2019, 2024] as $year) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($year === 2024 ? 'a' : 'b', 24), 'record_code' => 1, 'kind' => 'pc', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => $year === 2019 ? 'UTTAR PRADESH' : 'Uttar Pradesh', 'constituency_name' => 'Lucknow', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 1, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->getJson('/search?q=Lucknow')->assertOk()->assertJsonCount(1, 'suggestions')->assertJsonPath('suggestions.0.type', 'Parliament (PC)')->assertJsonPath('suggestions.0.period', 'Uttar Pradesh')->assertJsonPath('suggestions.0.label', 'Lucknow');
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [['code' => 1, 'status' => 'validated', 'winner' => 'Example winner', 'candidates' => [['candidate_name' => 'Example winner', 'party_at_election' => 'Example party', 'votes' => 100]], 'number_of_seats' => 1]]]]);
        });
        $url = '/india/constituency?kind=pc&state=UTTAR%20PRADESH&name=lucknow';
        $this->get($url)->assertOk()->assertSee('Election history')->assertSee('Example winner')->assertSee('Election winners may differ from current representatives')->assertSee('2019')->assertSee('2024')->assertSee('2024 results')->assertSee('How voting has changed')->assertSee('Election year')->assertSee('Location map')->assertSee('Registered electors and votes polled')->assertSee('Absolute counts, not percentages');
        $this->get($url.'&edition='.str_repeat('a', 24))->assertOk()->assertSee('2024 results')->assertSee('Example party');
        $this->get($url.'&edition='.str_repeat('d', 24))->assertNotFound();
        $this->get('/india/elections/lok-sabha?edition='.str_repeat('a', 24).'&state=Uttar%20Pradesh&code=1')->assertRedirect(route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'Lucknow', 'edition' => str_repeat('a', 24), 'code' => 1]));
    }

    public function test_same_name_seats_keep_their_official_record_codes(): void
    {
        $edition = str_repeat('a', 24);
        foreach ([10, 20] as $code) {
            DB::table('historical_constituency_index')->insert(['edition_id' => $edition, 'record_code' => $code,
                'kind' => 'ac', 'year' => 2007, 'edition_label' => '2007', 'state_label' => 'Uttar Pradesh',
                'constituency_name' => 'Nawabganj', 'status' => 'validated', 'has_warning' => false,
                'candidate_count' => 1, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://eci.gov.in',
                'source_sha256' => str_repeat('c', 64), 'records' => [
                    ['code' => 10, 'status' => 'validated', 'candidates' => [['candidate_name' => 'First seat candidate', 'party_at_election' => 'A', 'votes' => 10]]],
                    ['code' => 20, 'status' => 'validated', 'candidates' => [['candidate_name' => 'Second seat candidate', 'party_at_election' => 'B', 'votes' => 20]]],
                ]]]);
        });

        $base = route('constituency.overview', ['kind' => 'ac', 'state' => 'Uttar Pradesh', 'name' => 'Nawabganj']);
        $this->get($base)->assertRedirect(route('elections.constituencies', ['kind' => 'ac', 'state' => 'Uttar Pradesh', 'q' => 'Nawabganj']));
        $this->get($base.'&edition='.$edition.'&code=20')->assertOk()
            ->assertSee('Second seat candidate')->assertDontSee('First seat candidate')
            ->assertSee('This page shows the selected seat');
        $this->get('/india/elections/assembly?edition='.$edition.'&state=Uttar%20Pradesh&code=20')
            ->assertRedirect(route('constituency.overview', ['kind' => 'ac', 'state' => 'Uttar Pradesh',
                'name' => 'Nawabganj', 'edition' => $edition, 'code' => 20]));
    }

    public function test_legacy_pilibhit_profile_opens_shared_history_template(): void
    {
        $this->seed(PilibhitSeeder::class);
        DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat('a', 24), 'record_code' => 26, 'kind' => 'pc', 'year' => 2024, 'edition_label' => '2024', 'state_label' => 'Uttar Pradesh', 'constituency_name' => 'Pilibhit', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 1, 'extraction_sha256' => str_repeat('c', 64)]);
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [['code' => 26, 'status' => 'needs_review', 'candidates' => []]]]]);
        });
        $this->get(route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'Pilibhit']))->assertOk()->assertSee('Connected places')->assertSee('Baheri')->assertSee('Bareilly')->assertSee('Puranpur');
        $this->get('/india/pc/pilibhit')->assertRedirect(route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'Pilibhit']));
    }

    public function test_related_seats_use_official_mapping_without_legacy_profiles(): void
    {
        foreach ([['pc', 'Aonla', 1], ['ac', 'Bithari Chainpur', 2]] as [$kind,$name,$code]) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat('a', 24), 'record_code' => $code, 'kind' => $kind, 'year' => 2024, 'edition_label' => '2024', 'state_label' => 'Uttar Pradesh', 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 0, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['records' => []]]);
        });
        $this->get(route('constituency.overview', ['kind' => 'pc', 'state' => 'UTTAR PRADESH', 'name' => 'Aonla']))->assertOk()->assertSee('Connected places')->assertSee('Bithari Chainpur')->assertSee('Bareilly')->assertSee('Official district reference')->assertSee(route('constituency.overview', ['kind' => 'ac', 'state' => 'Uttar Pradesh', 'name' => 'Bithari Chainpur']));
        $this->assertDatabaseCount('places', 0);
    }

    public function test_2009_turnout_with_source_discrepancy_stays_visible_and_identical_2019_charts_are_not_repeated(): void
    {
        $edition2009 = str_repeat('d', 24);
        foreach ([[$edition2009, 2009, 404, '2009 Vol I, II, III'], [str_repeat('a', 24), 2019, 387, '2019 (Including Vellore PC)'], [str_repeat('b', 24), 2019, 387, '2019 (Excluding Vellore PC)']] as [$edition,$year,$code,$label]) {
            DB::table('historical_constituency_index')->insert(['edition_id' => $edition, 'record_code' => $code, 'kind' => 'pc', 'year' => $year, 'edition_label' => $label, 'state_label' => 'Uttar Pradesh', 'constituency_name' => 'Pilibhit', 'status' => $year === 2009 ? 'needs_review' : 'validated', 'has_warning' => $year === 2009, 'candidate_count' => 2, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($edition2009): void {
            $mock->shouldReceive('load')->andReturnUsing(function (string $edition) use ($edition2009): array {
                $is2009 = $edition === $edition2009;

                return [['source_url' => 'https://www.eci.gov.in/statistical-reports', 'source_sha256' => str_repeat('c', 64), 'records' => [[
                    'code' => $is2009 ? 404 : 387, 'status' => $is2009 ? 'needs_review' : 'validated', 'number_of_seats' => 1,
                    'electors' => $is2009 ? 1310007 : 100, 'votes_polled' => $is2009 ? 837929 : 60,
                    'detail_page' => 149, 'summary_page' => 404,
                    'valid_candidate_votes' => $is2009 ? 557577 : 60,
                    'summary_totals' => $is2009 ? ['electors' => 1310007, 'votes_polled' => 837929, 'valid_candidate_votes' => 557567] : null,
                    'error' => $is2009 ? 'Summary and detailed totals differ' : null,
                    'candidates' => [
                        ['candidate_name' => 'Candidate A', 'party_at_election' => 'AAA', 'votes' => $is2009 ? 419539 : 40],
                        ['candidate_name' => 'Candidate B', 'party_at_election' => 'BBB', 'votes' => $is2009 ? 138038 : 20],
                    ],
                ]]]];
            });
        });

        $url = route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'Pilibhit']);
        $overview = $this->get($url)->assertOk()->assertSee('63.96% †')->assertSee('281,501 †')->assertSee('2019 (Including Vellore PC)')->assertSee('2019 (Excluding Vellore PC)');
        preg_match_all('/class="history-chart-data">(.*?)<\/script>/s', $overview->getContent(), $charts);
        $this->assertCount(4, $charts[1]);
        $chart = json_decode($charts[1][0], true);
        $this->assertSame([2009, 2019], array_column($chart['rows'], 'year'));
        $this->assertEquals(60, $chart['rows'][1]['polled']);
        preg_match('/<select name="edition" id="edition">(.*?)<\/select>/s', $overview->getContent(), $selector);
        $this->assertSame(2, substr_count($selector[1], '<option'));
        $this->assertStringNotContainsString('Vol', $selector[1]);
        $this->assertStringNotContainsString('Vellore', $selector[1]);
        $this->get($url.'&edition='.$edition2009)->assertOk()->assertSee('837,929 †')->assertSee('Summary and detailed totals differ')->assertSee('Candidate A †')->assertSee('281,501 †')->assertSee('Report a problem with this result');
    }

    public function test_state_case_and_official_codes_resolve_across_states(): void
    {
        foreach ([['KARNATAKA', 'Karnataka', 'S10', 'Example Karnataka'], ['ASSAM', 'Assam', 'S03', 'Example Assam'], ['UTTAR PRADESH', 'Uttar Pradesh', 'S24', 'Example UP']] as [$upper,$label,$code,$name]) {
            foreach ([$upper, $label, $code] as $i => $variant) {
                DB::table('historical_constituency_index')->insert(['edition_id' => substr(hash('sha256', $name.$i), 0, 24), 'record_code' => 1, 'kind' => 'pc', 'year' => 2004 + $i * 10, 'edition_label' => (string) (2004 + $i * 10), 'state_label' => $variant, 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 0, 'extraction_sha256' => str_repeat('c', 64)]);
            }
        }
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['records' => []]]);
        });
        foreach (['KARNATAKA' => 'Example Karnataka', 'ASSAM' => 'Example Assam', 'UTTAR PRADESH' => 'Example UP'] as $state => $name) {
            $this->get(route('constituency.overview', ['kind' => 'pc', 'state' => $state, 'name' => $name]))->assertOk()->assertSee('2004')->assertSee('2014')->assertSee('2024')->assertSee('3 available years');
            $this->getJson('/search?q='.urlencode($name))->assertOk()->assertJsonCount(1, 'suggestions');
        }
    }

    public function test_party_lines_rank_parties_once_per_year_and_preserve_missing_figures(): void
    {
        $record = ['code' => 1, 'status' => 'validated', 'number_of_seats' => 1, 'electors' => 400, 'votes_polled' => 200,
            'candidates' => array_map(fn ($party, $votes) => ['candidate_name' => $party, 'party_at_election' => $party, 'votes' => $votes], ['A', 'B', 'C', 'D', 'NOTA'], [80, 60, 40, 16, 4])];
        $rows = collect([
            ['entry' => (object) ['year' => 2019], 'record' => $record],
            ['entry' => (object) ['year' => 2019], 'record' => $record],
            ['entry' => (object) ['year' => 2024], 'record' => null],
        ]);
        $html = view('place-history-charts', compact('rows'))->render();
        preg_match_all('/class="history-chart-data">(.*?)<\/script>/s', $html, $matches);
        $party = json_decode($matches[1][3], true);
        $this->assertSame(['A', 'B', 'C', 'Others'], array_column($party['series'], 'label'));
        $this->assertCount(2, $party['rows']);
        $this->assertEquals(80, $party['rows'][0]['party0']);
        $this->assertEquals(20, $party['rows'][0]['others']);
        $this->assertNull($party['rows'][1]['party0']);
        $this->assertNull($party['rows'][1]['others']);
        $this->assertSame('%', $party['unit']);
        $this->assertEquals(40, $party['rows'][0]['party0_share']);
        $this->assertEquals(10, $party['rows'][0]['others_share']);
        $this->assertNull($party['rows'][1]['party0_share']);
        $this->assertNull($party['rows'][1]['others_share']);
        $this->assertStringContainsString('80 (40.00%)', $html);
        $this->assertStringContainsString('20 (10.00%)', $html);
    }
}
