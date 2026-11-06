<?php

namespace Tests\Feature;

use App\Services\IssueLocalityResolver;
use App\Services\IssueLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssueLocalityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_kengeri_coordinates_resolve_without_a_village_link(): void
    {
        $l = app(IssueLocationResolver::class)->resolve(12.9177, 77.4838)[0];
        $this->assertSame('Kengeri', $l['name']);
        $s = collect($l['scopes'])->keyBy('kind');
        $this->assertSame('Karnataka', $s['state']['name']);
        $this->assertSame('district:29:525', $s['district']['key']);
        $this->assertArrayHasKey('ac', $s->all());
        $this->assertArrayHasKey('pc', $s->all());
        $this->assertArrayNotHasKey('village', $s->all());
        $this->assertStringContainsString('2022', implode(' ', $l['notes']));
    }

    public function test_uncovered_coordinates_remain_reportable(): void
    {
        $l = app(IssueLocalityResolver::class)->resolve(0, 0);
        $this->assertSame('Detected location', $l['name']);
        $this->assertCount(1, $l['scopes']);
        $this->assertNotSame($l['identity_key'], app(IssueLocalityResolver::class)->resolve(0, 1)['identity_key']);
    }

    public function test_locality_report_publishes_to_its_area_feeds_without_a_village(): void
    {
        $location = app(IssueLocalityResolver::class)->resolve(12.9177, 77.4838);
        $this->mock(IssueLocationResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->once()->andReturn([$location]));
        $token = $this->postJson('/issues/location', ['latitude' => 12.9177, 'longitude' => 77.4838])->assertOk()->json('choices.0.token');
        $this->post('/issues/publish', ['location_token' => $token, 'location_confirmed' => 1, 'category' => 'roads', 'title' => 'Repair the road', 'description' => 'Road damage in Kengeri', 'consent' => 1])->assertRedirect();
        $this->assertDatabaseCount('citizen_issues', 1);
        foreach ($location['scopes'] as $scope) {
            $this->get('/issues?area='.urlencode($scope['key']))->assertOk()->assertSee('Repair the road');
        }
        $this->assertSame(0, DB::table('citizen_issue_scopes')->where('kind', 'village')->count());
    }
}
