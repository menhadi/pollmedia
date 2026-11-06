<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationDashboardSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_district_search_uses_one_current_dashboard_destination(): void
    {
        $this->seed(PilibhitSeeder::class);
        $rows = $this->getJson('/search?q=Pilibhit&kind=district')->assertOk()->json('suggestions');
        $this->assertCount(1, $rows);
        $this->assertSame('District', $rows[0]['type']);
        $this->assertStringEndsWith('/india/districts/uttar-pradesh/pilibhit', $rows[0]['url']);
    }

    public function test_national_directory_district_can_be_found_without_legacy_place_registry_row(): void
    {
        $rows = $this->getJson('/search?q=Thanjavur&kind=district')->assertOk()->json('suggestions');
        $this->assertCount(1, $rows);
        $this->assertStringEndsWith('/india/districts/tamil-nadu/thanjavur', $rows[0]['url']);
    }
}
