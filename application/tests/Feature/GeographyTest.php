<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeographyTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_filters_country_and_source_defined_types(): void
    {
        DB::table('places')->insert([
            ['slug' => 'foreign-borough', 'name' => 'Test borough', 'type' => 'borough', 'country_code' => 'GB'],
            ['slug' => 'other-county', 'name' => 'Test county', 'type' => 'county', 'country_code' => 'US'],
        ]);
        $this->get('/explore?country=GB&type=borough')->assertOk()->assertSee('Test borough')->assertDontSee('Test county')
            ->assertViewHas('places', fn ($places) => $places->total() === 1)
            ->assertViewHas('types', fn ($types) => $types->all() === ['borough']);
        $this->get('/explore?country=GB&type=county')->assertOk()->assertSee('No recorded places match');
        $this->get('/explore/places/unknown')->assertNotFound();
    }

    public function test_shared_profile_preserves_missing_values_and_hides_unaccepted_evidence(): void
    {
        $this->seed(PilibhitSeeder::class);
        $place = DB::table('places')->insertGetId(['slug' => 'test-global-place', 'name' => 'Test global place', 'type' => 'borough', 'country_code' => 'GB']);
        $release = DB::table('source_releases')->where('status', 'accepted')->first();
        $pending = DB::table('source_releases')->insertGetId(['data_source_id' => $release->data_source_id, 'version_key' => 'pending-global-test',
            'url' => 'https://example.test/pending', 'retrieved_at' => now(), 'status' => 'pending']);
        $indicator = DB::table('indicators')->value('id');
        DB::table('observations')->insert([
            ['place_id' => $place, 'indicator_id' => $indicator, 'source_release_id' => $release->id, 'period' => '2001', 'value' => null, 'status' => 'reported'],
            ['place_id' => $place, 'indicator_id' => $indicator, 'source_release_id' => $pending, 'period' => '2025', 'value' => 987654, 'status' => 'reported'],
        ]);
        $this->get('/explore/places/test-global-place')->assertOk()->assertSee('Test global place')->assertSee('Not available')
            ->assertDontSee('987654')->assertDontSee('https://example.test/pending')
            ->assertSee('No verified officeholder assignments')->assertViewHas('observations', fn ($rows) => $rows->count() === 1);
        $this->get('/explore/places/district-pilibhit')->assertOk()->assertSee('Pilibhit')->assertSee('Official evidence');
    }

    public function test_expired_relationships_do_not_appear_as_current_links(): void
    {
        $this->seed(PilibhitSeeder::class);
        $one = DB::table('places')->insertGetId(['slug' => 'one', 'name' => 'One', 'type' => 'ward', 'country_code' => 'GB']);
        $two = DB::table('places')->insertGetId(['slug' => 'two', 'name' => 'Expired parent', 'type' => 'borough', 'country_code' => 'GB']);
        DB::table('place_relationships')->insert(['from_place_id' => $one, 'to_place_id' => $two, 'type' => 'within',
            'source_release_id' => DB::table('source_releases')->value('id'), 'valid_to' => now()->subDay()->toDateString()]);
        $this->get('/explore/places/one')->assertOk()->assertDontSee('Expired parent')->assertSee('No current source-backed relationships');
    }
}
