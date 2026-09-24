<?php

namespace Tests\Feature;

use App\Services\ElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalConstituencyIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_builds_searchable_edition_specific_records_without_publishing_results(): void
    {
        $url = collect(app(ElectionArchive::class)->catalogue()['pc'])->first(fn (array $entry): bool => str_starts_with($entry[0], '2009'))[1];
        $edition = substr(hash('sha256', $url), 0, 24);
        $path = 'election-archive/'.$edition.'/extraction.json';
        $sourceHash = hash('sha256', 'official PDF');
        $body = json_encode([
            'kind' => 'pc', 'year' => 2009, 'source_url' => $url, 'source_file' => 'detail.pdf', 'source_sha256' => $sourceHash,
            'records' => [
                ['code' => 451, 'state_code' => 'S24', 'constituency_name' => 'Pilibhit', 'status' => 'validated', 'candidates' => [['candidate_name' => 'A']]],
                ['code' => 452, 'state_code' => 'S24', 'constituency_name' => 'Bareilly', 'status' => 'needs_review', 'error' => 'Source totals differ', 'candidates' => []],
            ],
        ], JSON_THROW_ON_ERROR);
        DB::table('archive_json_files')->insert([
            'path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive',
            'sha256' => hash('sha256', $body), 'bytes' => strlen($body), 'source_url' => $url,
            'body' => $body, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $manifestPath = 'election-archive/'.$edition.'/manifest.json';
        $manifest = json_encode(['url' => $url, 'files' => [['file' => 'detail.pdf', 'sha256' => $sourceHash]]], JSON_THROW_ON_ERROR);
        DB::table('archive_json_files')->insert([
            'path_hash' => hash('sha256', $manifestPath), 'path' => $manifestPath, 'category' => 'election-archive',
            'sha256' => hash('sha256', $manifest), 'bytes' => strlen($manifest), 'source_url' => $url,
            'body' => $manifest, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('archive:index-constituencies --check')->assertExitCode(0);
        $this->assertDatabaseCount('historical_constituency_index', 0);
        $this->artisan('archive:index-constituencies')->assertExitCode(0);
        $this->artisan('archive:index-constituencies')->assertExitCode(0);
        $this->assertDatabaseCount('historical_constituency_index', 2);
        $this->assertDatabaseCount('election_contests', 0);
        $this->assertDatabaseHas('historical_constituency_index', [
            'edition_id' => $edition, 'record_code' => 452, 'has_warning' => true,
        ]);

        $this->get(route('elections.constituencies', ['q' => 'pIlIbHiT', 'kind' => 'pc', 'state' => 'UTTAR PRADESH']))
            ->assertOk()->assertSee('1 election records match')
            ->assertSee('Pilibhit')->assertDontSee('Bareilly')
            ->assertSee(route('elections.history', ['edition' => $edition, 'state' => 'S24', 'code' => 451]));
        $this->get(route('elections.constituencies', ['q' => 'Bareilly']))
            ->assertOk()->assertSee('Needs review †');

        $wrongManifest = json_encode(['url' => $url, 'files' => [['file' => 'detail.pdf', 'sha256' => str_repeat('0', 64)]]], JSON_THROW_ON_ERROR);
        DB::table('archive_json_files')->where('path_hash', hash('sha256', $manifestPath))->update([
            'body' => $wrongManifest, 'sha256' => hash('sha256', $wrongManifest), 'bytes' => strlen($wrongManifest),
        ]);
        $this->artisan('archive:index-constituencies')->assertExitCode(1);
        $this->assertDatabaseCount('historical_constituency_index', 2);
        DB::table('archive_json_files')->where('path_hash', hash('sha256', $manifestPath))->update([
            'body' => $manifest, 'sha256' => hash('sha256', $manifest), 'bytes' => strlen($manifest),
        ]);

        DB::table('archive_json_files')->where('path_hash', hash('sha256', $path))->update(['body' => '{}']);
        $this->artisan('archive:index-constituencies')->assertExitCode(1);
        $this->assertDatabaseCount('historical_constituency_index', 2);
    }

    public function test_finder_without_built_index_keeps_archive_links_available(): void
    {
        $this->get(route('elections.constituencies'))->assertOk()
            ->assertSee('No election records match this selection')
            ->assertSee(route('elections.history'))
            ->assertSee(route('elections.assembly'));
    }
}
