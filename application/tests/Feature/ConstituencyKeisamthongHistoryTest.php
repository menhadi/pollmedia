<?php

namespace Tests\Feature;

use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyKeisamthongHistoryTest extends TestCase
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

    public function test_both_spellings_render_thirteen_years_and_preserve_raw_names(): void
    {
        $years = [1967, 1972, 1974, 1980, 1984, 1990, 1995, 2000, 2002, 2007, 2012, 2017, 2022];
        $names = [];
        foreach ($years as $year) {
            $name = in_array($year, [2007, 2012, 2017], true) ? 'Keishamthong' : 'KEISAMTHONG';
            $id = match ($year) {
                1967 => 'c2f796a4415124c983df09b7', 1972 => self::EARLY, default => substr(hash('sha256', (string) $year), 0, 24)
            };
            $this->add($id, $year, $name, match ($year) {
                1967 => 9, 1972 => 17, default => 12
            });
            $names[] = $name;
        }
        $this->records();
        foreach (['keisamthong', 'keishamthong'] as $name) {
            $this->verifyRendered($name, $years, $names);
        }
    }

    public function test_wrong_identity_scopes_and_early_editions_are_excluded(): void
    {
        $this->records();
        $this->add(self::LATER, 1980, 'Keishamthong', 12);
        $this->add(str_repeat('a', 24), 1967, 'Keisamthong', 9);
        $this->add(str_repeat('b', 24), 1972, 'Keisamthong', 17);
        $this->add(self::EARLY, 1972, 'Keisamthong', 18);
        $this->add(self::OLD, 1974, 'Keisamthong (ST)', 12);
        $this->verifyRendered('Keishamthong', [1980], ['Keishamthong']);
        DB::table('historical_constituency_index')->delete();
        $this->add(self::LATER, 1980, 'Keisamthong', 12);
        $this->add(self::OLD, 1974, 'Keishamthong', 13);
        $this->verifyRendered('Keisamthong', [1980], ['Keisamthong']);
        DB::table('historical_constituency_index')->where('edition_id', self::OLD)->update(['record_code' => 12]);
        foreach ([['pc', 'Manipur'], ['ac', 'Tripura']] as [$kind, $state]) {
            DB::table('historical_constituency_index')->update(['kind' => $kind, 'state_label' => $state]);
            $this->get(route('constituency.overview', ['kind' => $kind, 'state' => $state, 'name' => 'Keisamthong']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        }
    }

    public function test_original_name_at_another_code_preserves_ambiguity(): void
    {
        $this->add(self::OLD, 1974, 'Keisamthong', 13);
        $this->add(self::OLD, 1974, 'Keishamthong', 12);
        $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => 'Keisamthong']))->assertRedirect();
    }
}
