<?php

namespace Tests\Feature;

use App\Services\PostalPlaceNavigation;
use App\Services\UrbanPostalParents;
use Illuminate\Support\Facades\Route;
use PDO;
use Tests\TestCase;

class PostalPlaceNavigationTest extends TestCase
{
    private string $path;

    private PostalPlaceNavigation $navigation;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Route::has('electoral-villages.village')) {
            Route::get('/test/villages/{code}', fn () => 'Village')->name('electoral-villages.village');
        }
        if (! Route::has('map-selection.page')) {
            Route::get('/test/places/{state}/{district}/{subdistrict?}', fn () => 'Place')->name('map-selection.page');
        }
        Route::getRoutes()->refreshNameLookups();
        $this->path = tempnam(sys_get_temp_dir(), 'postal-navigation-');
        $db = new PDO('sqlite:'.$this->path);
        $db->exec("CREATE TABLE states(code TEXT,name TEXT); CREATE TABLE directory(lgd TEXT,state TEXT,name TEXT,district TEXT,subdistrict TEXT); CREATE TABLE ancestry(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT);
            CREATE TABLE seats(state TEXT,kind TEXT,code TEXT,name TEXT); CREATE TABLE membership(state TEXT,ac TEXT,pc TEXT,entity_type TEXT,entity_code TEXT,coverage TEXT);
            INSERT INTO states VALUES ('9','Uttar Pradesh');
            INSERT INTO directory VALUES ('100','9','Example Village','Pilibhit','Example Taluka');
            INSERT INTO ancestry VALUES ('100','9','173','1');
            INSERT INTO seats VALUES ('9','ac','7','Example AC'),('9','pc','3','Example PC');
            INSERT INTO membership VALUES ('9','7','3','Village','100','Fully Covered');
            INSERT INTO membership VALUES ('9','0','3','District','173','Fully Covered');");
        $this->navigation = new PostalPlaceNavigation($this->path);
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        parent::tearDown();
    }

    private function row(array $overrides = []): array
    {
        return array_replace(['kind' => 'village', 'code' => '100', 'state' => '9', 'district' => '173', 'subdistrict' => '1',
            'name' => 'Example Village', 'state_name' => 'Uttar Pradesh', 'district_name' => 'Pilibhit', 'subdistrict_name' => 'Example Taluka'], $overrides);
    }

    public function test_matching_village_has_individual_place_and_parent_links(): void
    {
        $row = $this->navigation->rows([$this->row()])[0];
        $this->assertTrue($row['current_identity_linked']);
        $this->assertSame(route('electoral-villages.village', ['code' => '100']), $row['place_url']);
        $this->assertSame(route('map-selection.page', ['state' => '9', 'district' => '173', 'subdistrict' => '1']), $row['subdistrict_url']);
        $this->assertSame(route('map-selection.page', ['state' => '9', 'district' => '173']), $row['district_url']);
        $this->assertSame(route('postal-areas.state', ['state' => '9']), $row['state_url']);
    }

    public function test_mismatch_does_not_link_to_a_different_current_place(): void
    {
        $row = $this->navigation->rows([$this->row(['district' => '999'])])[0];
        $this->assertFalse($row['current_identity_linked']);
        $this->assertSame(route('postal-areas.show', ['kind' => 'village', 'code' => '100']), $row['place_url']);
        $this->assertNull($row['district_url']);
        $this->assertNull($row['subdistrict_url']);
    }

    public function test_urban_code_does_not_inherit_a_same_numbered_village(): void
    {
        $row = $this->navigation->rows([$this->row(['kind' => 'local-body', 'district' => '', 'subdistrict' => '', 'district_name' => '', 'subdistrict_name' => ''])])[0];
        $this->assertSame(route('postal-areas.show', ['kind' => 'local-body', 'code' => '100']), $row['place_url']);
        $this->assertNull($row['district_url']);
        $this->assertNull($row['subdistrict_url']);
        $this->assertNotNull($row['state_url']);
    }

    public function test_rows_expose_assembly_parliamentary_and_panchayat_columns(): void
    {
        $row = $this->navigation->rows([$this->row()])[0];
        $this->assertSame('Example AC', $row['ac']['name']);
        $this->assertSame('Example PC', $row['pc']['name']);
        $this->assertArrayHasKey('panchayat', $row);

        $districtOnly = $this->navigation->rows([$this->row(['code' => '999', 'district' => '173'])])[0];
        $this->assertNull($districtOnly['ac']);
        $this->assertSame('Example PC', $districtOnly['pc']['name']);
    }

    public function test_state_directory_has_individual_district_links(): void
    {
        $state = $this->navigation->state('9');
        $this->assertSame('Uttar Pradesh', $state['state']['name']);
        $this->assertSame('Pilibhit', $state['districts'][0]['name']);
        $this->assertSame(1, $state['districts'][0]['villages']);
        $this->assertSame(route('map-selection.page', ['state' => '9', 'district' => '173']), $state['districts'][0]['url']);
        $this->assertNull($this->navigation->state('999'));
    }

    public function test_missing_directory_keeps_the_postal_source_page(): void
    {
        $row = (new PostalPlaceNavigation($this->path.'-missing'))->rows([$this->row()])[0];
        $this->assertFalse($row['current_identity_linked']);
        $this->assertNull($row['state_url']);
    }

    public function test_urban_parent_links_use_coverage_codes_and_use_dated_directory_pages_for_other_scopes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'urban-links-');
        try {
            $db = new PDO('sqlite:'.$path);
            $db->exec("CREATE TABLE parents(body TEXT,state TEXT,level TEXT,code TEXT,district TEXT,name TEXT,coverage TEXT);
                INSERT INTO parents VALUES ('500','9','district','173','173','Pilibhit','Partial'),
                ('500','9','subdistrict','1','173','Example Taluka','Partial'),
                ('500','9','subdistrict','2','173','New Taluka','Partial');");
            $service = new UrbanPostalParents($path);
            $this->app->instance(UrbanPostalParents::class, $service);
            $row = $this->navigation->rows([$this->row(['kind' => 'local-body', 'code' => '500'])])[0];
            $this->assertCount(2, $row['parent_links']['subdistrict']);
            $this->assertSame(route('map-selection.page', ['state' => '9', 'district' => '173', 'subdistrict' => '1']), $row['parent_links']['subdistrict'][0]['url']);
            $this->assertSame(route('postal-areas.geography', ['state' => '9', 'level' => 'subdistrict', 'code' => '2']), $row['parent_links']['subdistrict'][1]['url']);
            $this->assertSame(route('map-selection.page', ['state' => '9', 'district' => '173']), $row['parent_links']['district'][0]['url']);
            $this->assertSame(route('postal-areas.show', ['kind' => 'local-body', 'code' => '500']), $row['place_url']);
            $this->app->forgetInstance(UrbanPostalParents::class);
            unset($service, $db);
        } finally {
            unlink($path);
        }
    }
}
