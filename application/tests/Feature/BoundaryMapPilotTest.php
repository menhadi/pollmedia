<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoundaryMapPilotTest extends TestCase
{
    use RefreshDatabase;

    public function test_pilot_preserves_sources_and_does_not_claim_verified_joins(): void
    {
        $data = json_decode(file_get_contents(public_path('maps/up-pilot.json')), true, flags: JSON_THROW_ON_ERROR);
        $pc = collect($data['features'])->where('properties.kind', 'pc');
        $ac = collect($data['features'])->where('properties.kind', 'ac');
        $this->assertCount(80, $pc);
        $this->assertCount(403, $ac);
        $this->assertCount(403, $ac->pluck('properties.code')->unique());
        $this->assertSame('Pilibhit', $pc->firstWhere('properties.code', 26)['properties']['name']);
        $this->assertSame([44, 79], array_column($data['metadata']['flagged'], 'code'));
        $this->assertSame([44, 79], $pc->where('properties.geometry_status', 'flagged')->pluck('properties.code')->values()->all());
        foreach ($data['features'] as $feature) {
            $this->assertSame('unverified', $feature['properties']['join_status']);
            $this->assertContains($feature['geometry']['type'], ['Polygon', 'MultiPolygon']);
        }
        foreach ($data['metadata']['sources'] as $source) {
            $this->assertStringContainsString($data['metadata']['revision'], $source['url']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $source['sha256']);
        }
        $this->get(route('elections.map-pilot'))->assertOk()
            ->assertSee('noindex,follow', false)->assertSee('not a verified official electoral map')
            ->assertSee('CC BY 2.5 India')->assertSee('Akbarpur')->assertSee('data-finder=', false);
    }
}
