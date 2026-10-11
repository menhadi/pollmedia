<?php

namespace Tests\Feature;

use App\Services\DistrictConstituencyLinks;
use App\Services\DistrictDashboardData;
use Tests\TestCase;

class MaharashtraDashboardScopeTest extends TestCase
{
    public function test_dated_shared_pc_keeps_dhule_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Nandurbar', 'Maharashtra');
        $this->assertSame([1, 2, 3, 4], array_column($links['assembly'], 'code'));
        $this->assertSame([1], array_column($links['parliamentary'], 'code'));
        $this->assertSame([5, 9], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame(['Dhule', 'Dhule'], array_column($links['parliamentary'][0]['outside_district'], 'district'));
    }

    public function test_pc_context_reads_both_districts_on_each_render_without_combining_totals(): void
    {
        $data = $this->mock(DistrictDashboardData::class);
        $data->shouldReceive('published')->twice()->with('497', '27')->andReturn(null, ['year' => 2011, 'totals' => ['Total' => ['population' => 1234, 'literacy' => null]]]);
        $data->shouldReceive('published')->twice()->with('498', '27')->andReturn(null);
        $args = ['state' => 'Maharashtra', 'kind' => 'pc', 'name' => 'Nandurbar', 'exactSeatOnly' => false];
        $first = view('maharashtra-constituency-context', $args)->render();
        $second = view('maharashtra-constituency-context', $args)->render();
        $this->assertStringContainsString('Nandurbar, Dhule', $first);
        $this->assertStringContainsString('combined populations are not a constituency total', $first);
        $this->assertStringContainsString('Published district Census records are unavailable', $first);
        $this->assertStringContainsString('1,234', $second);
        $this->assertStringContainsString('Sakri (ST) (AC 5)', $second);
        $this->assertStringContainsString('Shirpur (ST) (AC 9)', $second);
        $this->assertStringContainsString('NANDURB&gt;R', $second);
    }
}
