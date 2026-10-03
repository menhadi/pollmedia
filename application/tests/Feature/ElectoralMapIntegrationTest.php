<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ElectoralMapCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ElectoralMapIntegrationTest extends TestCase
{
    use RefreshDatabase;

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
