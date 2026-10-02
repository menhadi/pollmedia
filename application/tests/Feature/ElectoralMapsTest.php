<?php

namespace Tests\Feature;

use App\Services\ElectoralMapCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ElectoralMapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_source_record_is_preserved_with_unique_ids_and_hashes(): void
    {
        $catalogue = app(ElectoralMapCatalogue::class)->all();
        $this->assertCount(36, $catalogue['states']);
        $counts = ['pc' => 0, 'ac' => 0];
        $ids = [];
        $flagged = 0;
        foreach ($catalogue['states'] as $state) {
            $file = public_path('maps/electoral/'.$state['file']);
            $this->assertSame($state['sha256'], hash_file('sha256', $file));
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ($data['features'] as $feature) {
                $counts[$feature['properties']['kind']]++;
                $ids[] = $feature['id'];
                $flagged += (int) ($feature['properties']['geometry_status'] === 'flagged');
                $this->assertSame('unverified', $feature['properties']['join_status']);
                $this->assertArrayHasKey('source_attributes', $feature['properties']);
            }
        }
        $this->assertSame(['pc' => 543, 'ac' => 4182], $counts);
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertSame(23, $flagged);
    }

    public function test_all_state_routes_and_historical_grouping_notices_are_available(): void
    {
        foreach (app(ElectoralMapCatalogue::class)->all()['states'] as $state) {
            $this->get(route('elections.maps.show', ['state' => $state['slug']]))->assertOk()->assertSee($state['name']);
        }
        $this->get('/india/elections/maps?state=telangana&kind=ac')->assertOk()
            ->assertSee('combined layer')->assertSee('andhra-pradesh.json');
        $this->get('/india/elections/maps/assam')->assertOk()->assertSee('2023 delimitation');
        $this->get('/india/elections/maps/jammu-and-kashmir')->assertOk()->assertSee('not the current 90-seat map');
        $this->get('/india/elections/maps/ladakh')->assertOk()->assertSee('no separate Ladakh Assembly layer');
        $this->get('/india/elections/maps/not-a-state')->assertNotFound();
        $this->get('/india/elections/maps?state=../../file')->assertNotFound();
        $this->get('/india/elections/maps/uttar-pradesh')->assertOk()->assertSee('Akbarpur');
    }

    public function test_sidebar_links_use_the_correct_state_and_keep_location_maps(): void
    {
        $maps = app(ElectoralMapCatalogue::class);
        $this->assertSame('bihar', $maps->stateFromQuery('Patna, Bihar, India')['slug']);
        $this->assertSame('odisha', $maps->stateFromQuery('Bhubaneswar, Orissa, India')['slug']);
        $this->assertNull($maps->stateFromQuery('Unknown location'));
        $html = view('place-location-map', ['mapName' => 'Kerala', 'mapQuery' => 'Kerala, India'])->render();
        $this->assertStringContainsString('/india/elections/maps/kerala', $html);
        $this->assertStringContainsString('google.com/maps', $html);
    }
}
