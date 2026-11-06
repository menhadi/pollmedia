<?php

namespace Tests\Feature;

use App\Services\IssueAuthorities;
use App\Services\MyPlaceServices;
use Tests\TestCase;

class MyPlaceServicesTest extends TestCase
{
    private function context(string $state = '29', string $district = '548', ?string $village = '612623'): array
    {
        return ['state' => ['code' => $state, 'name' => 'Karnataka'], 'district' => ['code' => $district, 'name' => 'Tumakuru'], 'village' => $village ? ['code' => $village, 'name' => 'Arepalya'] : null, 'pilot' => $state === '9' && $district === '173'];
    }

    public function test_stored_tumakuru_contact_is_scoped_and_general_routing_is_not_a_health_facility(): void
    {
        $records = app(MyPlaceServices::class)->directory($this->context());
        $this->assertCount(1, $records);
        $this->assertSame('Deputy Commissioner & District Magistrate — Tumakuru', $records[0]['name']['en']);
        $this->assertSame(['other'], $records[0]['categories']);
        $this->assertSame('स्थानीय प्रशासन संपर्क', $records[0]['name']['hi']);
        $this->assertSame('tel:+918162278718', $records[0]['phone_href']);
        $this->assertSame('district', $records[0]['scope']);
        $this->assertSame([], app(MyPlaceServices::class)->directory($this->context('9', '548', null)));
    }

    public function test_pilibhit_retains_facilities_and_does_not_duplicate_pilot_phone_and_source(): void
    {
        $records = app(MyPlaceServices::class)->directory($this->context('9', '173', '131596'));
        $this->assertCount(5, array_filter($records, fn ($r) => $r['kind'] === 'facility'));
        $this->assertCount(1, array_filter($records, fn ($r) => $r['phone'] === '7839882618'));
        $this->assertContains('District Hospital', array_column(array_column($records, 'name'), 'en'));
    }

    public function test_missing_and_invalid_contact_methods_remain_unavailable_and_templates_escape_names(): void
    {
        $this->mock(IssueAuthorities::class)->shouldReceive('contactsForKeys')->once()->with(['district:29:548'])->andReturn(collect([
            ['office' => '<script>Unsafe</script>', 'categories' => ['water'], 'phone' => null, 'phone_href' => null, 'email' => 'not-an-email', 'checked_on' => '2026-10-26'],
        ]));
        $context = $this->context(village: null);
        $records = app(MyPlaceServices::class)->directory($context);
        $this->assertNull($records[0]['phone_href']);
        $this->assertNull($records[0]['email']);
        foreach (['en', 'hi'] as $language) {
            $html = view('my-place-service-directory', ['hub' => ['service_contacts' => $records], 'context' => $context, 'language' => $language, 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
            $this->assertStringNotContainsString('<script>Unsafe</script>', $html);
            $this->assertStringNotContainsString('href="tel:', $html);
            $this->assertStringContainsString($language === 'hi' ? 'फोन सूची में नहीं है' : 'Phone not listed', $html);
        }
    }

    public function test_empty_directory_has_an_explicit_gap_in_both_languages(): void
    {
        foreach (['en', 'hi'] as $language) {
            $html = view('my-place-service-directory', ['hub' => ['service_contacts' => []], 'context' => $this->context(), 'language' => $language, 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
            $this->assertStringContainsString($language === 'hi' ? 'सेवा संपर्क अभी उपलब्ध नहीं हैं' : 'A local contact list is not available', $html);
            $this->assertStringNotContainsString('data-service-card', $html);
        }
    }
}
