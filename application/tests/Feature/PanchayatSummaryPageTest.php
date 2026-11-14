<?php

namespace Tests\Feature;

use App\Services\CensusPlaceNavigation;
use App\Services\CommunityAreaIssues;
use App\Services\ElectionGeographySummary;
use App\Services\ElectoralVillageMap;
use App\Services\IssuePostCards;
use App\Services\MyPlaceDirectory;
use App\Services\MyPlaceFacts;
use App\Services\MyPlacePanchayat;
use App\Services\PanchayatSummaryPage;
use App\Services\PlaceBreadcrumbs;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDO;
use Tests\TestCase;

class PanchayatSummaryPageTest extends TestCase
{
    private array $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['view']->share('errors', new ViewErrorBag);
        $navigation = Mockery::mock(CensusPlaceNavigation::class);
        $navigation->shouldReceive('stateDirectory')->andReturn([]);
        $this->app->instance(CensusPlaceNavigation::class, $navigation);
        $elections = Mockery::mock(ElectionGeographySummary::class);
        $elections->shouldReceive('states')->andReturn(collect());
        $this->app->instance(ElectionGeographySummary::class, $elections);
        $directory = Mockery::mock(MyPlaceDirectory::class);
        $directory->shouldReceive('states')->andReturn([['code' => '9', 'name' => 'Uttar Pradesh']]);
        $this->app->instance(MyPlaceDirectory::class, $directory);
        $members = [];
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE directory (lgd TEXT, state TEXT, name TEXT, district TEXT, subdistrict TEXT, census2011 TEXT); CREATE TABLE states (code TEXT, name TEXT); CREATE TABLE ancestry (lgd TEXT, state TEXT, district TEXT, subdistrict TEXT)');
        $db->exec("INSERT INTO states VALUES ('9', 'Uttar Pradesh')");
        $insert = $db->prepare('INSERT INTO directory VALUES (?, ?, ?, ?, ?, ?)');
        $ancestry = $db->prepare('INSERT INTO ancestry VALUES (?, ?, ?, ?)');
        for ($i = 1; $i <= 60; $i++) {
            $name = 'Member village '.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $code = (string) (100000 + $i);
            $members[] = ['village' => $code, 'name' => $name, 'state_name' => 'Uttar Pradesh', 'district' => '173', 'district_name' => 'Pilibhit', 'subdistrict' => '901', 'subdistrict_name' => 'Amaria'];
            $insert->execute([$code, '9', $name, 'Pilibhit', 'Amaria', $code]);
            $ancestry->execute([$code, '9', '173', '901']);
        }
        $this->profile = ['record' => ['state' => 9, 'gp' => 82056, 'name' => 'Test Panchayat'], 'villages' => $members];
        $map = Mockery::mock(ElectoralVillageMap::class);
        $map->shouldReceive('database')->andReturn($db);
        $this->app->instance(ElectoralVillageMap::class, $map);
        $facts = Mockery::mock(MyPlaceFacts::class);
        $facts->shouldReceive('village')->andReturn(['census_status' => 'available', 'population' => 123, 'households' => 30]);
        $this->app->instance(MyPlaceFacts::class, $facts);
        $breadcrumbs = Mockery::mock(PlaceBreadcrumbs::class);
        $breadcrumbs->shouldReceive('village')->andReturn([
            ['label' => 'India', 'url' => url('/')],
            ['label' => 'Uttar Pradesh', 'url' => url('/india/census/states/uttar-pradesh')],
            ['label' => 'Pilibhit', 'url' => url('/india/districts/uttar-pradesh/pilibhit')],
            ['label' => 'Amaria', 'url' => url('/india/census/subdistricts/uttar-pradesh/pilibhit/amaria-1')],
        ]);
        $this->app->instance(PlaceBreadcrumbs::class, $breadcrumbs);
        $records = Mockery::mock(MyPlacePanchayat::class);
        $records->shouldReceive('profileRecords')->andReturn(['payment_totals' => [], 'reports' => [], 'allocations' => []]);
        $this->app->instance(MyPlacePanchayat::class, $records);
    }

    private function page(array $query = []): View
    {
        $request = Request::create('/india/gram-panchayats/uttar-pradesh/test-panchayat-82056', 'GET', $query);
        $this->app->instance('request', $request);

        return app(PanchayatSummaryPage::class)->show($this->profile);
    }

    public function test_all_member_villages_are_accessible_through_real_pages_and_search(): void
    {
        $page = $this->page(['village_page' => 2]);
        $data = $page->getData();
        $this->assertCount(10, $data['rows']);
        $this->assertSame(60, $data['villagesPage']->total());
        $this->assertSame(['state', 'district', 'subdistrict'], array_column(array_values($data['related']), 'kind'));
        $this->assertSame('Member village 51', $data['rows'][0]['name']);
        $this->assertStringContainsString('/india/villages/uttar-pradesh/pilibhit/member-village-51-100051', $data['rows'][0]['url']);
        $this->assertSame(123, $data['rows'][0]['population']);
        $this->assertSame(30, $data['rows'][0]['households']);
        $this->assertStringContainsString('#member-villages', $data['villagesPage']->previousPageUrl());
        $html = $page->render();
        $this->assertStringContainsString('Member village 60', $html);
        $this->assertStringNotContainsString('Member village 01</a>', $html);
        $this->assertStringContainsString('Subdistrict/Taluka/Tehsil', $html);
        $this->assertStringContainsString('section=issues', $html);
        $this->assertStringNotContainsString('Panchayat LGD code', $html);
        $filtered = $this->page(['village_q' => 'village 60', 'village_page' => 999])->getData();
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame(1, $filtered['villagesPage']->currentPage());
        $this->assertStringContainsString('No matching villages.', $this->page(['village_q' => 'absent'])->render());
    }

    public function test_all_payment_years_and_original_units_are_preserved(): void
    {
        $totals = [];
        foreach (['2026-2027', '2025-2026', '2024-2025', '2023-2024'] as $year) {
            $totals[] = ['year' => $year, 'unit' => null, 'source_amount' => '12500', 'source_url' => 'https://egramswaraj.gov.in/', 'captured_on' => '2026-11-01'];
        }
        $records = Mockery::mock(MyPlacePanchayat::class);
        $records->shouldReceive('profileRecords')->andReturn(['payment_totals' => $totals, 'reports' => [], 'allocations' => []]);
        $this->app->instance(MyPlacePanchayat::class, $records);
        $html = $this->page()->render();
        $this->assertSame(4, substr_count($html, 'data-gp-year='));
        $this->assertStringContainsString('Unit unconfirmed', $html);
        $this->assertStringNotContainsString('₹12500', $html);
        $this->assertStringContainsString('id="methodology"', $html);
        $this->assertStringNotContainsString('id="methodology" open', $html);
        $this->assertStringContainsString('https://nrega.dord.gov.in/', $html);
        $this->assertStringContainsString('https://jansunwai.up.nic.in/', $html);
    }

    public function test_community_issues_use_all_member_villages_and_a_separate_page(): void
    {
        $issues = Mockery::mock(CommunityAreaIssues::class);
        $issues->shouldReceive('feed')->once()->withArgs(function (array $area): bool {
            return $area['kind'] === 'gram-panchayat' && count($area['keys']) === 60
                && in_array('village:100060', $area['keys'], true) && ! in_array('district:9:173', $area['keys'], true);
        })->andReturn(['issues' => collect(), 'total' => 0, 'authorities' => collect(), 'contacts' => collect()]);
        $this->app->instance(CommunityAreaIssues::class, $issues);
        $cards = Mockery::mock(IssuePostCards::class);
        $cards->shouldReceive('prepare')->once();
        $this->app->instance(IssuePostCards::class, $cards);
        $html = $this->page(['section' => 'issues'])->render();
        $this->assertStringContainsString('No published issues from the linked villages yet.', $html);
        $this->assertStringNotContainsString('id="member-villages"', $html);
        $this->assertStringNotContainsString('id="local-services"', $html);
    }

    public function test_invalid_village_page_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->page(['village_page' => -1]);
    }
}
