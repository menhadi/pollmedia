<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectionArchive;
use App\Services\ElectionPlaceIdentity;
use App\Services\ElectoralMapCatalogue;
use App\Services\HistoricalElectionAnalytics;
use App\Services\HistoricalElectionArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ElectoralMapIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function importedOlderEdition(string $state = 'Uttar Pradesh', string $name = 'Pilibhit'): array
    {
        [$label, $url] = collect(app(ElectionArchive::class)->catalogue()['pc'])->first(fn ($item) => str_starts_with($item[0], '1957'));
        $edition = substr(hash('sha256', $url), 0, 24);
        $data = ['kind' => 'pc', 'year' => 1957, 'source_url' => $url, 'source_file' => 'source.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => [
            ['code' => 16, 'name' => $name, 'constituency_name' => $name, 'state_name' => $state, 'state_code' => 'S12', 'status' => 'validated', 'number_of_seats' => 1, 'electors' => 200, 'votes_polled' => 150,
                'winner' => 'Earlier winner', 'margin' => 50, 'candidates' => [['candidate_name' => 'Earlier winner', 'party_at_election' => 'INC', 'votes' => 100], ['candidate_name' => 'Earlier runner', 'party_at_election' => 'IND', 'votes' => 50]]],
        ]];
        foreach (['extraction.json' => $data, 'manifest.json' => ['url' => $url, 'files' => [['file' => 'source.pdf', 'sha256' => $data['source_sha256']]]]] as $file => $contents) {
            $path = 'election-archive/'.$edition.'/'.$file;
            $body = json_encode($contents);
            DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path, 'category' => 'election-archive', 'bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'source_url' => $url, 'body' => $body]);
        }

        return [$edition, $data];
    }

    public function test_pre_1980_history_and_map_results_use_preserved_imports_when_the_index_is_missing(): void
    {
        Storage::fake('local');
        [$edition, $data] = $this->importedOlderEdition();
        $this->mock(HistoricalElectionArchive::class, function ($mock) use ($data) {
            $mock->shouldReceive('load')->andReturn([$data]);
        });
        $this->get('/india/constituency?kind=pc&state=Uttar%20Pradesh&name=Pilibhit')->assertOk()->assertSee('1957')->assertSee('Earlier winner')->assertSee('1957 results');
        $this->getJson('/api/election-maps/results?kind=pc&state=uttar-pradesh&edition='.$edition)->assertOk()->assertJsonCount(1, 'records')->assertJsonPath('records.0.year', 1957)->assertJsonPath('records.0.party', 'INC');
        $this->assertDatabaseCount('historical_constituency_index', 0);
    }

    public function test_renamed_state_history_remains_available_under_current_navigation(): void
    {
        Storage::fake('local');
        [$edition, $data] = $this->importedOlderEdition('Madras', 'Historical seat');
        $history = app(HistoricalElectionAnalytics::class)->forState('Tamil Nadu', 'pc');
        $this->assertSame(1957, $history[0]['year']);
        $this->assertSame('Madras', $history[0]['state']);
        $this->getJson('/api/election-maps/results?kind=pc&state=tamil-nadu&edition='.$edition)->assertOk()->assertJsonPath('records.0.state', 'Tamil Nadu');
        $this->assertSame('Karnataka', ElectionPlaceIdentity::state('Mysore'));
        $this->assertSame('tamil-nadu', app(ElectoralMapCatalogue::class)->stateFromQuery('Madras')['slug']);
        $this->assertDatabaseCount('historical_constituency_index', 0);
    }

    public function test_unindexed_history_rejects_source_manifest_mismatch(): void
    {
        Storage::fake('local');
        [$edition] = $this->importedOlderEdition();
        $path = 'election-archive/'.$edition.'/manifest.json';
        $row = DB::table('archive_json_files')->where('path', $path)->first();
        $manifest = json_decode($row->body, true);
        $manifest['files'][0]['sha256'] = str_repeat('0', 64);
        $body = json_encode($manifest);
        DB::table('archive_json_files')->where('path', $path)->update(['body' => $body, 'bytes' => strlen($body), 'sha256' => hash('sha256', $body)]);
        $this->getJson('/api/election-maps/results?kind=pc&state=uttar-pradesh&edition='.$edition)->assertStatus(409);
        $this->assertDatabaseCount('historical_constituency_index', 0);
    }

    private function fixture(string $kind = 'pc', string $state = 'Uttar Pradesh', string $id = 'a', int $year = 2024): string
    {
        $edition = str_repeat($id, 24);
        $records = [
            ['code' => 371, 'name' => 'Pilibhit', 'constituency_name' => 'Pilibhit', 'official_pc_code' => 26,
                'number_of_seats' => 1, 'status' => 'validated', 'winner' => 'Recorded winner', 'margin' => 50,
                'candidates' => [['candidate_name' => 'Recorded winner', 'party_at_election' => 'BJP', 'votes' => 100], ['candidate_name' => 'Runner', 'party_at_election' => 'INC', 'votes' => 50]]],
            ['code' => 372, 'name' => 'Historical seat', 'status' => 'needs_review', 'candidates' => []],
        ];
        $body = json_encode(['source_url' => 'https://www.eci.gov.in/report.pdf', 'source_file' => 'report.pdf', 'source_sha256' => str_repeat('f', 64), 'records' => $records]);
        Storage::disk('local')->put('election-archive/'.$edition.'/extraction.json', $body);
        Storage::disk('local')->put('election-archive/'.$edition.'/manifest.json', json_encode(['url' => 'https://www.eci.gov.in/report.pdf', 'files' => [['file' => 'report.pdf', 'sha256' => str_repeat('f', 64)]]]));
        foreach ($records as $record) {
            DB::table('historical_constituency_index')->insert(['edition_id' => $edition, 'record_code' => $record['code'], 'kind' => $kind, 'year' => $year, 'edition_label' => (string) $year, 'state_label' => $state, 'constituency_name' => $record['name'], 'status' => $record['status'], 'has_warning' => $record['status'] !== 'validated', 'candidate_count' => count($record['candidates']), 'extraction_sha256' => hash('sha256', $body)]);
        }

        return $edition;
    }

    public function test_results_keep_official_winners_and_exact_record_links_without_guessing_missing_results(): void
    {
        Storage::fake('local');
        $edition = $this->fixture();
        $json = $this->getJson('/api/election-maps/results?kind=pc&state=uttar-pradesh&edition='.$edition)->assertOk()
            ->assertJsonCount(2, 'records')->assertJsonPath('records.0.party', 'BJP')->assertJsonPath('records.0.official_code', 26)
            ->assertJsonPath('records.1.party', null)->assertJsonPath('records.1.winner', null);
        $this->assertSame(route('constituency.overview', ['kind' => 'pc', 'state' => 'Uttar Pradesh', 'name' => 'Pilibhit', 'edition' => $edition, 'code' => 371]), $json->json('records.0.url'));
        $this->getJson('/api/election-maps/results?kind=ac&state=uttar-pradesh')->assertOk()->assertJsonCount(0, 'records');
        $this->getJson('/api/election-maps/results?kind=pc&state=not-a-state')->assertNotFound();
    }

    public function test_latest_results_are_selected_per_state_and_explicit_year_remains_scoped(): void
    {
        Storage::fake('local');
        $this->fixture('pc', 'Uttar Pradesh', 'a', 2024);
        $old = $this->fixture('pc', 'S24', 'b', 2019);
        $this->fixture('pc', 'Bihar', 'c', 2019);
        $data = $this->getJson('/api/election-maps/results?kind=pc')->assertOk()->assertJsonCount(4, 'records')->json('records');
        $this->assertSame([2024], array_values(array_unique(array_column(array_filter($data, fn ($r) => $r['state'] === 'Uttar Pradesh'), 'year'))));
        $this->getJson('/api/election-maps/results?kind=pc&edition='.$old)->assertOk()->assertJsonPath('records.0.year', 2019);
    }

    public function test_former_state_spelling_keeps_available_election_records_on_current_state_map(): void
    {
        Storage::fake('local');
        $edition = $this->fixture('pc', 'Orissa', 'd', 2004);
        $this->getJson('/api/election-maps/results?kind=pc&state=odisha&edition='.$edition)->assertOk()->assertJsonCount(2, 'records');
    }

    public function test_corrupt_indexed_extraction_is_rejected(): void
    {
        Storage::fake('local');
        $edition = $this->fixture();
        DB::table('historical_constituency_index')->where('edition_id', $edition)->update(['extraction_sha256' => str_repeat('0', 64)]);
        $this->getJson('/api/election-maps/results?kind=pc&edition='.$edition)->assertStatus(409);
    }

    public function test_map_view_keeps_selected_year_seat_and_combined_source_context(): void
    {
        $html = view('election-map', ['mapKind' => 'ac', 'mapStateName' => 'Telangana', 'mapEdition' => str_repeat('a', 24), 'mapSelectedName' => 'Hyderabad', 'mapSelectedCode' => 12, 'mapYear' => 2014])->render();
        foreach (['andhra-pradesh.json', 'data-kind="ac"', 'data-selected="Hyderabad"', 'data-selected-code="12"', 'edition='.str_repeat('a', 24), 'schematic list'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Needs review', $html);
        $national = view('election-map', ['mapKind' => 'pc'])->render();
        $this->assertStringContainsString('india-pc.json', $national);
        $focused = view('election-map', ['mapKind' => 'pc', 'mapStateName' => 'Uttar Pradesh', 'mapSelectedName' => 'Pilibhit', 'mapMode' => 'focus', 'mapYear' => 1957, 'mapEdition' => str_repeat('a', 24)])->render();
        $this->assertStringContainsString('data-mode="focus"', $focused);
        $this->assertStringNotContainsString('edition=', $focused);
        $this->assertStringNotContainsString('1957', $focused);
        $this->assertStringContainsString('class="election-map-search"  hidden', $focused);
    }

    public function test_national_layers_preserve_all_records_and_flagged_geometry(): void
    {
        $catalogue = app(ElectoralMapCatalogue::class)->all();
        $originals = [];
        foreach ($catalogue['states'] as $state) {
            foreach (json_decode(file_get_contents(public_path('maps/electoral/'.$state['file'])), true)['features'] as $f) {
                if ($f['properties']['geometry_warning']) {
                    $originals[$f['id']] = $f['geometry'];
                }
            }
        }
        $seen = [];
        foreach (['pc' => 543, 'ac' => 4182] as $kind => $count) {
            $features = json_decode(file_get_contents(public_path('maps/electoral/india-'.$kind.'.json')), true)['features'];
            $this->assertCount($count, $features);
            foreach ($features as $f) {
                $this->assertSame($kind, $f['properties']['kind']);
                $seen[] = $f['id'];
                if (isset($originals[$f['id']])) {
                    $this->assertSame($originals[$f['id']], $f['geometry']);
                }
            }
        }
        $this->assertCount(4725, array_unique($seen));
        $this->assertCount(23, $originals);
    }

    public function test_boundary_checks_are_private_and_acknowledgements_preserve_source_flags(): void
    {
        $this->get('/admin/election-maps')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->get('/admin/election-maps')->assertForbidden();
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $this->actingAs($admin)->get('/admin/election-maps')->assertOk()->assertSee('Needs review')->assertSee('Akbarpur');
        $state = app(ElectoralMapCatalogue::class)->all()['states']['uttar-pradesh'];
        $feature = collect(json_decode(file_get_contents(public_path('maps/electoral/'.$state['file'])), true)['features'])->firstWhere('properties.name', 'Akbarpur');
        $input = ['state' => 'uttar-pradesh', 'feature' => $feature['id'], 'hash' => $state['sha256'], 'reason' => 'Source shape inspected; historical approximation retained.', 'evidence_url' => 'https://www.eci.gov.in/'];
        $this->post('/admin/election-maps/review', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('site_changes', ['target' => 'settings:boundary_reviews', 'user_id' => $admin->id]);
        $this->assertSame($state['sha256'], hash_file('sha256', public_path('maps/electoral/'.$state['file'])));
        $input['hash'] = str_repeat('0', 64);
        $this->post('/admin/election-maps/review', $input)->assertStatus(409);
    }

    public function test_admin_party_colours_are_used_by_public_map_results(): void
    {
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $data = config('site.appearance');
        $data['party_colors']['BJP'] = '#ff8800';
        $this->actingAs($admin)->post('/admin/site/appearance', $data)->assertSessionHasNoErrors();
        $this->getJson('/api/election-maps/results?kind=pc')->assertOk()->assertJsonPath('colors.BJP', '#ff8800');
        $data['party_colors']['BJP'] = 'invalid';
        $this->post('/admin/site/appearance', $data)->assertSessionHasErrors('party_colors.BJP');
    }
}
