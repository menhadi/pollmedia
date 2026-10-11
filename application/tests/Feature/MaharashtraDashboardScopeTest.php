<?php

namespace Tests\Feature;

use App\Services\DistrictConstituencyLinks;
use App\Services\DistrictDashboardData;
use Tests\TestCase;

class MaharashtraDashboardScopeTest extends TestCase
{
    public function test_buldhana_retains_shared_raver_and_corroborated_mehkar_segment(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Buldhana', 'Maharashtra');
        $this->assertSame(range(21, 27), array_column($links['assembly'], 'code'));
        $this->assertSame([4, 5], array_column($links['parliamentary'], 'code'));
        $this->assertSame([10, 11, 12, 19, 20], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $fixture = json_decode(file_get_contents(database_path('fixtures/maharashtra-electoral-geography.json')), true);
        $pc = $fixture['pcs'][4];
        $this->assertSame(range(22, 27), $pc['ac_codes']);
        $this->assertStringContainsString('prints5.Mehkar', $pc['source_code_note']);
        $this->assertStringContainsString('ceoelection.maharashtra.gov.in', $pc['corroborating_url']);
    }

    public function test_akola_pc_retains_washim_risod_segment(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Akola', 'Maharashtra');
        $this->assertSame(range(28, 32), array_column($links['assembly'], 'code'));
        $this->assertSame([6], array_column($links['parliamentary'], 'code'));
        $this->assertSame([33], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame('Washim', $links['parliamentary'][0]['outside_district'][0]['district']);
    }

    public function test_washim_retains_both_shared_pcs_and_yavatmal_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Washim', 'Maharashtra');
        $this->assertSame([33, 34, 35], array_column($links['assembly'], 'code'));
        $this->assertSame([6, 14], array_column($links['parliamentary'], 'code'));
        $this->assertSame(range(28, 32), array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([77, 78, 79, 81], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame(['Yavatmal'], array_values(array_unique(array_column($links['parliamentary'][1]['outside_district'], 'district'))));
    }

    public function test_amravati_retains_shared_wardha_pc_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Amravati', 'Maharashtra');
        $this->assertSame(range(36, 43), array_column($links['assembly'], 'code'));
        $this->assertSame([7, 8], array_column($links['parliamentary'], 'code'));
        $this->assertSame(range(44, 47), array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame(['Wardha'], array_values(array_unique(array_column($links['parliamentary'][1]['outside_district'], 'district'))));
    }

    public function test_wardha_pc_retains_amravati_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Wardha', 'Maharashtra');
        $this->assertSame(range(44, 47), array_column($links['assembly'], 'code'));
        $this->assertSame([8], array_column($links['parliamentary'], 'code'));
        $this->assertSame([36, 43], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame(['Amravati'], array_values(array_unique(array_column($links['parliamentary'][0]['outside_district'], 'district'))));
    }

    public function test_nagpur_maps_twelve_acs_to_two_complete_pcs(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Nagpur', 'Maharashtra');
        $this->assertSame(range(48, 59), array_column($links['assembly'], 'code'));
        $this->assertSame([9, 10], array_column($links['parliamentary'], 'code'));
        $this->assertSame([], $links['parliamentary'][0]['outside_district']);
        $this->assertSame([], $links['parliamentary'][1]['outside_district']);
    }

    public function test_chandrapur_keeps_both_shared_pcs_and_yavatmal_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Chandrapur', 'Maharashtra');
        $this->assertSame([70, 71, 72, 73, 74, 75], array_column($links['assembly'], 'code'));
        $this->assertSame([12, 13], array_column($links['parliamentary'], 'code'));
        $this->assertSame([66, 67, 68, 69], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([76, 80], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame(['Yavatmal', 'Yavatmal'], array_column($links['parliamentary'][1]['outside_district'], 'district'));
    }

    public function test_gadchiroli_shared_pc_retains_gondiya_and_chandrapur(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Gadchiroli', 'Maharashtra');
        $this->assertSame([67, 68, 69], array_column($links['assembly'], 'code'));
        $this->assertSame([12], array_column($links['parliamentary'], 'code'));
        $this->assertSame([66, 73, 74], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame(['Gondiya', 'Chandrapur'], array_values(array_unique(array_column($links['parliamentary'][0]['outside_district'], 'district'))));
    }

    public function test_gondiya_keeps_two_shared_pcs_and_all_outside_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Gondiya', 'Maharashtra');
        $this->assertSame([63, 64, 65, 66], array_column($links['assembly'], 'code'));
        $this->assertSame([11, 12], array_column($links['parliamentary'], 'code'));
        $this->assertSame([60, 61, 62], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([67, 68, 69, 73, 74], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame(['Gadchiroli', 'Chandrapur'], array_values(array_unique(array_column($links['parliamentary'][1]['outside_district'], 'district'))));
    }

    public function test_bhandara_pc_retains_gondiya_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Bhandara', 'Maharashtra');
        $this->assertSame([60, 61, 62], array_column($links['assembly'], 'code'));
        $this->assertSame([11], array_column($links['parliamentary'], 'code'));
        $this->assertSame([63, 64, 65], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame(['Gondiya'], array_values(array_unique(array_column($links['parliamentary'][0]['outside_district'], 'district'))));
    }

    public function test_dhule_retains_both_shared_pcs_and_nashik_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Dhule', 'Maharashtra');
        $this->assertSame([5, 6, 7, 8, 9], array_column($links['assembly'], 'code'));
        $this->assertSame([1, 2], array_column($links['parliamentary'], 'code'));
        $this->assertSame([114, 115, 116], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame(['Nashik', 'Nashik', 'Nashik'], array_column($links['parliamentary'][1]['outside_district'], 'district'));
    }

    public function test_jalgaon_shared_raver_retains_buldhana_segment(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Jalgaon', 'Maharashtra');
        $this->assertSame(range(10, 20), array_column($links['assembly'], 'code'));
        $this->assertSame([3, 4], array_column($links['parliamentary'], 'code'));
        $this->assertSame([21], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame('Buldhana', $links['parliamentary'][1]['outside_district'][0]['district']);
    }

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
