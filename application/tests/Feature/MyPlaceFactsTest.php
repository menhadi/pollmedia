<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use App\Services\MyPlaceFacts;
use App\Services\WaterMapData;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class MyPlaceFactsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/my-place-facts-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec("CREATE TABLE directory(lgd TEXT,state TEXT,census2011 TEXT); INSERT INTO directory VALUES ('612623','29','612623')");
        $this->mock(ElectoralVillageMap::class)->shouldReceive('directory')->andReturn($this->directory);
        $this->mock(WaterMapData::class)->shouldReceive('records')->with([['feature_index' => 0, 'state' => '29', 'lgd' => '612623']])->andReturn([0 => ['households' => 75, 'tap_connections' => 61, 'coverage_pct' => 81.33, 'source_url' => 'https://ejalshakti.gov.in/source', 'retrieved_at' => '2026-10-13']]);
        DB::statement('CREATE TABLE census_editions(id INTEGER,source_key TEXT,status TEXT,year INTEGER,source_url TEXT)');
        DB::statement('CREATE TABLE census_publications(source_key TEXT,edition_id INTEGER)');
        DB::statement('CREATE TABLE census_catalogue_rows(id INTEGER,edition_id INTEGER,name TEXT,state_code TEXT,district_code TEXT,level TEXT,residence TEXT,geography TEXT,`values` TEXT)');
        DB::table('census_editions')->insert(['id' => 1, 'source_key' => 'test-pca', 'status' => 'published', 'year' => 2011, 'source_url' => 'https://censusindia.gov.in/source']);
        DB::table('census_publications')->insert(['edition_id' => 1, 'source_key' => 'test-pca']);
        DB::table('census_catalogue_rows')->insert(['id' => 1, 'edition_id' => 1, 'name' => 'Arepalya', 'state_code' => '29', 'district_code' => '571', 'level' => 'VILLAGE', 'residence' => 'Rural', 'geography' => json_encode(['Subdistt' => '05541', 'Town/Village' => '612623']), 'values' => json_encode(['TOT_P' => 285, 'No_HH' => 60, 'P_LIT' => 167, 'P_06' => 30])]);
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/links.sqlite');
        rmdir($this->directory);
        parent::tearDown();
    }

    private function facts(string $code = '612623'): array
    {
        return app(MyPlaceFacts::class)->village(['code' => '612623', 'census2011' => $code], '29');
    }

    public function test_national_village_facts_preserve_separate_census_and_water_values(): void
    {
        $facts = $this->facts();
        $this->assertSame(285.0, $facts['population']);
        $this->assertSame(60.0, $facts['households']);
        $this->assertSame(65.49, $facts['literacy']);
        $this->assertSame(75, $facts['water']['households']);
        $this->assertSame(61, $facts['water']['tap_connections']);
        $this->assertSame('available', $facts['census_status']);
    }

    public function test_unpublished_rows_are_not_used_and_water_remains_available(): void
    {
        DB::table('census_publications')->delete();
        $facts = $this->facts();
        $this->assertNull($facts['population']);
        $this->assertSame('no_record', $facts['census_status']);
        $this->assertSame(81.33, $facts['water']['coverage_pct']);
    }

    public function test_conflicting_values_remain_unavailable_without_losing_agreed_fields(): void
    {
        $row = (array) DB::table('census_catalogue_rows')->first();
        $row['id'] = 2;
        $row['values'] = json_encode(['TOT_P' => 500, 'No_HH' => 60, 'P_LIT' => 167, 'P_06' => 30]);
        DB::table('census_catalogue_rows')->insert($row);
        $facts = $this->facts();
        $this->assertNull($facts['population']);
        $this->assertNull($facts['literacy']);
        $this->assertSame(60.0, $facts['households']);
        $this->assertSame('conflicting_values', $facts['census_status']);
    }

    public function test_ambiguous_official_crosswalk_does_not_assign_another_village(): void
    {
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec("INSERT INTO directory VALUES ('900000','29','612623')");
        $facts = $this->facts();
        $this->assertNull($facts['population']);
        $this->assertSame('conflicting_identity', $facts['census_status']);
    }

    public function test_missing_identity_does_not_become_zero(): void
    {
        $facts = $this->facts('');
        $this->assertNull($facts['population']);
        $this->assertSame('no_identity', $facts['census_status']);
    }
}
