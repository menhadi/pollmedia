<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyArchiveHistory;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyKailashaharHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function add(string $id, int $year, string $name, int $code): void
    {
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => $code, 'kind' => 'ac', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Tripura', 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 2, 'extraction_sha256' => str_repeat('c', 64)]);
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
        $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Tripura', 'name' => $name]))->assertOk()
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

    public function test_both_spellings_restore_twelve_years_in_tables_and_graphs(): void
    {
        $years = [1967, 1972, 1977, 1983, 1988, 1993, 1998, 2003, 2008, 2013, 2018, 2023];
        $names = [];
        foreach ($years as $year) {
            $name = $year > 1972 && $year < 2013 ? 'KAILASAHAR' : 'KAILASHAHAR';
            $id = match ($year) {
                1967 => '9cedf416aa4afcee8948ef91', 1972 => 'a09382fa323d9af5f7af7d59',
                default => substr(hash('sha256', (string) $year), 0, 24),
            };
            $code = match ($year) {
                1967 => 26, 1972 => 52, default => 53
            };
            $this->add($id, $year, $name, $code);
            $names[] = $name;
        }
        $this->records();
        foreach (['kailashahar', 'kailasahar'] as $name) {
            $this->verifyRendered($name, $years, $names);
        }
    }

    public function test_alias_excludes_other_codes_reservations_states_and_unproven_early_editions(): void
    {
        $this->add(str_repeat('a', 24), 2023, 'Kailasahar', 53);
        foreach ([['b', 1967, 'Kailashahar', 26], ['c', 2013, 'Kailashahar (SC)', 53],
            ['d', 2013, 'Kailashahar (ST)', 53], ['e', 2013, 'Kailashahar', 54],
            ['f', 1972, 'Kailashahar', 52]] as [$id, $year, $name, $code]) {
            $this->add(str_repeat($id, 24), $year, $name, $code);
        }
        $this->records();
        $this->verifyRendered('Kailasahar', [2023], ['Kailasahar']);
        foreach ([['pc', 'Tripura'], ['ac', 'Manipur']] as [$kind, $state]) {
            $this->assertSame([], ConstituencyArchiveHistory::historyAliasRules($kind, $state, 'Kailashahar'));
        }
    }

    public function test_missing_index_fallback_keeps_original_spelling_and_source_bytes(): void
    {
        $id = 'f3146efb9111d25969c412f0';
        $url = 'https://old.eci.gov.in/files/file/3288-tripura-1977/';
        $path = 'election-archive/'.$id.'/extraction.json';
        $record = ['code' => 53, 'name' => 'KAILASAHAR', 'state_name' => 'Tripura', 'status' => 'validated', 'candidates' => []];
        $body = json_encode(['source_url' => $url, 'kind' => 'ac', 'year' => 1977, 'source_file' => 'source.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => [$record]]);
        DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'body' => $body]);
        $this->mock(ArchiveFiles::class, function ($mock) use ($path, $body, $id, $url): void {
            $mock->shouldReceive('get')->with($path)->once()->andReturn($body);
            $mock->shouldReceive('get')->with('election-archive/'.$id.'/manifest.json')->once()->andReturn(json_encode(['url' => $url, 'files' => [['file' => 'source.pdf', 'sha256' => str_repeat('f', 64)]]]));
        });
        $rows = app(ConstituencyArchiveHistory::class)->missingEntries('ac', 'Tripura', 'Kailashahar', collect());
        $this->assertCount(1, $rows);
        $this->assertSame('KAILASAHAR', $rows->first()->constituency_name);
        $this->assertSame($body, DB::table('archive_json_files')->where('path', $path)->value('body'));
    }
}
