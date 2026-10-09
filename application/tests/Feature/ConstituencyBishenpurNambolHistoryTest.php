<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyArchiveHistory;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyBishenpurNambolHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = '48bac24675875f468956cf9e';

    private const EARLY = '496d7edbfe44e6b6cf4b312b';

    private const LATER = '59e97ae4ea85bc81b029c266';

    private function add(string $id, int $year, string $name, int $code): void
    {
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => $code, 'kind' => 'ac', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Manipur', 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 2, 'extraction_sha256' => str_repeat('c', 64)]);
    }

    private function records(): void
    {
        $this->mock(HistoricalElectionArchive::class, function ($mock): void {
            $mock->shouldReceive('load')->andReturnUsing(function (string $id): array {
                $rows = DB::table('historical_constituency_index')->where('edition_id', $id)->get();

                return [['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => $rows->map(fn ($row) => [
                    'code' => $row->record_code, 'name' => $row->constituency_name, 'status' => 'validated', 'number_of_seats' => 1, 'electors' => 400, 'votes_polled' => 200,
                    'winner' => 'Winner '.$row->year, 'margin' => 40, 'candidates' => [['candidate_name' => 'Winner '.$row->year, 'party_at_election' => 'INC', 'votes' => 120], ['candidate_name' => 'Runner', 'party_at_election' => 'BJP', 'votes' => 80]],
                ])->all()]];
            });
        });
    }

    private function verifyRendered(string $name, array $years, array $rawNames): void
    {
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => $name]))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('entry.constituency_name')->sort()->values()->all() === collect($rawNames)->sort()->values()->all());
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        foreach ($years as $year) {
            $row = $xpath->query('//section[@id="history"]//tbody/tr[th="'.$year.'"]');
            $this->assertSame(1, $row->length);
            $cells = $xpath->query('./td', $row->item(0));
            $this->assertSame('Winner '.$year, trim($cells->item(0)->textContent));
            $this->assertSame('200', trim($cells->item(2)->textContent));
            $this->assertSame('50.00', trim($cells->item(3)->textContent));
            $this->assertSame('40', trim($cells->item(4)->textContent));
        }
        $charts = $xpath->query('//script[@class="history-chart-data"]');
        $this->assertSame(4, $charts->length);
        foreach ($charts as $chart) {
            $points = json_decode($chart->textContent, true)['rows'];
            $this->assertSame($years, array_column($points, 'year'));
            foreach ($points as $point) {
                $this->assertEquals(200, $point['polled']);
                $this->assertEquals(50, $point['turnout']);
                $this->assertEquals(40, $point['margin']);
                $this->assertEquals(120, $point['party0']);
                $this->assertEquals(60, $point['party0_share']);
            }
        }
    }

    public function test_bishenpur_spellings_render_complete_history_both_directions(): void
    {
        $this->add(self::OLD, 1974, 'Bishnupur', 26);
        $this->add(self::LATER, 1980, 'Bishenpur', 26);
        $this->records();
        foreach (['Bishenpur', 'Bishnupur'] as $name) {
            $this->verifyRendered($name, [1974, 1980], ['Bishnupur', 'Bishenpur']);
        }
    }

    public function test_nambol_spellings_link_explicit_1972_and_1974_and_later_records(): void
    {
        $this->add(self::EARLY, 1972, 'NAMBOL', 25);
        $this->add(self::OLD, 1974, 'NANBOL', 24);
        $this->add(self::LATER, 1980, 'NAMBOL', 24);
        $this->records();
        foreach (['Nambol', 'Nanbol'] as $name) {
            $this->verifyRendered($name, [1972, 1974, 1980], ['NAMBOL', 'NANBOL', 'NAMBOL']);
        }
    }

    public function test_wrong_codes_editions_states_and_kinds_are_not_extra_aliases(): void
    {
        $this->records();
        foreach ([['Bishenpur', 'Bishnupur', 26], ['Nambol', 'Nanbol', 24]] as [$name, $alias, $code]) {
            DB::table('historical_constituency_index')->delete();
            $this->add(self::LATER, 1980, $name, $code);
            $this->add(self::OLD, 1974, $alias, $code + 1);
            $this->verifyRendered($name, [1980], [$name]);
            DB::table('historical_constituency_index')->where('edition_id', self::OLD)->update(['record_code' => $code]);
            foreach ([['pc', 'Manipur'], ['ac', 'Tripura']] as [$kind, $state]) {
                DB::table('historical_constituency_index')->update(['kind' => $kind, 'state_label' => $state]);
                $this->get(route('constituency.overview', compact('kind', 'state', 'name')))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
            }
        }
        DB::table('historical_constituency_index')->delete();
        $this->add(self::OLD, 1974, 'Nanbol', 24);
        $this->add(str_repeat('a', 24), 1972, 'Nambol', 25);
        $this->add(str_repeat('b', 24), 1967, 'Mao East (ST)', 24);
        $this->add(str_repeat('c', 24), 1974, 'Nambol', 24);
        $this->verifyRendered('Nanbol', [1974], ['Nanbol']);
        DB::table('historical_constituency_index')->delete();
        $this->add(self::LATER, 1980, 'Nambol', 24);
        $this->add(str_repeat('a', 24), 1974, 'Nanbol', 24);
        $this->verifyRendered('Nambol', [1980], ['Nambol']);
    }

    public function test_original_names_at_other_codes_preserve_ambiguity(): void
    {
        foreach ([['Bishenpur', 'Bishnupur', 26], ['Nambol', 'Nanbol', 24]] as [$name, $alias, $code]) {
            DB::table('historical_constituency_index')->delete();
            $this->add(self::OLD, 1974, $name, $code + 1);
            $this->add(self::OLD, 1974, $alias, $code);
            $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => $name]))->assertRedirect(route('elections.constituencies', ['kind' => 'ac', 'state' => 'Manipur', 'q' => $name]));
        }
    }

    public function test_preserved_archive_fallback_respects_nambol_edition_and_code(): void
    {
        $disk = $this->mock(ArchiveFiles::class);
        foreach ([self::EARLY => [1972, '3703-manipur-1972'], self::OLD => [1974, '3704-manipur-1974'], self::LATER => [1980, '3705-manipur-1980']] as $id => [$year, $slug]) {
            $url = 'https://old.eci.gov.in/files/file/'.$slug.'/';
            $path = 'election-archive/'.$id.'/extraction.json';
            $body = json_encode(['source_url' => $url, 'kind' => 'ac', 'year' => $year, 'source_file' => 'source.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => [
                ['code' => $year === 1972 ? 25 : 24, 'name' => $year === 1974 ? 'NANBOL' : 'NAMBOL', 'state_name' => 'Manipur', 'candidates' => []],
                ['code' => 26, 'name' => 'Bishnupur', 'state_name' => 'Manipur', 'candidates' => []],
            ]]);
            DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'body' => $body]);
            $disk->shouldReceive('get')->with($path)->andReturn($body);
            $disk->shouldReceive('get')->with('election-archive/'.$id.'/manifest.json')->andReturn(json_encode(['url' => $url, 'files' => [['file' => 'source.pdf', 'sha256' => str_repeat('f', 64)]]]));
        }
        $before = DB::table('archive_json_files')->pluck('body', 'path')->all();
        $history = app(ConstituencyArchiveHistory::class);
        foreach (['Nanbol', 'Nambol'] as $name) {
            $rows = $history->missingEntries('ac', 'Manipur', $name, collect());
            $this->assertSame([1972, 1974, 1980], $rows->pluck('year')->sort()->values()->all());
            $this->assertSame(['NAMBOL', 'NANBOL', 'NAMBOL'], $rows->sortBy('year')->pluck('constituency_name')->all());
        }
        $this->assertCount(3, $history->missingEntries('ac', 'Manipur', 'Bishenpur', collect()));
        $this->assertSame($before, DB::table('archive_json_files')->pluck('body', 'path')->all());
    }
}
