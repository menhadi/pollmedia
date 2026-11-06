<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyArchiveHistory;
use App\Services\ElectionArchive;
use App\Services\ElectionGeographySummary;
use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use App\Services\HistoricalElectionReview;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_identical_results_in_the_known_2019_revision_pair_are_displayed_once(): void
    {
        $record = ['code' => 255, 'status' => 'validated', 'state_name' => 'Manipur', 'constituency_name' => 'Inner Manipur',
            'detail_page' => 311, 'votes_polled' => 100, 'candidates' => [
                ['candidate_name' => 'Winner', 'party_at_election' => 'A', 'votes' => 60, 'source_row' => 1],
                ['candidate_name' => 'Runner', 'party_at_election' => 'B', 'votes' => 40, 'source_row' => 2],
            ]];
        $first = ['entry' => (object) ['year' => 2019, 'record_code' => 255, 'edition_id' => '2e749f2174f08a9ea1fc803d'],
            'record' => $record, 'result' => ['winner' => 'Winner', 'margin' => 20]];
        $second = $first;
        $second['entry'] = (object) ['year' => 2019, 'record_code' => 255, 'edition_id' => '70e603b1037bf7ca8e1350b0'];
        $second['record']['detail_page'] = 310;
        $second['record']['candidates'] = array_reverse($record['candidates']);
        $second['record']['candidates'][0]['source_row'] = 9;
        $reviews = app(HistoricalElectionReview::class);
        $first['record'] = $reviews->apply($first['entry']->edition_id, $first['record'], str_repeat('a', 64));
        $second['record'] = $reviews->apply($second['entry']->edition_id, $second['record'], str_repeat('b', 64));
        $this->assertNotSame($first['record']['review_fingerprint'], $second['record']['review_fingerprint']);
        $rows = collect([$first, $second]);
        $analytics = app(HistoricalElectionAnalytics::class);
        $this->assertCount(1, $analytics->distinctConstituencyHistoryRows($rows));
        $this->assertCount(2, $rows);
        $this->assertSame($first['record']['review_fingerprint'], $rows[0]['record']['review_fingerprint']);
        $this->assertSame($second['record']['review_fingerprint'], $rows[1]['record']['review_fingerprint']);
        foreach (['votes', 'round', 'warning', 'review_id', 'unknown_edition'] as $difference) {
            $different = $second;
            if ($difference === 'unknown_edition') {
                $different['entry'] = (object) ['year' => 2019, 'record_code' => 255, 'edition_id' => str_repeat('a', 24)];
            } elseif ($difference === 'votes') {
                $different['record']['candidates'][0]['votes']++;
            } elseif ($difference === 'review_id') {
                $different['record']['review_id'] = 1;
            } else {
                $different['record'][$difference] = 'Different source information';
            }
            $this->assertCount(2, $analytics->distinctConstituencyHistoryRows(collect([$first, $different])), $difference);
        }
    }

    public function test_delhi_source_history_does_not_repeat_geography_queries_for_each_record(): void
    {
        [$label, $url] = collect(app(ElectionArchive::class)->catalogue()['pc'])->first(fn ($entry) => str_starts_with($entry[0], '2004'));
        $id = substr(hash('sha256', $url), 0, 24);
        $path = 'election-archive/'.$id.'/extraction.json';
        $records = [];
        for ($i = 1; $i <= 100; $i++) {
            $records[] = ['code' => $i, 'state_name' => 'NCT OF Delhi', 'constituency_name' => $i === 1 ? 'NEW DELHI' : 'Other seat '.$i, 'candidates' => []];
        }
        $body = json_encode(['source_url' => $url, 'kind' => 'pc', 'year' => 2004, 'source_file' => 'source.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => $records]);
        DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'body' => $body]);
        $this->mock(ArchiveFiles::class, function ($mock) use ($path, $body, $id, $url): void {
            $mock->shouldReceive('get')->with($path)->once()->andReturn($body);
            $mock->shouldReceive('get')->with('election-archive/'.$id.'/manifest.json')->once()->andReturn(json_encode(['url' => $url, 'files' => [['file' => 'source.pdf', 'sha256' => str_repeat('f', 64)]]]));
        });
        $this->mock(ElectionGeographySummary::class)->shouldNotReceive('states');
        $rows = app(ConstituencyArchiveHistory::class)->missingEntries('pc', 'Delhi', 'New Delhi', collect());
        $this->assertCount(1, $rows);
        $this->assertSame('NEW DELHI', $rows->first()->constituency_name);
    }

    public function test_new_delhi_overview_matches_official_delhi_labels_across_years(): void
    {
        foreach ([['a', 2024, 'NCT OF Delhi'], ['b', 2019, 'u05'], ['c', 2004, 'Delhi']] as [$id, $year, $state]) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($id, 24), 'record_code' => 1, 'kind' => 'pc', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => $state, 'constituency_name' => 'NEW DELHI', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 1, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [['code' => 1, 'name' => 'NEW DELHI', 'status' => 'validated', 'winner' => 'Example winner', 'candidates' => [['candidate_name' => 'Example winner', 'party_at_election' => 'Example party', 'votes' => 100]], 'number_of_seats' => 1]]]]);
        });
        foreach (['Delhi', 'NCT OF Delhi', 'u05'] as $state) {
            $this->get(route('constituency.overview', ['kind' => 'pc', 'state' => $state, 'name' => 'NEW DELHI']))->assertOk()->assertSee('2024 results')->assertSee('2019')->assertSee('2004')->assertSee('Example winner');
        }
    }

    public function test_search_groups_years_and_overview_defaults_to_history(): void
    {
        foreach ([2019, 2024] as $year) {
            DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($year === 2024 ? 'a' : 'b', 24), 'record_code' => 1, 'kind' => 'pc', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => $year === 2019 ? 'UTTAR PRADESH' : 'Uttar Pradesh', 'constituency_name' => 'Lucknow', 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 1, 'extraction_sha256' => str_repeat('c', 64)]);
        }
        $this->getJson('/search?q=Lucknow')->assertOk()->assertJsonCount(1, 'suggestions')->assertJsonPath('suggestions.0.type', 'Lok Sabha')->assertJsonPath('suggestions.0.period', 'Uttar Pradesh')->assertJsonPath('suggestions.0.label', 'Lucknow');
        $this->mock(HistoricalElectionArchive::class, function ($mock) {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [['code' => 1, 'status' => 'validated', 'winner' => 'Example winner', 'candidates' => [['candidate_name' => 'Example winner', 'party_at_election' => 'Example party', 'votes' => 100]], 'number_of_seats' => 1]]]]);
        });
        $url = '/india/constituency?kind=pc&state=UTTAR%20PRADESH&name=lucknow';
        $this->get($url)->assertOk()->assertSee('Election history')->assertSee('Example winner')->assertSee('Election winners may differ from current representatives')->assertSee('2019')->assertSee('2024')->assertSee('2024 results')->assertSee('How voting has changed')->assertSee('Election year')->assertSee('data-selected="lucknow"', false)->assertSee('data-mode="focus"', false)->assertSeeInOrder(['Lok Sabha map', 'How voting has changed'])->assertDontSee('google.com/maps')->assertSee('Registered electors, votes polled and turnout')->assertSee('Margin, registered electors and votes polled are absolute counts.');
        $this->get($url.'&edition='.str_repeat('a', 24))->assertOk()->assertSee('2024 results')->assertSee('Example party');
        $this->get($url.'&edition='.str_repeat('d', 24))->assertNotFound();
        $this->get($url.'&format=report')->assertOk()->assertSee('Print / Save as PDF')->assertSee('Sources and data notes')->assertSee('https://eci.gov.in')->assertSee('<svg', false)->assertDontSee('<select', false)->assertDontSee('<details', false)->assertDontSee('history-lines.js')->assertDontSee('data-history-chart');
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

    public function test_source_vote_count_remains_visible_without_an_invalid_turnout_percentage(): void
    {
        $edition = str_repeat('e', 24);
        DB::table('historical_constituency_index')->insert([
            'edition_id' => $edition, 'record_code' => 7, 'kind' => 'ac', 'year' => 1957,
            'edition_label' => '1957', 'state_label' => 'Example State', 'constituency_name' => 'Example Seat',
            'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => 2,
            'extraction_sha256' => str_repeat('c', 64),
        ]);
        $this->mock(HistoricalElectionArchive::class, function ($mock): void {
            $mock->shouldReceive('load')->andReturn([[
                'source_url' => 'https://eci.gov.in/example-report.pdf',
                'source_sha256' => str_repeat('c', 64),
                'records' => [[
                    'code' => 7, 'status' => 'needs_review', 'number_of_seats' => 2,
                    'electors' => 100, 'votes_polled' => 150,
                    'error' => 'Multi-member vote count exceeds electors',
                    'candidates' => [
                        ['candidate_name' => 'Candidate A', 'party_at_election' => 'AAA', 'votes' => 80],
                        ['candidate_name' => 'Candidate B', 'party_at_election' => 'BBB', 'votes' => 70],
                    ],
                ]],
            ]]);
        });

        $url = route('constituency.overview', ['kind' => 'ac', 'state' => 'Example State', 'name' => 'Example Seat']);
        $this->get($url)->assertOk()->assertSee('150 ‡')->assertSee('Vote count from the linked source record')
            ->assertSee('Multi-member vote count exceeds electors')->assertDontSee('150.00%');
        $this->get($url.'&format=report')->assertOk()->assertSee('150 ‡')
            ->assertSee('Vote count from the linked source record');
    }

    public function test_puranpur_displays_documented_1996_and_2007_source_values_with_notes(): void
    {
        foreach ([['1cc8415ab4d57b66831417e8', 1996, 60, 6], ['174ec81b511a8fb1aeca553f', 2007, 44, 16]] as [$edition, $year, $code, $candidates]) {
            DB::table('historical_constituency_index')->insert(['edition_id' => $edition, 'record_code' => $code, 'kind' => 'ac', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Uttar Pradesh', 'constituency_name' => 'PURANPUR', 'status' => 'needs_review', 'has_warning' => true, 'candidate_count' => $candidates, 'extraction_sha256' => str_repeat('c', 64)]);
        }

        $url = route('constituency.overview', ['kind' => 'ac', 'state' => 'Uttar Pradesh', 'name' => 'PURANPUR']);
        $this->get($url)->assertOk()
            ->assertSee('170,064 †')->assertSee('ARSHAD KHAN †')->assertSee('6,267 †')
            ->assertSee('170,352 †')->assertSee('GOPAL KRISHNA †')->assertSee('4,452 †');
        $this->get($url.'&edition=174ec81b511a8fb1aeca553f')->assertOk()
            ->assertSee('Candidate sum 170056; detailed total 170056; summary valid votes 170060. Totals differ.')
            ->assertSee('Check the official report');
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
        $later = $record;
        $later['candidates'] = array_map(fn ($party, $votes) => ['candidate_name' => $party, 'party_at_election' => $party, 'votes' => $votes], ['C', 'D', 'A', 'B', 'NOTA'], [90, 70, 20, 16, 4]);
        $rows = collect([
            ['entry' => (object) ['year' => 2019], 'record' => $record],
            ['entry' => (object) ['year' => 2019], 'record' => $record],
            ['entry' => (object) ['year' => 2024], 'record' => null],
            ['entry' => (object) ['year' => 2029], 'record' => $later],
        ]);
        $html = view('place-history-charts', compact('rows'))->render();
        preg_match_all('/class="history-chart-data">(.*?)<\/script>/s', $html, $matches);
        $fixed = json_decode($matches[1][0], true);
        $this->assertCount(4, $matches[1]);
        $this->assertSame(['C', 'A', 'D'], array_column($fixed['series'], 'label'));
        $this->assertSame('%', $fixed['unit']);
        $this->assertEquals(20, $fixed['rows'][0]['fixed0_share']);
        $this->assertEquals(45, $fixed['rows'][2]['fixed0_share']);
        $this->assertNull($fixed['rows'][1]['fixed0_share']);
        $this->assertEquals(40, $fixed['rows'][0]['fixed0']);
        $this->assertLessThan(strpos($html, '<h3>Party vote shares by year'), strpos($html, '<h3>Top three parties across the years'));
        $party = json_decode($matches[1][1], true);
        $this->assertSame(['1st party', '2nd party', 'Others'], array_column($party['series'], 'label'));
        $this->assertCount(3, $party['rows']);
        $this->assertSame('A', $party['rows'][0]['party0_name']);
        $this->assertSame('B', $party['rows'][0]['party1_name']);
        $this->assertSame('C', $party['rows'][2]['party0_name']);
        $this->assertSame('D', $party['rows'][2]['party1_name']);
        $this->assertEquals(45, $party['rows'][2]['party0_share']);
        $this->assertEquals(20, $party['rows'][2]['others_share']);
        $this->assertLessThan(strpos($html, '<h3>Registered electors, votes polled and turnout'), strpos($html, '<h3>Party vote shares by year'));
        $combined = json_decode($matches[1][2], true);
        $this->assertSame(['electors', 'polled', 'turnout'], array_column($combined['series'], 'key'));
        $this->assertSame('right', $combined['series'][2]['axis']);
        $this->assertTrue($combined['rightAutoScale']);
        $this->assertSame(['electors', 'margin'], array_map(fn ($json) => json_decode($json, true)['series'][0]['key'], array_slice($matches[1], 2)));
        $this->assertStringContainsString('&amp;z=7&amp;', view('place-location-map', ['mapName' => 'Pilibhit', 'mapQuery' => 'Pilibhit, India'])->render());
        $this->assertEquals(80, $party['rows'][0]['party0']);
        $this->assertEquals(60, $party['rows'][0]['others']);
        $this->assertNull($party['rows'][1]['party0']);
        $this->assertNull($party['rows'][1]['others']);
        $this->assertSame('%', $party['unit']);
        $this->assertEquals(40, $party['rows'][0]['party0_share']);
        $this->assertEquals(30, $party['rows'][0]['others_share']);
        $this->assertNull($party['rows'][1]['party0_share']);
        $this->assertNull($party['rows'][1]['others_share']);
        $this->assertStringContainsString('80 (40.00%)', $html);
        $this->assertStringContainsString('60 (30.00%)', $html);
        $report = view('place-history-charts', ['rows' => $rows, 'reportMode' => true])->render();
        $this->assertSame(4, substr_count($report, '<svg'));
        $this->assertSame(4, substr_count($report, '<table>'));
        $this->assertStringNotContainsString('<details', $report);
        $this->assertStringNotContainsString('data-history-chart', $report);
        $this->assertStringContainsString('60 (30.00%)', $report);
    }

    public function test_print_chart_uses_straight_lines_without_point_markers(): void
    {
        $plotRows = collect([['year' => 2020, 'turnout' => 50], ['year' => 2024, 'turnout' => 60]]);
        $plot = ['title' => 'Voter turnout', 'unit' => '%', 'series' => [['key' => 'turnout', 'label' => 'Turnout']]];
        $html = view('history-static-plot', compact('plotRows', 'plot'))->render();

        $this->assertMatchesRegularExpression('/<path d="M[^\"]*L[^\"]*"/', $html);
        $this->assertStringNotContainsString('<circle', $html);
    }
}
