<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use PDO;
use Tests\TestCase;

class FarmerLocationOptionsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/farmer-options-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec('CREATE TABLE ancestry (lgd TEXT,state TEXT,district TEXT,subdistrict TEXT); CREATE TABLE directory (lgd TEXT,state TEXT,name TEXT,district TEXT,subdistrict TEXT); CREATE TABLE states (code TEXT,name TEXT)');
        $db->exec("INSERT INTO states VALUES ('9','Uttar Pradesh'); INSERT INTO ancestry VALUES ('100','9','173','10'),('101','9','173','11'),('102','9','173',NULL),('103','9','174',NULL),('104','9','175','10'); INSERT INTO directory VALUES ('100','9','Village A','Pilibhit','Tehsil A'),('101','9','Village B','Pilibhit','Tehsil B'),('102','9','Village C','Pilibhit',''),('103','9','Village D','Other district',''),('104','9','Village E','Third district','Tehsil A')");
        $this->mock(ElectoralVillageMap::class)->shouldReceive('directory')->andReturn($this->directory);
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/links.sqlite');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_subdistrict_choices_and_villages_stay_within_the_selected_district(): void
    {
        $this->getJson('/issues/location-options?state=9&district=173&level=subdistrict')->assertOk()->assertExactJson(['options' => [['code' => '10', 'name' => 'Tehsil A'], ['code' => '11', 'name' => 'Tehsil B'], ['code' => 'unknown', 'name' => 'Subdistrict unavailable']]]);
        $this->getJson('/issues/location-options?state=9&district=173&level=village&subdistrict=10')->assertOk()->assertJsonCount(1, 'options')->assertJsonPath('options.0.code', '100');
        $this->getJson('/issues/location-options?state=9&district=173&subdistrict=unknown')->assertOk()->assertJsonCount(1, 'options')->assertJsonPath('options.0.code', '102');
    }

    public function test_district_without_subdistricts_uses_villages_directly_and_old_callers_keep_working(): void
    {
        $this->getJson('/issues/location-options?state=9&district=174&level=subdistrict')->assertOk()->assertExactJson(['options' => []]);
        $this->getJson('/issues/location-options?state=9&district=174')->assertOk()->assertJsonCount(1, 'options')->assertJsonPath('options.0.code', '103');
        $this->getJson('/issues/location-options?state=9&district=173')->assertOk()->assertJsonCount(3, 'options');
        $this->getJson('/issues/location-options?state=9&district=174&subdistrict=10')->assertOk()->assertExactJson(['options' => []]);
    }
}
