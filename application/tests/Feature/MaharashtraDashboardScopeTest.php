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

    public function test_aurangabad_keeps_jalna_segments_and_own_pc(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Aurangabad', 'Maharashtra');
        $this->assertSame(range(104, 112), array_column($links['assembly'], 'code'));
        $this->assertSame([18, 19], array_column($links['parliamentary'], 'code'));
        $this->assertSame([101, 102, 103], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([], $links['parliamentary'][1]['outside_district']);
    }

    public function test_jalna_keeps_parbhani_and_aurangabad_shared_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Jalna', 'Maharashtra');
        $this->assertSame([99, 100, 101, 102, 103], array_column($links['assembly'], 'code'));
        $this->assertSame([17, 18], array_column($links['parliamentary'], 'code'));
        $this->assertSame([95, 96, 97, 98], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([104, 106, 110], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame(['Aurangabad'], array_values(array_unique(array_column($links['parliamentary'][1]['outside_district'], 'district'))));
    }

    public function test_parbhani_shared_pc_retains_jalna_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Parbhani', 'Maharashtra');
        $this->assertSame([95, 96, 97, 98], array_column($links['assembly'], 'code'));
        $this->assertSame([17], array_column($links['parliamentary'], 'code'));
        $this->assertSame([99, 100], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame(['Jalna', 'Jalna'], array_column($links['parliamentary'][0]['outside_district'], 'district'));
    }

    public function test_hingoli_shared_pc_retains_yavatmal_and_nanded(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Hingoli', 'Maharashtra');
        $this->assertSame([92, 93, 94], array_column($links['assembly'], 'code'));
        $this->assertSame([15], array_column($links['parliamentary'], 'code'));
        $this->assertSame([82, 83, 84], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame(['Yavatmal', 'Nanded'], array_values(array_unique(array_column($links['parliamentary'][0]['outside_district'], 'district'))));
    }

    public function test_nanded_keeps_three_pcs_and_latur_outside_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Nanded', 'Maharashtra');
        $this->assertSame(range(83, 91), array_column($links['assembly'], 'code'));
        $this->assertSame([15, 16, 41], array_column($links['parliamentary'], 'code'));
        $this->assertSame([82, 92, 93, 94], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([], $links['parliamentary'][1]['outside_district']);
        $this->assertSame([234, 235, 236, 237, 238], array_column($links['parliamentary'][2]['outside_district'], 'code'));
        $this->assertSame(['Latur'], array_values(array_unique(array_column($links['parliamentary'][2]['outside_district'], 'district'))));
    }

    public function test_yavatmal_keeps_three_shared_pcs_and_hingoli_nanded_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Yavatmal', 'Maharashtra');
        $this->assertSame([76, 77, 78, 79, 80, 81, 82], array_column($links['assembly'], 'code'));
        $this->assertSame([13, 14, 15], array_column($links['parliamentary'], 'code'));
        $this->assertSame([70, 71, 72, 75], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([34, 35], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame([83, 84, 92, 93, 94], array_column($links['parliamentary'][2]['outside_district'], 'code'));
        $this->assertSame(['Nanded', 'Hingoli'], array_values(array_unique(array_column($links['parliamentary'][2]['outside_district'], 'district'))));
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

    public function test_nashik_keeps_dhule_shared_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Nashik', 'Maharashtra');
        $this->assertSame(range(113, 127), array_column($links['assembly'], 'code'));
        $this->assertSame([2, 20, 21], array_column($links['parliamentary'], 'code'));
        $this->assertSame([6, 7, 8], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame(['Dhule', 'Dhule', 'Dhule'], array_column($links['parliamentary'][0]['outside_district'], 'district'));
    }

    public function test_thane_retains_pre_split_electoral_scope(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Thane', 'Maharashtra');
        $this->assertSame(range(128, 151), array_column($links['assembly'], 'code'));
        $this->assertSame([22, 23, 24, 25], array_column($links['parliamentary'], 'code'));
        $this->assertSame([], $links['parliamentary'][0]['outside_district']);
    }

    public function test_mumbai_shared_pc_retains_both_districts(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Mumbai Suburban', 'Maharashtra');
        $this->assertSame(range(152, 177), array_column($links['assembly'], 'code'));
        $this->assertSame([26, 27, 28, 29, 30], array_column($links['parliamentary'], 'code'));
        $this->assertSame([178, 179, 180, 181], array_column($links['parliamentary'][4]['outside_district'], 'code'));
        $city = app(DistrictConstituencyLinks::class)->forDistrict('Mumbai City', 'Maharashtra');
        $this->assertSame(range(178, 187), array_column($city['assembly'], 'code'));
        $this->assertSame([30, 31], array_column($city['parliamentary'], 'code'));
        $this->assertSame([172, 173], array_column($city['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_raigad_keeps_shared_pune_and_ratnagiri_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Raigad', 'Maharashtra');
        $this->assertSame(range(188, 194), array_column($links['assembly'], 'code'));
        $this->assertSame([32, 33], array_column($links['parliamentary'], 'code'));
        $this->assertSame([263, 264], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([204, 205, 206], array_column($links['parliamentary'][1]['outside_district'], 'code'));
    }

    public function test_pune_retains_shared_maval_raigad_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Pune', 'Maharashtra');
        $this->assertSame(range(195, 215), array_column($links['assembly'], 'code'));
        $this->assertSame([33, 34, 35, 36], array_column($links['parliamentary'], 'code'));
        $this->assertSame([188, 189, 190], array_column($links['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_ahmednagar_retains_both_complete_pc_scopes(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Ahmednagar', 'Maharashtra');
        $this->assertSame(range(216, 227), array_column($links['assembly'], 'code'));
        $this->assertSame([37, 38], array_column($links['parliamentary'], 'code'));
        $this->assertSame([], $links['parliamentary'][0]['outside_district']);
        $this->assertSame([], $links['parliamentary'][1]['outside_district']);
    }

    public function test_beed_pc_keeps_all_six_dated_assembly_seats(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Beed', 'Maharashtra');
        $this->assertSame(range(228, 233), array_column($links['assembly'], 'code'));
        $this->assertSame([39], array_column($links['parliamentary'], 'code'));
        $this->assertSame([], $links['parliamentary'][0]['outside_district']);
    }

    public function test_latur_keeps_shared_pc40_and_nanded_loha(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Latur', 'Maharashtra');
        $this->assertSame(range(234, 239), array_column($links['assembly'], 'code'));
        $this->assertSame([40, 41], array_column($links['parliamentary'], 'code'));
        $this->assertSame([240, 241, 242, 243, 246], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([88], array_column($links['parliamentary'][1]['outside_district'], 'code'));
    }

    public function test_osmanabad_retains_latur_and_solapur_pc40_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Osmanabad', 'Maharashtra');
        $this->assertSame(range(240, 243), array_column($links['assembly'], 'code'));
        $this->assertSame([40], array_column($links['parliamentary'], 'code'));
        $this->assertSame([239, 246], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame(['Latur', 'Solapur'], array_column($links['parliamentary'][0]['outside_district'], 'district'));
    }

    public function test_solapur_preserves_two_cross_district_pcs(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Solapur', 'Maharashtra');
        $this->assertSame(range(244, 254), array_column($links['assembly'], 'code'));
        $this->assertSame([40, 42, 43], array_column($links['parliamentary'], 'code'));
        $this->assertSame([239, 240, 241, 242, 243], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([255, 258], array_column($links['parliamentary'][2]['outside_district'], 'code'));
    }

    public function test_satara_retains_solapur_segments_in_shared_madha_pc(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Satara', 'Maharashtra');
        $this->assertSame(range(255, 262), array_column($links['assembly'], 'code'));
        $this->assertSame([43, 45], array_column($links['parliamentary'], 'code'));
        $this->assertSame([244, 245, 253, 254], array_column($links['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_ratnagiri_sindhudurg_preserves_shared_pc_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Ratnagiri', 'Maharashtra');
        $this->assertSame(range(263, 267), array_column($links['assembly'], 'code'));
        $this->assertSame([32, 46], array_column($links['parliamentary'], 'code'));
        $this->assertSame([268, 269, 270], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $other = app(DistrictConstituencyLinks::class)->forDistrict('Sindhudurg', 'Maharashtra');
        $this->assertSame([268, 269, 270], array_column($other['assembly'], 'code'));
        $this->assertSame([46], array_column($other['parliamentary'], 'code'));
        $this->assertSame([265, 266, 267], array_column($other['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_kolhapur_sangli_retains_shared_hatkanangle_pc(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Kolhapur', 'Maharashtra');
        $this->assertSame(range(271, 280), array_column($links['assembly'], 'code'));
        $this->assertSame([47, 48], array_column($links['parliamentary'], 'code'));
        $this->assertSame([283, 284], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $other = app(DistrictConstituencyLinks::class)->forDistrict('Sangli', 'Maharashtra');
        $this->assertSame(range(281, 288), array_column($other['assembly'], 'code'));
        $this->assertSame([44, 48], array_column($other['parliamentary'], 'code'));
        $this->assertSame([277, 278, 279, 280], array_column($other['parliamentary'][1]['outside_district'], 'code'));
    }

    public function test_kachchh_shared_pc_retains_dated_rajkot_morbi(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Kachchh', 'Gujarat');
        $this->assertSame(range(1, 6), array_column($links['assembly'], 'code'));
        $this->assertSame([1], array_column($links['parliamentary'], 'code'));
        $this->assertSame([65], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame('Rajkot', $links['parliamentary'][0]['outside_district'][0]['district']);
    }

    public function test_banaskantha_keeps_shared_patan_and_mahesana_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Banaskantha', 'Gujarat');
        $this->assertSame(range(7, 15), array_column($links['assembly'], 'code'));
        $this->assertSame([2, 3], array_column($links['parliamentary'], 'code'));
        $this->assertSame([16, 17, 18, 19, 20], array_column($links['parliamentary'][1]['outside_district'], 'code'));
    }

    public function test_patan_keeps_banaskantha_and_mahesana_pc_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Patan', 'Gujarat');
        $this->assertSame(range(16, 19), array_column($links['assembly'], 'code'));
        $this->assertSame([3], array_column($links['parliamentary'], 'code'));
        $this->assertSame([11, 15, 20], array_column($links['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_mahesana_keeps_mansa_and_shared_patan_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Mahesana', 'Gujarat');
        $this->assertSame(range(20, 26), array_column($links['assembly'], 'code'));
        $this->assertSame([3, 4], array_column($links['parliamentary'], 'code'));
        $this->assertSame([37], array_column($links['parliamentary'][1]['outside_district'], 'code'));
    }

    public function test_sabarkantha_retains_complete_dated_pc_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Sabarkantha', 'Gujarat');
        $this->assertSame(range(27, 33), array_column($links['assembly'], 'code'));
        $this->assertSame([5], array_column($links['parliamentary'], 'code'));
        $this->assertSame([], $links['parliamentary'][0]['outside_district']);
    }

    public function test_gandhinagar_retains_three_shared_parliamentary_seats(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Gandhinagar', 'Gujarat');
        $this->assertSame(range(34, 38), array_column($links['assembly'], 'code'));
        $this->assertSame([4, 6, 7], array_column($links['parliamentary'], 'code'));
        $this->assertSame([40, 41, 42, 45, 55], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame([43, 46, 47, 48, 49], array_column($links['parliamentary'][2]['outside_district'], 'code'));
    }

    public function test_ahmedabad_keeps_all_five_parliamentary_seats(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Ahmedabad', 'Gujarat');
        $this->assertSame(range(39, 59), array_column($links['assembly'], 'code'));
        $this->assertSame([6, 7, 8, 9, 17], array_column($links['parliamentary'], 'code'));
        $this->assertSame([60, 61, 62, 63, 64], array_column($links['parliamentary'][3]['outside_district'], 'code'));
        $this->assertSame([115, 116, 117, 118, 120], array_column($links['parliamentary'][4]['outside_district'], 'code'));
    }

    public function test_surendranagar_keeps_ahmedabad_pc_segments(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Surendranagar', 'Gujarat');
        $this->assertSame(range(60, 64), array_column($links['assembly'], 'code'));
        $this->assertSame([9], array_column($links['parliamentary'], 'code'));
        $this->assertSame([39, 59], array_column($links['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_rajkot_keeps_kachchh_and_porbandar_shared_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Rajkot', 'Gujarat');
        $this->assertSame(range(65, 75), array_column($links['assembly'], 'code'));
        $this->assertSame([1, 10, 11], array_column($links['parliamentary'], 'code'));
        $this->assertSame([83, 84, 85, 88], array_column($links['parliamentary'][2]['outside_district'], 'code'));
    }

    public function test_jamnagar_retains_dated_dwarka_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Jamnagar', 'Gujarat');
        $this->assertSame(range(76, 82), array_column($links['assembly'], 'code'));
        $this->assertSame([12], array_column($links['parliamentary'], 'code'));
        $this->assertSame([], $links['parliamentary'][0]['outside_district']);
    }

    public function test_porbandar_keeps_rajkot_and_junagadh_shared_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Porbandar', 'Gujarat');
        $this->assertSame([83, 84], array_column($links['assembly'], 'code'));
        $this->assertSame([11], array_column($links['parliamentary'], 'code'));
        $this->assertSame([73, 74, 75, 85, 88], array_column($links['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_junagadh_retains_dated_gir_somnath_and_shared_porbandar(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Junagadh', 'Gujarat');
        $this->assertSame(range(85, 93), array_column($links['assembly'], 'code'));
        $this->assertSame([11, 13], array_column($links['parliamentary'], 'code'));
        $this->assertSame([73, 74, 75, 83, 84], array_column($links['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_amreli_retains_bhavnagar_mahuva_and_gariadhar(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Amreli', 'Gujarat');
        $this->assertSame(range(94, 98), array_column($links['assembly'], 'code'));
        $this->assertSame([14], array_column($links['parliamentary'], 'code'));
        $this->assertSame([99, 101], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame('Bhavnagar', $links['parliamentary'][0]['outside_district'][0]['district']);
    }

    public function test_bhavnagar_retains_botad_and_shared_amreli_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Bhavnagar', 'Gujarat');
        $this->assertSame(range(99, 107), array_column($links['assembly'], 'code'));
        $this->assertSame([14, 15], array_column($links['parliamentary'], 'code'));
        $this->assertSame(range(94, 98), array_column($links['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_anand_retains_complete_dated_pc_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Anand', 'Gujarat');
        $this->assertSame(range(108, 114), array_column($links['assembly'], 'code'));
        $this->assertSame([16], array_column($links['parliamentary'], 'code'));
        $this->assertSame([], $links['parliamentary'][0]['outside_district']);
    }

    public function test_kheda_keeps_ahmedabad_and_panchmahals_shared_membership(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Kheda', 'Gujarat');
        $this->assertSame(range(115, 121), array_column($links['assembly'], 'code'));
        $this->assertSame([17, 18], array_column($links['parliamentary'], 'code'));
        $this->assertSame([57, 58], array_column($links['parliamentary'][0]['outside_district'], 'code'));
        $this->assertSame([122, 124, 125, 126, 127], array_column($links['parliamentary'][1]['outside_district'], 'code'));
    }

    public function test_panchmahals_retains_three_shared_pc_scopes(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Panchmahals', 'Gujarat');
        $this->assertSame(range(122, 128), array_column($links['assembly'], 'code'));
        $this->assertSame([18, 19, 21], array_column($links['parliamentary'], 'code'));
        $this->assertSame([129, 130, 131, 132, 133, 134], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame([137, 138, 139, 140, 146, 148], array_column($links['parliamentary'][2]['outside_district'], 'code'));
    }

    public function test_dahod_keeps_panchmahals_santrampur_in_pc19(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Dahod', 'Gujarat');
        $this->assertSame(range(129, 134), array_column($links['assembly'], 'code'));
        $this->assertSame([19], array_column($links['parliamentary'], 'code'));
        $this->assertSame([123], array_column($links['parliamentary'][0]['outside_district'], 'code'));
    }

    public function test_vadodara_retains_three_pc_scopes_and_chhota_udaipur(): void
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Vadodara', 'Gujarat');
        $this->assertSame(range(135, 147), array_column($links['assembly'], 'code'));
        $this->assertSame([20, 21, 22], array_column($links['parliamentary'], 'code'));
        $this->assertSame([128, 148], array_column($links['parliamentary'][1]['outside_district'], 'code'));
        $this->assertSame([149, 150, 151, 152, 153, 154], array_column($links['parliamentary'][2]['outside_district'], 'code'));
    }
}
