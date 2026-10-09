<?php

namespace Tests\Feature;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyArchiveHistory;
use App\Services\ElectionArchive;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConstituencyGeneralCategoryHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function index(string $id, int $year, string $name, int $code = 1): void
    {
        DB::table('historical_constituency_index')->insert(['edition_id' => str_repeat($id, 24), 'record_code' => $code, 'kind' => 'ac', 'year' => $year, 'edition_label' => (string) $year, 'state_label' => 'Manipur', 'constituency_name' => $name, 'status' => 'validated', 'has_warning' => false, 'candidate_count' => 2, 'extraction_sha256' => str_repeat('c', 64)]);
    }

    private function mockRecords(): void
    {
        $this->mock(HistoricalElectionArchive::class, function ($mock): void {
            $mock->shouldReceive('load')->andReturn([['source_url' => 'https://eci.gov.in', 'source_sha256' => str_repeat('c', 64), 'records' => [
                ['code' => 1, 'status' => 'validated', 'number_of_seats' => 1, 'candidates' => [['candidate_name' => 'First candidate', 'party_at_election' => 'INC', 'votes' => 100], ['candidate_name' => 'Runner', 'party_at_election' => 'BJP', 'votes' => 80]]],
                ['code' => 2, 'status' => 'validated', 'number_of_seats' => 1, 'candidates' => [['candidate_name' => 'Second candidate', 'party_at_election' => 'BJP', 'votes' => 110], ['candidate_name' => 'Runner', 'party_at_election' => 'INC', 'votes' => 70]]],
            ]]]);
        });
    }

    public function test_general_category_suffix_keeps_available_years_together_in_both_directions(): void
    {
        $this->index('a', 2012, 'Khundrakpam');
        $this->index('b', 2017, 'Khundrakpam (GEN)');
        $this->index('c', 2022, 'Khundrakpam');
        $this->index('d', 2007, 'Khundrakpam (ST)');
        $this->mockRecords();
        foreach (['Khundrakpam', 'Khundrakpam (GEN)'] as $name) {
            $this->get(route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => $name]))->assertOk()
                ->assertSee('3 available years')->assertSee('2017')->assertSee('2012')->assertSee('2022')->assertDontSee('2007')
                ->assertViewHas('rows', fn ($rows) => $rows->pluck('entry.constituency_name')->contains('Khundrakpam (GEN)'));
        }
    }

    public function test_general_suffix_does_not_merge_two_codes_in_the_same_edition(): void
    {
        $this->index('a', 2017, 'Heingang', 1);
        $this->index('a', 2017, 'Heingang (GEN)', 2);
        $this->mockRecords();
        $base = route('constituency.overview', ['kind' => 'ac', 'state' => 'Manipur', 'name' => 'Heingang']);
        $this->get($base)->assertRedirect(route('elections.constituencies', ['kind' => 'ac', 'state' => 'Manipur', 'q' => 'Heingang']));
        $this->get($base.'&edition='.str_repeat('a', 24).'&code=2')->assertOk()->assertSee('Second candidate')->assertDontSee('First candidate')->assertSee('This page shows the selected seat');
    }

    public function test_preserved_unindexed_general_category_record_is_found_without_rewriting_its_name(): void
    {
        $source = collect(app(ElectionArchive::class)->nationalAssemblyEntries())->first(fn ($entry) => $entry['state'] === 'Manipur' && str_starts_with($entry['label'], '2017'));
        $this->assertNotNull($source);
        $url = $source['url'];
        $id = substr(hash('sha256', $url), 0, 24);
        $path = 'election-archive/'.$id.'/extraction.json';
        $body = json_encode(['source_url' => $url, 'kind' => 'ac', 'year' => 2017, 'source_file' => 'source.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => [['code' => 2, 'name' => 'Heingang (GEN)', 'candidates' => []]]]);
        DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'body' => $body]);
        $this->mock(ArchiveFiles::class, function ($mock) use ($path, $body, $id, $url): void {
            $mock->shouldReceive('get')->with($path)->once()->andReturn($body);
            $mock->shouldReceive('get')->with('election-archive/'.$id.'/manifest.json')->once()->andReturn(json_encode(['url' => $url, 'files' => [['file' => 'source.pdf', 'sha256' => str_repeat('f', 64)]]]));
        });
        $rows = app(ConstituencyArchiveHistory::class)->missingEntries('ac', 'Manipur', 'Heingang', collect());
        $this->assertCount(1, $rows);
        $this->assertSame('Heingang (GEN)', $rows->first()->constituency_name);
    }
}
