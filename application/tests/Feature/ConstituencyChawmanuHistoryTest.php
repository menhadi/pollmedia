<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyArchiveHistory;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyChawmanuHistoryTest extends TestCase
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

    public function test_official_spellings_restore_eleven_years_in_tables_and_graphs(): void
    {
        $years = [1972, 1977, 1983, 1988, 1993, 1998, 2003, 2008, 2013, 2018, 2023];
        $names = [];
        foreach ($years as $year) {
            $name = match ($year) {
                2023 => 'Chawmanu (ST)', 2018 => 'Chawamanu', 2013 => 'Chawamanu  (ST)',
                2008 => 'Chhawmanu  (ST)', default => 'CHHAWMANU (ST)',
            };
            $id = match ($year) {
                1972 => 'a09382fa323d9af5f7af7d59', 2018 => 'd070812d58833a38f814391b',
                default => substr(hash('sha256', (string) $year), 0, 24),
            };
            $this->add($id, $year, $name, $year === 1972 ? 48 : 49);
            $names[] = $name;
        }
        $this->records();
        foreach (['chawmanu (st)', 'chhawmanu (st)', 'chawamanu'] as $name) {
            $this->verifyRendered($name, $years, $names);
        }
    }

    public function test_alias_excludes_other_codes_reservations_states_and_unproven_editions(): void
    {
        $this->add(str_repeat('a', 24), 2023, 'Chawmanu (ST)', 49);
        foreach ([['b', 1972, 'Chhawmanu (ST)', 48], ['c', 2013, 'Chhawmanu (SC)', 49],
            ['d', 2013, 'Chhawmanu', 49], ['e', 2013, 'Chhawmanu (ST)', 50],
            ['f', 2018, 'Chawamanu', 49]] as [$id, $year, $name, $code]) {
            $this->add(str_repeat($id, 24), $year, $name, $code);
        }
        $this->records();
        $this->verifyRendered('Chawmanu (ST)', [2023], ['Chawmanu (ST)']);
        foreach ([['pc', 'Tripura'], ['ac', 'Manipur']] as [$kind, $state]) {
            $this->assertSame([], ConstituencyArchiveHistory::historyAliasRules($kind, $state, 'Chawmanu (ST)'));
        }
    }

    public function test_preserved_unindexed_1972_spelling_is_found_without_changing_source_bytes(): void
    {
        $id = 'a09382fa323d9af5f7af7d59';
        $url = 'https://old.eci.gov.in/files/file/3284-tripura-1972/';
        $path = 'election-archive/'.$id.'/extraction.json';
        $record = ['code' => 48, 'name' => 'CHHAWMANU (ST)', 'state_name' => 'Tripura', 'status' => 'validated', 'candidates' => []];
        $body = json_encode(['source_url' => $url, 'kind' => 'ac', 'year' => 1972, 'source_file' => 'source.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => [$record]]);
        DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'body' => $body]);
        $this->mock(ArchiveFiles::class, function ($mock) use ($path, $body, $id, $url): void {
            $mock->shouldReceive('get')->with($path)->once()->andReturn($body);
            $mock->shouldReceive('get')->with('election-archive/'.$id.'/manifest.json')->once()->andReturn(json_encode(['url' => $url, 'files' => [['file' => 'source.pdf', 'sha256' => str_repeat('f', 64)]]]));
        });
        $rows = app(ConstituencyArchiveHistory::class)->missingEntries('ac', 'Tripura', 'Chawmanu (ST)', collect());
        $this->assertCount(1, $rows);
        $this->assertSame('CHHAWMANU (ST)', $rows->first()->constituency_name);
        $this->assertSame($body, DB::table('archive_json_files')->where('path', $path)->value('body'));
    }
}
