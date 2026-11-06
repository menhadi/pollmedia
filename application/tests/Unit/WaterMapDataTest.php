<?php

namespace Tests\Unit;

use App\Services\WaterMapData;
use PHPUnit\Framework\TestCase;

class WaterMapDataTest extends TestCase
{
    public function test_water_joins_by_feature_index_without_replacing_census_counts(): void
    {
        $records = [['feature_index' => 3, 'values' => ['households' => 40]], ['feature_index' => 8, 'values' => ['households' => 70]]];
        $water = [8 => ['coverage_pct' => 0, 'households' => 90, 'tap_connections' => 0, 'jjm_village_id' => '123', 'lgd_village_code' => '456']];
        $result = WaterMapData::merge($records, $water);
        $this->assertSame(['households' => 40], $result[0]['values']);
        $this->assertSame(70, $result[1]['values']['households']);
        $this->assertSame(0, $result[1]['values']['water']);
        $this->assertSame(90, $result[1]['water']['households']);
    }

    public function test_missing_water_and_towns_are_not_assigned_zero(): void
    {
        $result = WaterMapData::merge([['feature_index' => 1, 'level' => 'TOWN', 'values' => []]], [1 => ['coverage_pct' => 100], 2 => ['coverage_pct' => null]]);
        $this->assertArrayNotHasKey('water', $result[0]['values']);
        $this->assertNull($result[1]['values']['water']);
    }
}
