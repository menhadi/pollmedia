<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyArchiveHistory;
use App\Services\ElectionArchive;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyManipurSpellingHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function index(string $id, int $year, string $name, int $code = 1): void
    {
        DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($id, 24), 'record_code' => $code, 'kind' => 'ac', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Manipur', 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 2, 'extraction_sha256' => str_repeat('c', 64)]);
    }

    private function mockRecords(): void
    {
        $this->mock(HistoricalElectionArchive::class, function ($mock): void {
            $mock->shouldReceive('load')->andReturnUsing(function (string $edition): array {
                $year = ['a' => 2012, 'b' => 2017, 'c' => 2022, 'd' => 2007][$edition[0]];
                $votes = ['a' => 120, 'b' => 140, 'c' => 160, 'd' => 100][$edition[0]];

                return [['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [
                    ['code' => 4, 'status' => 'validated', 'number_of_seats' => 1, 'electors' => 400, 'votes_polled' => $votes + 80, 'winner' => 'Winner '.$year, 'margin' => $votes - 80,
                        'candidates' => [['candidate_name' => 'Winner '.$year, 'party_at_election' => 'INC', 'votes' => $votes], ['candidate_name' => 'Runner', 'party_at_election' => 'BJP', 'votes' => 80]]],
                    ['code' => 7, 'status' => 'validated', 'number_of_seats' => 1, 'electors' => 400, 'votes_polled' => 180, 'winner' => 'Other code winner', 'margin' => 20,
                        'candidates' => [['candidate_name' => 'Other code winner', 'party_at_election' => 'BJP', 'votes' => 100], ['candidate_name' => 'Runner', 'party_at_election' => 'INC', 'votes' => 80]]],
                ]]];
            });
        });
    }

    public function test_official_spellings_share_history_without_rewriting_names(): void
    {
        $this->index('a', 2012, 'Kshetrigao', 4);
        $this->index('b', 2017, 'Kshetrigao (GEN)', 4);
        $this->index('c', 2022, 'Khetrigao', 4);
        $this->index('d', 2007, 'Khetrigao', 7);
        $this->mockRecords();
        foreach (['Kshetrigao', 'Khetrigao'] as $name) {
            $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => $name]))->assertOk()
                ->assertViewHas('rows', function ($rows) use ($name): bool {
                    $names = $rows->pluck('entry.constituency_name');

                    return $rows->count() === ($name === 'Khetrigao' ? 4 : 3)
                        && $names->contains('Kshetrigao') && $names->contains('Kshetrigao (GEN)') && $names->contains('Khetrigao');
                });
            $dom = new \DOMDocument;
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            $chartNodes = $xpath->query('//script[@class="history-chart-data"]');
            $this->assertSame(4, $chartNodes->length);
            foreach ([2012 => [200, 50, 40, 120], 2017 => [220, 55, 60, 140], 2022 => [240, 60, 80, 160]] as $year => [$polled, $turnout, $margin, $winnerVotes]) {
                $tableRow = $xpath->query('//section[@id="history"]//tbody/tr[th="'.$year.'"]');
                $this->assertSame(1, $tableRow->length);
                $cells = $xpath->query('./td', $tableRow->item(0));
                $this->assertSame('Winner '.$year, trim($cells->item(0)->textContent));
                $this->assertSame((string) $polled, trim($cells->item(2)->textContent));
                $this->assertSame(number_format($turnout, 2), trim($cells->item(3)->textContent));
                $this->assertSame((string) $margin, trim($cells->item(4)->textContent));
                foreach ($chartNodes as $chartNode) {
                    $chart = json_decode($chartNode->textContent, true);
                    $point = collect($chart['rows'])->firstWhere('year', $year);
                    $this->assertNotNull($point);
                    $this->assertEquals(400, $point['electors']);
                    $this->assertEquals($polled, $point['polled']);
                    $this->assertEquals($turnout, $point['turnout']);
                    $this->assertEquals($margin, $point['margin']);
                    $this->assertEquals($winnerVotes, $point['party0']);
                    $this->assertEqualsWithDelta(100 * $winnerVotes / $polled, $point['party0_share'], 0.001);
                }
            }
        }
    }

    public function test_other_states_kinds_and_codes_do_not_gain_alias_records(): void
    {
        $this->index('a', 2012, 'Kshetrigao', 4);
        $this->index('b', 2022, 'Khetrigao', 7);
        $this->mockRecords();
        foreach ([['ac', 'Manipur'], ['pc', 'Manipur'], ['ac', 'Tripura']] as [$kind, $state]) {
            DB::table('historical_constituency_index')->update(['kind' => $kind, 'state_label' => $state]);
            DB::table('historical_constituency_index')->where('edition_id', str_repeat('b', 24))->update(['record_code' => $kind === 'ac' && $state === 'Manipur' ? 7 : 4]);
            $this->get(route('constituency.overview', ['kind' => $kind, 'state' => $state, 'name' => 'Kshetrigao']))->assertOk()
                ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()['entry']->constituency_name === 'Kshetrigao');
        }
        $this->assertSame([], ConstituencyArchiveHistory::manipurAssemblyAliasNames('ac', 'Manipur', 'Kshetrigao (ST)'));
    }

    public function test_alias_retains_same_edition_ambiguity_guard(): void
    {
        $this->index('a', 2017, 'Kshetrigao', 7);
        $this->index('a', 2017, 'Khetrigao', 4);
        $this->mockRecords();
        $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => 'Kshetrigao']))
            ->assertRedirect(route('elections.constituencies', ['kind' => 'ac', 'state' => 'Manipur', 'q' => 'Kshetrigao']));
    }

    public function test_unindexed_alias_matches_only_manipur_assembly_code_four_and_preserves_raw_names(): void
    {
        $source = collect(app(ElectionArchive::class)->nationalAssemblyEntries())->first(fn ($entry) => $entry['state'] === 'Manipur' && str_starts_with($entry['label'], '2017'));
        $url = $source['url'];
        $id = substr(hash('sha256', $url), 0, 24);
        $path = 'election-archive/'.$id.'/extraction.json';
        $records = [
            ['code' => 4, 'name' => 'Kshetrigao (GEN)', 'state_name' => 'Manipur', 'candidates' => []],
            ['code' => 7, 'name' => 'Kshetrigao', 'state_name' => 'Manipur', 'candidates' => []],
            ['code' => 8, 'name' => 'Khetrigao', 'state_name' => 'Manipur', 'candidates' => []],
            ['code' => 4, 'name' => 'Kshetrigao', 'state_name' => 'Tripura', 'candidates' => []],
        ];
        $body = json_encode(['source_url' => $url, 'kind' => 'ac', 'year' => 2017, 'source_file' => 'source.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => $records]);
        DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'body' => $body]);
        $this->mock(ArchiveFiles::class, function ($mock) use ($path, $body, $id, $url): void {
            $mock->shouldReceive('get')->with($path)->andReturn($body);
            $mock->shouldReceive('get')->with('election-archive/'.$id.'/manifest.json')->andReturn(json_encode(['url' => $url, 'files' => [['file' => 'source.pdf', 'sha256' => str_repeat('f', 64)]]]));
        });
        $history = app(ConstituencyArchiveHistory::class);
        $rows = $history->missingEntries('ac', 'Manipur', 'Khetrigao', collect());
        $this->assertSame(['Kshetrigao (GEN)', 'Khetrigao'], $rows->pluck('constituency_name')->all());
        $this->assertCount(2, $history->missingEntries('ac', 'Manipur', 'Kshetrigao', collect()));
        $this->assertCount(0, $history->missingEntries('ac', 'Tripura', 'Khetrigao', collect()));
        $this->assertCount(0, $history->missingEntries('pc', 'Manipur', 'Khetrigao', collect()));
        $this->assertSame($body, DB::table('archive_json_files')->value('body'));
    }
}
