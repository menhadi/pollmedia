<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyArchiveHistory;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyNaoriyaHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_ID = '48bac24675875f468956cf9e';

    private const NEW_ID = '59e97ae4ea85bc81b029c266';

    private function index(string $id, int $year, string $name, int $code = 21, string $state = 'Manipur', string $kind = 'ac'): void
    {
        DB::table('historical_constituency_index')->insert(['edition_id' => $id, 'record_code' => $code, 'kind' => $kind, 'year' => $year, 'edition_label' => (string) $year, 'state_label' => $state, 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 2, 'extraction_sha256' => str_repeat('c', 64)]);
    }

    private function records(): void
    {
        $this->mock(HistoricalElectionArchive::class, function ($mock): void {
            $mock->shouldReceive('load')->andReturnUsing(function (string $edition): array {
                $old = $edition === self::OLD_ID;

                return [['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [
                    ['code' => 21, 'name' => $old ? 'Naoriya Pakanglakpa' : 'Naoriya Pakhanglakpa', 'status' => 'validated', 'number_of_seats' => 1,
                        'electors' => 400, 'votes_polled' => $old ? 200 : 240, 'winner' => $old ? 'Old winner' : 'Later winner', 'margin' => $old ? 40 : 80,
                        'candidates' => [['candidate_name' => $old ? 'Old winner' : 'Later winner', 'party_at_election' => 'INC', 'votes' => $old ? 120 : 160], ['candidate_name' => 'Runner', 'party_at_election' => 'BJP', 'votes' => 80]]],
                ]]];
            });
        });
    }

    public function test_both_spelling_routes_render_both_years_and_actual_chart_values(): void
    {
        $this->index(self::OLD_ID, 1974, 'Naoriya Pakanglakpa');
        $this->index(self::NEW_ID, 1980, 'Naoriya Pakhanglakpa');
        $this->records();
        foreach (['Naoriya Pakanglakpa', 'Naoriya Pakhanglakpa'] as $name) {
            $response = $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => $name]))->assertOk()
                ->assertViewHas('rows', fn ($rows) => $rows->pluck('entry.constituency_name')->all() === ['Naoriya Pakhanglakpa', 'Naoriya Pakanglakpa']);
            $dom = new \DOMDocument;
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            foreach ([1974 => ['Old winner', 200, 50, 40, 120], 1980 => ['Later winner', 240, 60, 80, 160]] as $year => [$winner, $polled, $turnout, $margin, $votes]) {
                $row = $xpath->query('//section[@id="history"]//tbody/tr[th="'.$year.'"]');
                $this->assertSame(1, $row->length);
                $cells = $xpath->query('./td', $row->item(0));
                $this->assertSame($winner, trim($cells->item(0)->textContent));
                $this->assertSame((string) $polled, trim($cells->item(2)->textContent));
                $this->assertSame(number_format($turnout, 2), trim($cells->item(3)->textContent));
                $this->assertSame((string) $margin, trim($cells->item(4)->textContent));
                $charts = $xpath->query('//script[@class="history-chart-data"]');
                $this->assertSame(4, $charts->length);
                foreach ($charts as $chart) {
                    $point = collect(json_decode($chart->textContent, true)['rows'])->firstWhere('year', $year);
                    $this->assertEquals($polled, $point['polled']);
                    $this->assertEquals($turnout, $point['turnout']);
                    $this->assertEquals($margin, $point['margin']);
                    $this->assertEquals($votes, $point['party0']);
                }
            }
        }
    }

    public function test_alias_rejects_wrong_edition_code_state_kind_and_pre1975_reverse_matches(): void
    {
        $this->index(self::NEW_ID, 1980, 'Naoriya Pakhanglakpa');
        $this->index(str_repeat('a', 24), 1974, 'Naoriya Pakanglakpa');
        $this->index(self::OLD_ID, 1974, 'Naoriya Pakanglakpa', 22);
        $this->records();
        $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => 'Naoriya Pakhanglakpa']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        DB::table('historical_constituency_index')->delete();
        $this->index(self::OLD_ID, 1974, 'Naoriya Pakanglakpa');
        $this->index(self::NEW_ID, 1974, 'Naoriya Pakhanglakpa');
        $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => 'Naoriya Pakanglakpa']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        foreach ([['ac', 'Tripura'], ['pc', 'Manipur']] as [$kind, $state]) {
            DB::table('historical_constituency_index')->update(['kind' => $kind, 'state_label' => $state]);
            $this->get(route('constituency.overview', compact('kind', 'state') + ['name' => 'Naoriya Pakhanglakpa']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        }
    }

    public function test_original_name_other_codes_are_retained_and_ambiguity_is_not_suppressed(): void
    {
        $this->index(self::OLD_ID, 1974, 'Naoriya Pakanglakpa');
        $this->index(self::OLD_ID, 1974, 'Naoriya Pakhanglakpa', 22);
        $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => 'Naoriya Pakhanglakpa']))
            ->assertRedirect(route('elections.constituencies', ['kind' => 'ac', 'state' => 'Manipur', 'q' => 'Naoriya Pakhanglakpa']));
    }

    public function test_unindexed_archive_respects_the_same_exact_edition_rule_and_preserves_bytes(): void
    {
        $disk = $this->mock(ArchiveFiles::class);
        foreach ([self::OLD_ID => [1974, 'https://old.eci.gov.in/files/file/3704-manipur-1974/'], self::NEW_ID => [1980, 'https://old.eci.gov.in/files/file/3705-manipur-1980/']] as $id => [$year, $url]) {
            $path = 'election-archive/'.$id.'/extraction.json';
            $body = json_encode(['source_url' => $url, 'kind' => 'ac', 'year' => $year, 'source_file' => 'source.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => [
                ['code' => 21, 'name' => 'Naoriya Pakanglakpa', 'state_name' => 'Manipur', 'candidates' => []],
                ['code' => 22, 'name' => 'Naoriya Pakanglakpa', 'state_name' => 'Manipur', 'candidates' => []],
            ]]);
            DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'body' => $body]);
            $disk->shouldReceive('get')->with($path)->andReturn($body);
            $disk->shouldReceive('get')->with('election-archive/'.$id.'/manifest.json')->andReturn(json_encode(['url' => $url, 'files' => [['file' => 'source.pdf', 'sha256' => str_repeat('f', 64)]]]));
        }
        $before = DB::table('archive_json_files')->pluck('body', 'path')->all();
        $rows = app(ConstituencyArchiveHistory::class)->missingEntries('ac', 'Manipur', 'Naoriya Pakhanglakpa', collect());
        $this->assertCount(1, $rows);
        $this->assertSame(self::OLD_ID, $rows->first()->edition_id);
        $this->assertSame('Naoriya Pakanglakpa', $rows->first()->constituency_name);
        $this->assertSame(21, $rows->first()->record_code);
        $this->assertSame($before, DB::table('archive_json_files')->pluck('body', 'path')->all());
    }
}
