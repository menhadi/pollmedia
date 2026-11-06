<?php

namespace Tests\Feature;

use App\Services\MyPlaceDirectory;
use App\Services\MyPlacePanchayat;
use App\Services\MyPlaceSchools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyPlaceLocalDataTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $state = '29', string $district = '548', ?array $village = null): array
    {
        return ['state' => ['code' => $state, 'name' => $state === '29' ? 'Karnataka' : 'Uttar Pradesh'], 'district' => ['code' => $district, 'name' => $district === '548' ? 'Tumakuru' : 'Pilibhit'], 'village' => $village, 'pilot' => false, 'facts' => null, 'dashboard' => '/india'];
    }

    public function test_stored_school_fields_and_source_counts_are_preserved_without_naming_anonymous_records(): void
    {
        $service = app(MyPlaceSchools::class);
        $data = $service->data($this->context());
        $this->assertSame(2739, $data['totalSource']);
        $this->assertCount(49, $data['dataset']['columns']);
        $this->assertCount(12, $data['profiles']);
        $this->assertCount(6, $data['named']);
        $this->assertArrayNotHasKey('school_name', $data['profiles'][0]);
        $this->assertSame('9680936', $data['profiles'][0]['pseudocode']);
        $this->assertSame('Undefined source code: 9', $service->value('pre_primary', '9', $data['dataset'], 'en'));
        $this->assertSame('0', $service->value('avg_instr_days', '0', $data['dataset'], 'en'));
        $this->assertSame('Unavailable', $service->value('avg_instr_days', '', $data['dataset'], 'en'));
    }

    public function test_school_filters_do_not_cross_districts_or_turn_missing_villages_into_district_matches(): void
    {
        $service = app(MyPlaceSchools::class);
        $other = $service->data($this->context('9', '173'));
        $this->assertSame(0, $other['totalSource']);
        $this->assertSame([], $other['named']);
        $village = $this->context(village: ['code' => '612623', 'name' => 'Arepalya', 'subdistrict' => 'Kunigal']);
        $this->assertSame(0, $service->data($village)['total']);
        $this->assertSame([], $service->data($village)['named']);
        $this->assertSame(2739, $service->data($village, ['browse' => 'district'])['total']);
        $pin = $service->data($this->context(), ['pin' => '572101']);
        foreach ($pin['profiles'] as $row) {
            $this->assertSame('572101', $row['pincode']);
        }
        foreach ($pin['named'] as $row) {
            $this->assertFalse($row['pin_conflict']);
            $this->assertSame('572101', $row['pin']);
        }
        $first = $service->data($this->context());
        $next = $service->data($this->context(), ['page' => 2]);
        $this->assertSame([], array_intersect(array_column($first['profiles'], 'pseudocode'), array_column($next['profiles'], 'pseudocode')));
        $filtered = $service->data($this->context(), ['block' => 'Kunigal']);
        foreach ($filtered['profiles'] as $row) {
            $this->assertSame('Kunigal', $row['lgd_block_name']);
        }
    }

    public function test_panchayat_identity_uses_the_existing_official_village_link_and_keeps_finances_separate(): void
    {
        $service = app(MyPlacePanchayat::class);
        $jatipur = $service->data($this->context('9', '173', ['code' => '131596', 'name' => 'Jatipur']));
        $this->assertSame('82056', $jatipur['selected']['code']);
        $this->assertSame(['Lalaurikhera'], $jatipur['selected']['blocks']);
        $this->assertCount(1, $jatipur['reports']);
        $this->assertCount(25, $jatipur['reports'][0]['payments']);
        $this->assertCount(44, $jatipur['reports'][0]['activities']);
        $this->assertSame('133691975', $jatipur['reports'][0]['activities'][0]['code']);
        $this->assertNull($jatipur['reports'][0]['activities'][0]['cost']);
        $this->assertSame('250000', $jatipur['reports'][0]['activities'][23]['cost']);
        $this->assertSame('Activity Approved', $jatipur['reports'][0]['activities'][23]['status']);
        $this->assertSame(37433700, $jatipur['reports'][0]['financial_summary']['totals']['payments']['amount_paise']);
        $this->assertSame(22341400, $jatipur['reports'][0]['financial_summary']['totals']['receipts']['amount_paise']);
        $this->assertCount(5, $jatipur['reports'][0]['receipts']);
        $this->assertSame(171900, $jatipur['reports'][0]['contra'][0]['amount_paise']);
        $this->assertSame(1, $jatipur['reports'][0]['months'][1]['contra']);
        $this->assertContains('131596', array_column($jatipur['selected']['villages'], 'code'));
        $this->assertNull($service->data($this->context(village: ['code' => '612623', 'name' => 'Arepalya']))['selected']);
        $this->assertSame('Amruthur', $service->data($this->context())['selected']['name']);
        $this->assertNull($service->data($this->context('9', '173'), '220180')['selected']);
    }

    public function test_both_languages_render_six_separate_sections_and_working_record_endpoints(): void
    {
        $this->mock(MyPlaceDirectory::class)->shouldReceive('context')->andReturnUsing(fn ($state, $district, $village = null) => $this->context($state, $district, $village ? ['code' => $village, 'name' => $village === '131596' ? 'Jatipur' : 'Arepalya', 'subdistrict' => 'Kunigal'] : null));
        foreach (['en', 'hi'] as $language) {
            $activities = $this->get('/my-place/panchayat-records?state=9&district=173&village=131596&lang='.$language)->assertOk();
            $this->assertSame(44, substr_count($activities->getContent(), 'data-activity-code='));
            $activities->assertSee('133852709')->assertSee('Hand Pump Rebore')->assertSee($language === 'en' ? 'not independently verified' : 'स्वतंत्र रूप से सत्यापित नहीं');
            $page = $this->get('/my-place?state=29&district=548&lang='.$language)->assertOk();
            $this->assertSame(6, substr_count($page->getContent(), 'data-hub-panel'));
            $page->assertSee('id="gram-panchayat"', false)->assertSee('Amruthur')->assertSee('₹2,816.00')->assertSee('ಸರ್ಕಾರ ಉರ್ದು ಕಿರಿಯ ಪ್ರಾಥಮಿಕ ಶಾಲೆ- ತುಮಕೂರು');
            $this->get('/my-place/school-records?state=29&district=548&block=Kunigal&lang='.$language)->assertOk()->assertSee('name="source_village" translate="no"', false)->assertSee('value="Amruthur"', false);
            $this->get('/my-place/school-records?state=29&district=548&pin=572122&lang='.$language)->assertOk()->assertSee('572122')->assertDontSee('tumakuru-public-school-2');
            $this->get('/my-place/panchayat-records?state=9&district=173&village=131596&lang='.$language)->assertOk()->assertSee('82056')->assertSee('Lalaurikhera')->assertSee('₹14,500.00')->assertSee('₹26,019.00')->assertSee('₹374,337.00')->assertSee('₹223,414.00')->assertSee('gp-methodology')->assertSee('gp-chart-tooltip')->assertDontSee('₹2,816.00');
        }
        $this->getJson('/my-place/school-records?state=29&district=548&pin=abc')->assertUnprocessable();
        $this->get('/my-place/panchayat-records?state=9&district=173&panchayat=220180')->assertNotFound();
    }
}
