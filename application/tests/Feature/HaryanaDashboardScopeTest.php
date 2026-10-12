<?php

namespace Tests\Feature;

use App\Services\DistrictConstituencyLinks;
use App\Services\DistrictDashboardData;
use Tests\TestCase;

class HaryanaDashboardScopeTest extends TestCase
{
    public function test_panchkula_keeps_complete_shared_ambala_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Panchkula', 'Haryana');
        $this->assertSame([1, 2], array_column($links['assembly'], 'code'));
        $this->assertSame([1], array_column($links['parliamentary'], 'code'));
        $this->assertSame(range(1, 9), $links['parliamentary'][0]['ac_codes']);
        $this->assertSame(range(3, 9), array_column($links['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_context_reads_haryana_on_each_render_and_preserves_gaps(): void
    {
        $data = $this->mock(DistrictDashboardData::class);
        $data->shouldReceive('published')->twice()->with('069', '06')->andReturn(null, ['year' => 2011, 'totals' => ['Total' => ['population' => 561293, 'literacy' => null]]]);
        $args = ['state' => 'Haryana', 'kind' => 'pc', 'name' => 'Ambala', 'exactSeatOnly' => false];
        $first = view('haryana-constituency-context', $args)->render();
        $second = view('haryana-constituency-context', $args)->render();
        $this->assertStringContainsString('Published district Census records are unavailable', $first);
        $this->assertStringContainsString('561,293', $second);
        $this->assertStringContainsString('/india/districts/06/069/dashboard', $second);
        $this->assertStringContainsString('Panchkula, Ambala, Yamunanagar', $second);
        $this->assertStringContainsString('combined populations are not a constituency total', $second);
        $this->assertStringContainsString('District code crosswalk is not established', $second);
        $this->assertStringContainsString('Yamunanagar (AC 9)', $second);
    }
}
