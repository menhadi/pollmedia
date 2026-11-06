<?php

namespace Tests\Feature;

use App\Services\AiProviders;
use App\Services\FarmerAi;
use App\Services\FarmerGovernmentAdvisory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FarmerGovernmentAdvisoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
    }

    public function test_valid_bulletin_is_location_bound_and_expired_warnings_are_removed(): void
    {
        $text = "Agromet Advisory Bulletin for AGRA District Date : 2026-10-23\nIssued On : 2026-10-23 (Valid Till 08:30 IST of the next 5 days)\nForecast Summary: Dry weather.\nWeather Warnings (Valid Till 08:30 IST of the next day)\nOld warning text\nGeneral Advisory: Store harvested grain in dry places.";
        $service = new FarmerGovernmentAdvisory;
        $result = $service->validateBulletin($text, 'Agra', ['status' => 'unavailable']);
        $this->assertSame('available', $result['status']);
        $this->assertSame('2026-10-28T08:30:00+05:30', $result['valid_until']);
        $this->assertStringNotContainsString('Old warning', $result['text']);
        $this->assertSame('unavailable', $service->validateBulletin($text, 'Pilibhit', ['status' => 'unavailable'])['status']);
        $this->travelTo(now()->setDate(2026, 10, 29));
        $this->assertSame('unavailable', $service->validateBulletin($text, 'Agra', ['status' => 'unavailable'])['status']);
    }

    public function test_regional_bulletin_validity_is_read_without_inventing_a_period(): void
    {
        $service = new FarmerGovernmentAdvisory;
        $text = 'Agromet Advisory Bulletin for the Thanjavur District Issued on 23.10.2026 Ensemble weather forecast valid until 16.30 hrs of 28.10.2026 Agro Advisory Provide drainage.';
        $this->assertSame('available', $service->validateBulletin($text, 'Thanjavur', ['status' => 'unavailable'])['status']);
        $this->assertSame('unavailable', $service->validateBulletin(str_replace('valid until', 'unknown', $text), 'Thanjavur', ['status' => 'unavailable'])['status']);
        $this->assertSame('unavailable', $service->validateBulletin(str_replace('23.10.2026', '29.10.2026', $text), 'Thanjavur', ['status' => 'unavailable'])['status']);
    }

    public function test_district_is_resolved_only_inside_the_confirmed_state_and_empty_bulletins_stay_unavailable(): void
    {
        Http::fake([
            '*agromet_adv_ser_district_current_en.php' => Http::response('<select><option value="Uttar Pradesh">UP</option></select>'),
            '*district_current_en_get.php*' => Http::response('<select><option value="Agra">Agra</option></select>'),
            '*DistrictBulletin.php*' => Http::response('Bulletin Not available.'),
        ]);
        $location = ['scopes' => [['kind' => 'state', 'name' => 'Uttar Pradesh'], ['kind' => 'district', 'name' => 'Agra']]];
        $service = new FarmerGovernmentAdvisory;
        $result = $service->collect($location);
        $this->assertSame('unavailable', $result['status']);
        $this->assertStringContainsString('state=Uttar+Pradesh&district=Agra', $result['source_url']);
        $location['scopes'][1]['name'] = 'Pilibhit';
        $result = $service->collect($location);
        $this->assertStringContainsString('No unique district', $result['note']);
        $location['scopes'][0]['name'] = 'Madhya Pradesh';
        $result = $service->collect($location);
        $this->assertStringContainsString('No unique state', $result['note']);
    }

    public function test_government_quotes_and_translated_numbers_must_match_the_source(): void
    {
        $this->mock(AiProviders::class)->shouldReceive('settings')->with('deepseek')->andReturn(['key' => 'fake-key', 'model' => 'deepseek-flash']);
        $pairs = [['selected' => 'वर्षा 0.0 mm।', 'index' => 0], ['selected' => 'अनाज सूखे स्थान पर रखें।', 'index' => 1]];
        $response = ['title' => ['selected' => 'सरकारी मौसम और फसल सलाह', 'en' => 'Government weather and crop advisory'], 'coverage' => ['selected' => 'आगरा, उत्तर प्रदेश का जिला स्तरीय परामर्श। खेत का मापन नहीं।', 'en' => 'District-level coverage: Agra, Uttar Pradesh. Not a field measurement.'], 'items' => $pairs];
        Http::fake(['https://api.deepseek.com/*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($response)]]]])]);
        $bulletin = ['text' => 'Forecast Summary: Forecast rainfall 0.0 mm. General Advisory: Store grain in a safe dry place.', 'source_url' => 'https://imdagrimet.gov.in/Services/DistrictBulletin.php', 'issued_at' => '2026-10-23', 'valid_until' => '2026-10-28T08:30:00+05:30', 'retrieved_at' => now()->toIso8601String(), 'district' => 'Agra', 'state' => 'Uttar Pradesh'];
        $ai = new FarmerAi;
        $this->assertCount(2, $ai->government($bulletin, 'Hindi', [])['items']);
        $bulletin['text'] = 'Different bulletin text.';
        $this->expectException(\RuntimeException::class);
        $ai->government($bulletin, 'Hindi', []);
    }

    public function test_translated_temperature_sign_cannot_be_changed(): void
    {
        (new FarmerAi)->validateNumbers(['selected' => '३५ से ३६ C', 'en' => '35-36 C']);
        $this->expectException(\RuntimeException::class);
        (new FarmerAi)->validateNumbers(['selected' => 'तापमान -२ C', 'en' => 'Temperature 2 C']);
    }

    public function test_state_table_headings_are_not_translated_as_advice(): void
    {
        $this->mock(AiProviders::class)->shouldReceive('settings')->with('deepseek')->andReturn(['key' => 'fake-key', 'model' => 'deepseek-flash']);
        $text = 'Rice Maturity and Harvest paddy and make proper storage arrangements. Harvesting Wheat Sowing In present weather condition, field preparation should be started for rabi crops. Ploughing should be done properly to conserve the moisture in the soil.';
        $result = ['title' => ['selected' => 'State-level government advisory', 'en' => 'State-level government advisory'], 'coverage' => ['selected' => 'State reference only.', 'en' => 'State reference only.'], 'items' => [['index' => 1, 'selected' => 'In present weather condition, field preparation should be started for rabi crops.'], ['index' => 2, 'selected' => 'Ploughing should be done properly to conserve the moisture in the soil.']]];
        Http::fake(['https://api.deepseek.com/*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($result)]]]])]);
        $bulletin = ['text' => $text, 'state_advice_text' => $text, 'coverage_notice' => 'State reference only.', 'coverage_level' => 'state', 'reference_only' => true, 'source_url' => 'https://mausam.imd.gov.in/lucknow/mcdata/agromet.pdf', 'issued_at' => '2026-10-23', 'valid_until' => '2026-10-27T08:30:00+05:30', 'retrieved_at' => now()->toIso8601String(), 'district' => 'Pilibhit', 'state' => 'Uttar Pradesh'];
        $out = (new FarmerAi)->government($bulletin, 'English', ['crop' => 'Wheat']);
        $this->assertSame('In present weather condition, field preparation should be started for rabi crops.', $out['items'][0]['en']);
        $this->assertTrue($out['reference_only']);
        Http::assertSent(function ($request): bool {
            $payload = json_decode($request['messages'][1]['content'][0]['text'], true);

            return ! str_contains(json_encode($payload['candidates']), 'Harvesting Wheat Sowing');
        });
    }

    public function test_missing_district_uses_only_a_state_group_that_explicitly_lists_the_district(): void
    {
        $text = "UTTAR PRADESH COMPOSITE STATE AGRO-METEOROLOGICAL ADVISORY\nISSUED ON: 23.10.2026\n(Valid for the period from 23.10.2026 (0830 IST) to 27.10.2026 (0830 IST))\nNODAL OFFICER MODIPURAM: Districts: Meerut, Pilibhit, Saharanpur\nWheat Sowing In present weather condition, field preparation should be started for rabi crops. Ploughing should be done properly to conserve the moisture in the soil.\nNODAL OFFICER VARANASI: Districts: Varanasi\nRice advice for a different group.";
        Http::fake([
            '*agromet_adv_ser_district_current_en.php' => Http::response('<option value="Uttar Pradesh">UP</option>'),
            '*district_current_en_get.php*' => Http::response('<option value="Pilibhit">Pilibhit</option>'),
            '*DistrictBulletin.php*' => Http::response('Bulletin Not available.'),
            '*agromet_adv_ser_state_current.php' => Http::response('<option value="Uttar Pradesh">UP</option>'),
            '*getimageenglish_state.php*' => Http::response('<a href="http://mausam.imd.gov.in/imd_latest/contents/agromet/agromet-data/state/current/english-pdf/Uttar Pradesh.pdf">PDF</a>'),
            '*english-pdf/*' => Http::response(''),
            '*lucknow/mcdata/agromet.pdf' => Http::response('%PDF-test'),
        ]);
        $service = \Mockery::mock(FarmerGovernmentAdvisory::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('extract')->andReturn($text);
        $location = ['scopes' => [['kind' => 'state', 'name' => 'Uttar Pradesh'], ['kind' => 'district', 'name' => 'Pilibhit']]];
        $result = $service->collect($location);
        $this->assertSame('available', $result['status']);
        $this->assertSame('state', $result['coverage_level']);
        $this->assertStringContainsString('state composite bulletin', $result['coverage_notice']);
        $this->assertStringContainsString('Pilibhit', $result['state_advice_text']);
        $this->assertStringNotContainsString('different group', $result['state_advice_text']);
        $location['scopes'][1]['name'] = 'Agra';
        $this->assertSame('unavailable', $service->collect($location)['status']);
        $location['scopes'][1]['name'] = 'Pilibhit';
        $this->travelTo(now()->setDate(2026, 10, 28));
        $reference = $service->collect($location);
        $this->assertSame('reference_only', $reference['status']);
        $this->assertStringContainsString('not current weather', $reference['coverage_notice']);
        $this->travelTo(now()->setDate(2026, 10, 30));
        $this->assertSame('unavailable', $service->collect($location)['status']);
    }
}
