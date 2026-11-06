<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use App\Services\IndiaVillageMap;
use App\Services\VillageCensusRecords;
use App\Services\VillageMapMetrics;
use Illuminate\Support\Facades\DB;

class VillageMapMetricsTest extends VillageProfileLinksTest
{
    public function test_verified_town_values_replace_a_legacy_village_metric_without_duplicate_shapes(): void
    {
        DB::statement('ALTER TABLE census_editions ADD COLUMN sha256 TEXT');
        DB::statement('ALTER TABLE census_catalogue_rows ADD COLUMN record_key TEXT');
        DB::statement('ALTER TABLE census_catalogue_rows ADD COLUMN source_row INTEGER');
        $sha = str_repeat('a', 64);
        $path = sys_get_temp_dir().'/town-metrics-'.bin2hex(random_bytes(5)).'.geojson';
        file_put_contents($path, json_encode(['features' => [4 => ['properties' => ['source_code' => '405299', 'name' => 'Existing town']]]]));
        $map = $this->mock(IndiaVillageMap::class);
        $map->shouldReceive('layer')->andReturn(['id' => str_repeat('b', 20), 'state' => 'Odisha', 'file' => 'maps/test.geojson', 'sha256' => $sha]);
        $map->shouldReceive('geometryPath')->once()->andReturn($path);
        $db = new \PDO('sqlite:'.app(ElectoralVillageMap::class)->directory().'/links.sqlite');
        $db->exec("UPDATE layers SET sha256='$sha'");
        $row = (object) ['id' => 43, 'record_key' => 'original-town', 'level' => 'TOWN', 'residence' => 'Urban', 'state_code' => '21', 'district_code' => '385', 'name' => 'Existing town',
            'geography' => json_encode(['Town/Village' => '405299']), 'edition_id' => 1, 'source_row' => 20, 'source_key' => 'existing-2011', 'source_url' => 'https://censusindia.gov.in/source', 'source_sha256' => $sha, 'flags' => '[]', 'values' => json_encode(['TOT_P' => 200, 'No_HH' => 40])];
        $this->mock(VillageCensusRecords::class)->shouldReceive('sourceMatches')->once()->andReturn([4 => $row]);
        try {
            $data = app(VillageMapMetrics::class)->layer(str_repeat('b', 20), $sha);
            $this->assertCount(1, $data['records']);
            $this->assertSame(43, $data['records'][0]['record_id']);
            $this->assertSame(200.0, $data['records'][0]['values']['population']);
            $this->assertSame('700001', $data['records'][0]['original_lgd_village_code']);
        } finally {
            unlink($path);
        }
    }

    public function test_metrics_reuse_published_records_and_keep_source_identity(): void
    {
        DB::statement('ALTER TABLE census_editions ADD COLUMN sha256 TEXT');
        DB::statement('ALTER TABLE census_catalogue_rows ADD COLUMN record_key TEXT');
        DB::statement('ALTER TABLE census_catalogue_rows ADD COLUMN source_row INTEGER');
        $map = $this->mock(IndiaVillageMap::class);
        $sha = str_repeat('a', 64);
        $map->shouldReceive('layer')->andReturn(['file' => 'maps/test.geojson', 'sha256' => $sha]);
        $map->shouldReceive('geometryPath')->once()->andReturn('verified');
        $db = new \PDO('sqlite:'.app(ElectoralVillageMap::class)->directory().'/links.sqlite');
        $db->exec("UPDATE layers SET sha256='$sha'");
        $data = app(VillageMapMetrics::class)->layer(str_repeat('b', 20), $sha);
        $this->assertCount(1, $data['records']);
        $row = $data['records'][0];
        $this->assertSame('700001', $row['lgd_code']);
        $this->assertSame('405299', $row['census_code']);
        $this->assertSame(42, $row['record_id']);
        $this->assertSame(4, $row['feature_index']);
        $this->assertSame(['population' => 100.0, 'households' => 20.0, 'literacy' => 75.0], array_intersect_key($row['values'], array_flip(['population', 'households', 'literacy'])));
        DB::table('census_publications')->delete();
        $map->shouldReceive('geometryPath')->once()->andReturn('verified');
        $this->assertSame([], app(VillageMapMetrics::class)->layer(str_repeat('b', 20), $sha)['records']);
    }

    public function test_missing_values_are_distinct_from_zero_and_literacy_uses_age_seven_plus(): void
    {
        $this->assertSame(['population' => null, 'households' => null, 'literacy' => null], VillageMapMetrics::values([]));
        $this->assertSame(['c11_TOT_P' => 0.0, 'c11_No_HH' => 0.0, 'c11_P_06' => 0.0, 'c11_P_LIT' => 0.0, 'population' => 0.0, 'households' => 0.0, 'literacy' => null], VillageMapMetrics::values(['TOT_P' => 0, 'No_HH' => 0, 'P_06' => 0, 'P_LIT' => 0]));
        $this->assertSame(['c11_M_06' => 12.0, 'c11_F_LIT' => 0.0, 'c11_MAINWORK_P' => null], array_intersect_key(VillageMapMetrics::values(['M_06' => 12, 'F_LIT' => 0, 'MAINWORK_P' => null]), array_flip(['c11_M_06', 'c11_F_LIT', 'c11_MAINWORK_P'])));
        $this->assertNull(VillageMapMetrics::values(['TOT_P' => 10, 'P_06' => 8, 'P_LIT' => 8])['literacy']);
    }

    public function test_api_rejects_an_unverified_edition_or_missing_geometry_hash(): void
    {
        $url = '/api/india-map/metrics/'.str_repeat('b', 20);
        $this->getJson($url.'?year=2011')->assertUnprocessable();
        $this->getJson($url.'?year=2021&sha='.str_repeat('a', 64))->assertUnprocessable();
    }
}
