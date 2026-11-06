<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use App\Services\IssueLocalityResolver;
use App\Services\IssueLocationResolver;
use App\Services\MyPlaceDirectory;
use App\Services\WaterMapData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class MyPlaceDynamicTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(WaterMapData::class)->shouldReceive('records')->andReturn([]);
        $this->directory = sys_get_temp_dir().'/my-place-dynamic-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec('CREATE TABLE directory(lgd TEXT,state TEXT,name TEXT,census2011 TEXT,district TEXT,subdistrict TEXT); CREATE TABLE ancestry(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT); CREATE TABLE states(code TEXT,name TEXT)');
        $db->exec("INSERT INTO states VALUES ('9','Uttar Pradesh'),('8','Rajasthan'); INSERT INTO directory VALUES ('131596','9','Jatipur','131596','Pilibhit','Pilibhit'),('100','8','Village A','100','Jaipur','Tehsil A'); INSERT INTO ancestry VALUES ('131596','9','173','790'),('100','8','101','11')");
        $this->mock(ElectoralVillageMap::class)->shouldReceive('directory')->andReturn($this->directory);
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/links.sqlite');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_manual_location_updates_existing_pilibhit_information_and_rejects_wrong_parent(): void
    {
        $this->get('/my-place?lang=en')->assertOk()->assertSee('Use my location')->assertSee('Choose my place');
        $this->get('/my-place/summary?state=9&district=173&village=131596&lang=en')->assertOk()->assertSee('Jatipur')->assertSee('District Hospital')->assertSee('jatipur-131596');
        $this->get('/my-place/summary?state=8&district=101&village=131596')->assertNotFound();
        $this->get('/my-place/summary?state=8&district=101&village=100&lang=en')->assertOk()->assertSee('Village A')->assertDontSee('District Hospital')->assertSee('A local contact list is not available');
        $this->getJson('/my-place/options?state=9&district=173&level=village')->assertOk()->assertJsonPath('options.0.code', '131596');
        $this->get('/my-place?lang=hi')->assertOk()->assertSee('मेरी लोकेशन इस्तेमाल करें')->assertSee('खुद क्षेत्र चुनें');
    }

    public function test_location_is_stateless_and_imprecise_positions_do_not_assign_a_village(): void
    {
        $this->mock(IssueLocationResolver::class)->shouldReceive('resolve')->once()->with(28.5, 79.8)->andReturn([['name' => 'Jatipur', 'state' => '9', 'district' => '173', 'source' => ['lgd' => '131596'], 'scopes' => [['kind' => 'district', 'name' => 'Pilibhit']]]]);
        $this->mock(IssueLocalityResolver::class)->shouldReceive('resolve')->once()->with(28.5, 79.8)->andReturn(['name' => 'Pilibhit', 'state' => '9', 'district' => '173', 'source' => [], 'scopes' => [['kind' => 'district', 'name' => 'Pilibhit']]]);
        $this->postJson('/my-place/locate', ['latitude' => 28.5, 'longitude' => 79.8, 'accuracy' => 10])->assertOk()->assertJsonPath('choices.0.village', '131596')->assertHeader('Cache-Control', 'no-store, private')->assertSessionMissing('issue_locations');
        $this->postJson('/my-place/locate', ['latitude' => 28.5, 'longitude' => 79.8, 'accuracy' => 500])->assertOk()->assertJsonPath('choices.0.village', null)->assertJsonPath('choices.0.name', 'Pilibhit');
        $this->postJson('/my-place/locate', ['latitude' => 100, 'longitude' => 79.8, 'accuracy' => 10])->assertUnprocessable();
    }

    public function test_bookmarked_tehsil_and_village_can_reload_in_both_languages(): void
    {
        $this->get('/my-place?state=9&district=173&subdistrict=790&village=131596&lang=en')->assertOk()->assertSee('Jatipur');
        $this->get('/my-place?state=9&district=173&subdistrict=790&village=131596&lang=hi')->assertOk()->assertSee('पीलीभीत');
        $this->get('/my-place?state=9&district=173&subdistrict=unknown&lang=en')->assertOk();
        $this->getJson('/my-place?state=9&district=173&subdistrict=invalid')->assertUnprocessable();
        $this->get('/my-place?state=9&district=173&subdistrict=791&village=131596&lang=en')->assertOk()->assertSee('&quot;subdistrict&quot;:&quot;790&quot;', false)->assertDontSee('&quot;subdistrict&quot;:&quot;791&quot;', false);
    }

    public function test_district_cache_is_scoped_to_state_and_refreshes_when_the_source_changes(): void
    {
        $path = $this->directory.'/links.sqlite';
        $db = new PDO('sqlite:'.$path);
        $directory = new class extends MyPlaceDirectory
        {
            public int $reads = 0;

            public function database(): PDO
            {
                $this->reads++;

                return parent::database();
            }
        };
        $this->assertSame('173', $directory->districts('9')[0]['code']);
        $this->assertSame('173', $directory->districts('9')[0]['code']);
        $this->assertSame('101', $directory->districts('8')[0]['code']);
        $db->exec("UPDATE directory SET district='Pilibhit revised' WHERE lgd='131596'");
        touch($path, filemtime($path) + 2);
        clearstatcache(true, $path);
        $this->assertSame('Pilibhit revised', $directory->districts('9')[0]['name']);
        $this->assertSame(3, $directory->reads);
    }

    public function test_tumakuru_school_links_follow_official_district_identity_in_both_languages(): void
    {
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec("INSERT INTO states VALUES ('29','Karnataka'); INSERT INTO directory VALUES ('612623','29','Arepalya','612623','Tumakuru','Kunigal'); INSERT INTO ancestry VALUES ('612623','29','548','5541')");
        foreach (['en', 'hi'] as $language) {
            $page = $this->get('/my-place?state=29&district=548&village=612623&lang='.$language)->assertOk();
            $page->assertSee('Arepalya')->assertSee('href="https://schoolgis.udiseplus.gov.in/"', false)->assertDontSee('href="https://gis.udiseplus.gov.in/"', false)->assertSee('kys.udiseplus.gov.in')->assertSee('tumkur.nic.in/en/public-utility-category/hospitals/')->assertDontSee('District Hospital</h3>', false)->assertSee('data-hub-panel', false);
        }
        $this->get('/my-place?state=8&district=101&village=100&lang=en')->assertDontSee('tumkur.nic.in');
    }
}
