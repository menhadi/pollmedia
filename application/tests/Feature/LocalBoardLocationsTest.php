<?php

namespace Tests\Feature;

use App\Http\Controllers\MyPlacePanchayatController;
use App\Services\ElectoralVillageMap;
use App\Services\GramPanchayatDirectory;
use App\Services\LocalBoardLocations;
use App\Services\MyPlaceDirectory;
use App\Services\NamedPlaceUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocalBoardLocationsTest extends TestCase
{
    private PDO $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = new PDO('sqlite::memory:');
        $this->directory->exec("CREATE TABLE states(code TEXT,name TEXT); CREATE TABLE directory(lgd TEXT,state TEXT,name TEXT,district TEXT,subdistrict TEXT,census2011 TEXT); CREATE TABLE ancestry(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT); INSERT INTO states VALUES('9','Uttar Pradesh'); INSERT INTO directory VALUES('131596','9','Jatipur','Pilibhit','Pilibhit','131596'); INSERT INTO ancestry VALUES('131596','9','173','901')");
        $directory = Mockery::mock(MyPlaceDirectory::class);
        $directory->shouldReceive('database')->andReturn($this->directory);
        $directory->shouldReceive('states')->andReturn([['code' => '9', 'name' => 'Uttar Pradesh']]);
        $directory->shouldReceive('districts')->with('9')->andReturn([['code' => '173', 'name' => 'Pilibhit']]);
        $this->app->instance(MyPlaceDirectory::class, $directory);
        $gp = Mockery::mock(GramPanchayatDirectory::class);
        $gp->shouldReceive('forVillage')->with(9, 131596)->andReturn(['gp' => '82056', 'name' => 'Jatipur Panchayat']);
        $gp->shouldReceive('profile')->with(9, 82056)->andReturn(['record' => ['state' => 9, 'gp' => 82056, 'name' => 'Jatipur Panchayat'], 'villages' => [['district' => 173, 'name' => 'Jatipur']]]);
        $this->app->instance(GramPanchayatDirectory::class, $gp);
    }

    public function test_village_parents_are_resolved_from_accepted_directory_not_client_input(): void
    {
        $area = app(LocalBoardLocations::class)->resolve('village:131596');
        $this->assertSame('Jatipur', $area['name']);
        $this->assertSame(['district:9:173', 'gp:9:82056'], array_column($area['parents'], 'key'));
        $this->assertStringContainsString('/india/villages/uttar-pradesh/pilibhit/jatipur-131596', $area['url']);
        $gp = app(LocalBoardLocations::class)->resolve('gp:9:82056');
        $this->assertSame(['district:9:173'], array_column($gp['parents'], 'key'));
    }

    public function test_duplicate_village_ancestry_is_not_silently_assigned(): void
    {
        $this->directory->exec("INSERT INTO ancestry VALUES('131596','9','174','902')");
        $this->expectException(HttpException::class);
        app(LocalBoardLocations::class)->resolve('village:131596');
    }

    public function test_unknown_village_and_invalid_namespace_are_not_accepted(): void
    {
        $this->expectException(HttpException::class);
        app(LocalBoardLocations::class)->resolve('state:9');
    }

    public function test_city_identity_is_stable_across_duplicate_census_rows_and_not_inferred_from_old_editions(): void
    {
        $locations = app(LocalBoardLocations::class);
        $row = (object) ['id' => 20, 'level' => 'TOWN', 'state_code' => '09', 'district_code' => '151', 'geography' => json_encode(['Town/Village' => '800952'])];
        $this->assertSame('city:9:151:800952', $locations->censusKey($row, 2011));
        $row->id = 21;
        $this->assertSame('city:9:151:800952', $locations->censusKey($row, 2011));
        $this->assertNull($locations->censusKey($row, 2001));
        $row->level = 'WARD';
        $this->assertNull($locations->censusKey($row, 2011));
    }

    public function test_place_options_are_scoped_to_the_chosen_district(): void
    {
        $data = app(LocalBoardLocations::class)->options('village', '9', '173', 'Jat', 1);
        $this->assertSame('village:131596', $data['items'][0]['key']);
        $this->assertSame('Jatipur · Pilibhit', $data['items'][0]['name']);
        $this->assertFalse($data['more']);
        $this->assertSame('district:9:173', app(LocalBoardLocations::class)->districtKey('Uttar Pradesh', 'Pilibhit'));
    }

    public function test_city_resolution_keeps_duplicate_published_rows_together_without_guessing_current_district(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.board_identity_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('board_identity_test');
        try {
            DB::statement('CREATE TABLE census_editions (id INTEGER, status TEXT, year INTEGER)');
            DB::statement('CREATE TABLE census_catalogue_rows (id INTEGER, edition_id INTEGER, level TEXT, state_code TEXT, district_code TEXT, name TEXT, geography TEXT)');
            DB::table('census_editions')->insert(['id' => 1, 'status' => 'published', 'year' => 2011]);
            foreach ([1, 2] as $id) {
                DB::table('census_catalogue_rows')->insert(['id' => $id, 'edition_id' => 1, 'level' => 'TOWN', 'state_code' => '09', 'district_code' => '151', 'name' => 'Pilibhit (NPP)', 'geography' => json_encode(['Town/Village' => '800877'])]);
            }
            $urls = Mockery::mock(NamedPlaceUrls::class);
            $urls->shouldReceive('census')->with(1)->andReturn(url('/test-city'));
            $this->app->instance(NamedPlaceUrls::class, $urls);
            $area = app(LocalBoardLocations::class)->resolve('city:9:151:800877');
            $this->assertSame('Pilibhit (NPP)', $area['name']);
            $this->assertSame([], $area['parents']);
            $this->assertSame('', $area['district']);
            DB::table('census_catalogue_rows')->where('id', 2)->update(['name' => 'Conflicting city']);
            $this->expectException(HttpException::class);
            app(LocalBoardLocations::class)->resolve('city:9:151:800877');
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('board_identity_test');
        }
    }

    public function test_old_panchayat_overview_shortcuts_return_to_the_main_page(): void
    {
        $url = url('/india/gram-panchayats/uttar-pradesh/jatipur-panchayat-82056');
        foreach (['overview' => 'panchayat-overview', 'villages' => 'member-villages'] as $topic => $anchor) {
            $request = Request::create($url, 'GET', ['view' => 'details', 'topic' => $topic, 'lang' => 'hi']);
            $this->app->instance('request', $request);
            $response = app(MyPlacePanchayatController::class)->profile($request, app(GramPanchayatDirectory::class), Mockery::mock(ElectoralVillageMap::class), 9, 82056);
            $this->assertSame(301, $response->getStatusCode());
            $this->assertSame($url.'?lang=hi#'.$anchor, $response->getTargetUrl());
        }
    }
}
