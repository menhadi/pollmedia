<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyArchiveHistory;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyKhangabokHistoryTest extends TestCase
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

    public function test_all_three_spellings_render_thirteen_years_and_preserve_raw_names(): void
    {
        $years = [1967, 1972, 1974, 1980, 1984, 1990, 1995, 2000, 2002, 2007, 2012, 2017, 2022];
        $names = [];
        foreach ($years as $year) {
            $name = match ($year) {
                1967, 1974, 2007, 2012, 2017 => 'KHANGABOK', 1972 => 'KHANGABOX', default => 'KHANGABO'
            };
            $id = match ($year) {
                1967 => 'c2f796a4415124c983df09b7', 1972 => self::EARLY, default => substr(hash('sha256', (string) $year), 0, 24)
            };
            $this->add($id, $year, $name, $year === 1967 ? 18 : 35);
            $names[] = $name;
        }
        $this->records();
        foreach (['Khangabok', 'Khangabo', 'Khangabox'] as $name) {
            $this->verifyRendered($name, $years, $names);
        }
    }

    public function test_aliases_refuse_wrong_codes_editions_states_and_kinds(): void
    {
        $this->records();
        $this->add(self::LATER, 1980, 'Khangabok', 35);
        $this->add(self::OLD, 1974, 'Khangabo', 34);
        $this->add(str_repeat('a', 24), 1972, 'Khangabox', 35);
        $this->add(self::EARLY, 1972, 'Khangabox', 34);
        $this->verifyRendered('Khangabok', [1980], ['Khangabok']);
        DB::table('historical_constituency_index')->delete();
        $this->add(self::LATER, 1980, 'Khangabo', 35);
        $this->add(str_repeat('b', 24), 1967, 'Khangabok', 18);
        $this->add('c2f796a4415124c983df09b7', 1967, 'Khangabok', 17);
        $this->verifyRendered('Khangabo', [1980], ['Khangabo']);
        DB::table('historical_constituency_index')->delete();
        $this->add(self::LATER, 1980, 'Khangabo', 35);
        $this->add(self::OLD, 1974, 'Khangabok', 35);
        foreach ([['pc', 'Manipur'], ['ac', 'Tripura']] as [$kind, $state]) {
            DB::table('historical_constituency_index')->update(['kind' => $kind, 'state_label' => $state]);
            $this->get(route('constituency.overview', ['kind' => $kind, 'state' => $state, 'name' => 'Khangabo']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        }
    }

    public function test_original_name_at_another_code_still_refuses_ambiguity(): void
    {
        $this->add(self::OLD, 1974, 'Khangabok', 34);
        $this->add(self::OLD, 1974, 'Khangabo', 35);
        $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => 'Khangabok']))->assertRedirect(route('elections.constituencies', ['kind' => 'ac', 'state' => 'Manipur', 'q' => 'Khangabok']));
    }

    public function test_preserved_archive_fallback_applies_the_same_edition_guards(): void
    {
        $disk = $this->mock(ArchiveFiles::class);
        foreach (['c2f796a4415124c983df09b7' => [1967, '3702-manipur-1967', 'Khangabok', 18], self::EARLY => [1972, '3703-manipur-1972', 'Khangabox', 35], self::LATER => [1980, '3705-manipur-1980', 'Khangabo', 35]] as $id => [$year, $slug, $name, $code]) {
            $url = 'https://old.eci.gov.in/files/file/'.$slug.'/';
            $path = 'election-archive/'.$id.'/extraction.json';
            $body = json_encode(['source_url' => $url, 'kind' => 'ac', 'year' => $year, 'source_file' => 'source.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => [
                ['code' => $code, 'name' => $name, 'state_name' => 'Manipur', 'candidates' => []],
                ['code' => 34, 'name' => $name, 'state_name' => 'Manipur', 'candidates' => []],
            ]]);
            DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'body' => $body]);
            $disk->shouldReceive('get')->with($path)->andReturn($body);
            $disk->shouldReceive('get')->with('election-archive/'.$id.'/manifest.json')->andReturn(json_encode(['url' => $url, 'files' => [['file' => 'source.pdf', 'sha256' => str_repeat('f', 64)]]]));
        }
        $before = DB::table('archive_json_files')->pluck('body', 'path')->all();
        $rows = app(ConstituencyArchiveHistory::class)->missingEntries('ac', 'Manipur', 'Khangabok', collect());
        $this->assertSame([1967, 1967, 1972, 1980], $rows->pluck('year')->sort()->values()->all());
        $this->assertSame([18, 34, 35, 35], $rows->sortBy('year')->pluck('record_code')->values()->all());
        $this->assertSame($before, DB::table('archive_json_files')->pluck('body', 'path')->all());
    }
}
