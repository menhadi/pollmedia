<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use App\Services\IndiaVillageMap;
use App\Services\VillageCensusRecords;
use App\Services\VillageProfileLinks;
use Illuminate\Support\Facades\DB;

class VillageCensusRecordsTest extends VillageProfileLinksTest
{
    public function test_qualified_urban_source_codes_reuse_original_records_and_reject_changed_editions(): void
    {
        DB::table('census_catalogue_rows')->update(['level' => 'TOWN', 'residence' => 'Urban', 'name' => 'Official spelling']);
        $sha = str_repeat('a', 64);
        DB::table('census_editions')->update(['sha256' => $sha]);
        $id = str_repeat('b', 20);
        $path = app(ElectoralVillageMap::class)->directory().'/urban-map-links.json';
        $previous = is_file($path) ? file_get_contents($path) : null;
        $feature = ['source_code' => '405299', 'name' => 'Original SOI spelling'];
        $layer = ['id' => $id, 'file' => 'maps/test.geojson', 'sha256' => $sha];
        file_put_contents($path, json_encode(['year' => 2011, 'layers' => [$id => ['file' => $layer['file'], 'sha256' => $sha, 'records' => [4 => ['source_code' => '405299', 'source_name' => $feature['name'], 'record_id' => 42, 'record_key' => 'original-key', 'edition_id' => 1, 'source_sha256' => $sha, 'basis' => 'Original full official codes checked']]]]]));
        try {
            $service = app(VillageCensusRecords::class);
            $this->assertSame([], $service->sourceMatches('Odisha', [4 => $feature]));
            $rows = $service->sourceMatches('Odisha', [4 => $feature], $layer);
            $this->assertSame(42, $rows[4]->id);
            $this->assertSame('Original full official codes checked', $service->profile($rows[4])['link_basis']);
            $this->assertSame([], $service->sourceMatches('Odisha', [4 => $feature], array_replace($layer, ['sha256' => 'changed'])));
            $this->assertSame([], $service->sourceMatches('Odisha', [4 => array_replace($feature, ['source_code' => '405300'])], $layer));
            DB::table('census_publications')->delete();
            $this->assertSame([], $service->sourceMatches('Odisha', [4 => $feature], $layer));
        } finally {
            if ($previous !== null) {
                file_put_contents($path, $previous);
            } else {
                unlink($path);
            }
        }
    }

    public function test_urban_only_district_is_available_without_rural_village_ancestry(): void
    {
        $service = app(ElectoralVillageMap::class);
        $db = new \PDO('sqlite:'.$service->directory().'/links.sqlite');
        $db->exec('ALTER TABLE membership ADD COLUMN entity_name TEXT');
        $this->assertContains(['code' => '315', 'name' => 'Kolkata'], $service->hierarchy('19')['options']);
        $this->assertNotContains(['code' => '315', 'name' => 'Kolkata'], $service->hierarchy('21')['options']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('ALTER TABLE census_editions ADD COLUMN sha256 TEXT');
        DB::statement('ALTER TABLE census_catalogue_rows ADD COLUMN name TEXT');
        DB::statement('ALTER TABLE census_catalogue_rows ADD COLUMN record_key TEXT');
        DB::statement('ALTER TABLE census_catalogue_rows ADD COLUMN source_row INTEGER');
        DB::table('census_catalogue_rows')->update(['name' => 'Existing village (122)', 'record_key' => 'original-key', 'source_row' => 12]);
    }

    public function test_source_correspondence_reuses_the_published_identity_and_dated_values(): void
    {
        $service = app(VillageCensusRecords::class);
        $rows = $service->sourceMatches('Odisha', [4 => ['source_code' => '405299', 'name' => 'EXISTING VILLAGE']]);
        $this->assertCount(1, $rows);
        $profile = $service->profile($rows[4]);
        $this->assertSame(route('civic.place', ['record' => 42]), $profile['url']);
        $this->assertSame('405299', $profile['census']['code']);
        $this->assertSame('original-key', $profile['census']['record_key']);
        $this->assertSame(100.0, $profile['census']['population']);
        $this->assertSame(75.0, $profile['census']['literacy_rate']);
        $this->assertArrayNotHasKey('lgd_code', $profile);
    }

    public function test_source_correspondence_withholds_duplicate_codes_wrong_names_and_withdrawn_editions(): void
    {
        $service = app(VillageCensusRecords::class);
        $feature = ['source_code' => '405299', 'name' => 'Existing village'];
        $this->assertSame([], $service->sourceMatches('Odisha', [$feature, $feature]));
        $this->assertSame([], $service->sourceMatches('Odisha', [array_replace($feature, ['name' => 'Different'])]));
        $this->assertSame([], $service->sourceMatches('Different state', [$feature]));
        DB::table('census_publications')->delete();
        $this->assertSame([], $service->sourceMatches('Odisha', [$feature]));
    }

    public function test_urban_polygon_uses_the_existing_town_identity_without_inventing_lgd_or_ward_links(): void
    {
        DB::table('census_catalogue_rows')->update(['level' => 'TOWN', 'residence' => 'Urban', 'name' => 'Existing city (M Corp.)']);
        $service = app(VillageCensusRecords::class);
        $feature = ['source_code' => '405299', 'name' => 'Existing city (M Corp)'];
        $rows = $service->sourceMatches('Odisha', [$feature]);
        $this->assertCount(1, $rows);
        $profile = $service->profile($rows[0]);
        $this->assertSame('town', $profile['kind']);
        $this->assertCount(2, $service->sourceMatches('Odisha', [$feature, $feature]));
        $this->assertSame([], $service->sourceMatches('Odisha', [$feature, array_replace($feature, ['name' => 'Another city'])]));
        $this->assertSame(42, $profile['census']['record_id']);
        $this->assertSame(100.0, $profile['census']['population']);
        $this->assertArrayNotHasKey('lgd_code', $profile);
        DB::table('census_catalogue_rows')->update(['level' => 'WARD']);
        $this->assertSame([], $service->sourceMatches('Odisha', [$feature]));
        DB::table('census_catalogue_rows')->update(['level' => 'TOWN', 'residence' => 'Rural']);
        $this->assertSame([], $service->sourceMatches('Odisha', [$feature]));
    }

    public function test_dated_town_profile_preserves_an_existing_lgd_village_link_separately(): void
    {
        DB::table('census_catalogue_rows')->update(['level' => 'TOWN', 'residence' => 'Urban', 'name' => 'Existing city (CT)']);
        $path = tempnam(sys_get_temp_dir(), 'urban-profile-');
        file_put_contents($path, json_encode(['features' => [4 => ['properties' => ['source_code' => '405299', 'name' => 'Existing city (CT)']]]]));
        $map = $this->mock(IndiaVillageMap::class);
        $map->shouldReceive('geometryPath')->once()->andReturn($path);
        try {
            $profile = app(VillageProfileLinks::class)->forShape(['feature_index' => 4], ['file' => 'maps/test.geojson', 'sha256' => 'abc', 'state' => 'Odisha'], true);
            $this->assertSame('town', $profile['kind']);
            $this->assertSame('700001', $profile['original_lgd_village_code']);
            $this->assertSame(route('civic.place', ['record' => 42]), $profile['url']);
        } finally {
            unlink($path);
        }
    }
}
