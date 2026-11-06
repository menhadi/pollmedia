<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use App\Services\VillageProfileLinks;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class VillageProfileLinksTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/profile-links-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec('CREATE TABLE shapes(file TEXT,feature_index INTEGER,lgd TEXT,state TEXT,basis TEXT); CREATE TABLE layers(file TEXT,sha256 TEXT); CREATE TABLE directory(lgd TEXT,state TEXT,census2011 TEXT)');
        $db->exec("INSERT INTO shapes VALUES ('maps/test.geojson',4,'700001','21','Existing checked identity'); INSERT INTO layers VALUES ('maps/test.geojson','abc'); INSERT INTO directory VALUES ('700001','21','405299')");
        $db->exec("ALTER TABLE directory ADD COLUMN district TEXT DEFAULT 'Nayagarh'; ALTER TABLE directory ADD COLUMN subdistrict TEXT DEFAULT 'Gania'; CREATE TABLE states(code TEXT,name TEXT); INSERT INTO states VALUES ('21','Odisha'); CREATE TABLE ancestry(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT); INSERT INTO ancestry VALUES ('700001','21','385','3022'); CREATE TABLE membership(source_row INTEGER,state TEXT,pc TEXT,ac TEXT,coverage TEXT,entity_type TEXT,entity_code TEXT); CREATE TABLE seats(state TEXT,kind TEXT,code TEXT,name TEXT); CREATE TABLE metadata(json TEXT); INSERT INTO metadata VALUES ('{\"source_url\":\"https://lgdirectory.gov.in/downloadDirectory.do\",\"checked_on\":\"2026-10-13\"}')");
        $directory = $this->directory;
        $this->app->instance(ElectoralVillageMap::class, new class($directory) extends ElectoralVillageMap
        {
            public function __construct(private string $path) {}

            public function directory(): string
            {
                return $this->path;
            }
        });
        DB::statement('CREATE TABLE census_editions(id INTEGER,source_key TEXT,status TEXT,year INTEGER,retrieved_at TEXT,source_url TEXT)');
        DB::statement('CREATE TABLE census_publications(source_key TEXT,edition_id INTEGER)');
        DB::statement('CREATE TABLE census_catalogue_rows(id INTEGER,edition_id INTEGER,state_code TEXT,district_code TEXT,level TEXT,residence TEXT,geography TEXT,`values` TEXT,flags TEXT)');
        DB::table('census_editions')->insert(['id' => 1, 'source_key' => 'existing-2011', 'status' => 'published', 'year' => 2011, 'retrieved_at' => '2026-10-13', 'source_url' => 'https://censusindia.gov.in/source']);
        DB::table('census_publications')->insert(['source_key' => 'existing-2011', 'edition_id' => 1]);
        DB::table('census_catalogue_rows')->insert(['id' => 42, 'edition_id' => 1, 'state_code' => '21', 'district_code' => '385', 'level' => 'VILLAGE', 'residence' => 'Rural', 'geography' => json_encode(['Subdistt' => '03022', 'Town/Village' => '405299']), 'values' => json_encode(['TOT_P' => 100, 'No_HH' => 20, 'P_LIT' => 60, 'P_06' => 20]), 'flags' => '[]']);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/links.sqlite');
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_existing_shape_mapping_opens_existing_profiles_and_published_data(): void
    {
        $links = app(VillageProfileLinks::class)->forShape(['feature_index' => 4], ['file' => 'maps/test.geojson', 'sha256' => 'abc'], true);
        $this->assertSame(route('electoral-villages.village', ['code' => '700001']), $links['url']);
        $this->assertSame(route('civic.place', ['record' => 42]), $links['census_url']);
        $this->assertSame(100, $links['census']['population']);
        $this->assertSame(75.0, $links['census']['literacy_rate']);
    }

    public function test_changed_geometry_and_unmapped_features_do_not_inherit_a_profile(): void
    {
        $resolver = app(VillageProfileLinks::class);
        $this->assertSame([], $resolver->forShape(['feature_index' => 4], ['file' => 'maps/test.geojson', 'sha256' => 'changed']));
        $this->assertSame([], $resolver->forShape(['feature_index' => 5], ['file' => 'maps/test.geojson', 'sha256' => 'abc']));
    }

    public function test_official_connections_preserve_partial_coverage_and_exclude_partial_parent_inference(): void
    {
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec("INSERT INTO seats VALUES ('21','pc','10','Official PC'),('21','ac','20','Official AC'),('21','ac','99','Partial parent only'); INSERT INTO membership VALUES (7,'21','10','20','Fully Covered','District','385'),(8,'21','10','20','Partly Covered','Village','700001'),(9,'21','10','99','Partly Covered','SubDistrict','3022')");
        $connections = app(ElectoralVillageMap::class)->connections('700001');
        $this->assertCount(2, $connections['constituencies']);
        $this->assertTrue($connections['constituencies'][0]['partial']);
        $this->assertSame([7, 8], $connections['constituencies'][0]['source_rows']);
        $this->assertSame('385', $connections['administration']['district_code']);
        $this->assertSame(route('constituency.overview', ['state' => 'Odisha', 'kind' => 'pc', 'name' => 'Official PC']), $connections['constituencies'][0]['url']);
        $this->assertSame('10', $connections['constituencies'][0]['code']);
        $this->assertSame('https://lgdirectory.gov.in/downloadDirectory.do', $connections['relationship_source']['url']);
    }

    public function test_official_numeric_crosswalk_restores_census_leading_zeroes(): void
    {
        DB::table('census_catalogue_rows')->update(['state_code' => '06', 'geography' => json_encode(['Subdistt' => '00123', 'Town/Village' => '059772'])]);
        $links = app(VillageProfileLinks::class)->records(['state' => '6', 'census2011' => '59772']);
        $this->assertSame(route('civic.place', ['record' => 42]), $links['census_url']);
    }

    public function test_pilibhit_reuses_the_full_profile_and_rejects_a_name_only_match(): void
    {
        DB::statement('CREATE TABLE data_sources(id INTEGER,`key` TEXT)');
        DB::statement('CREATE TABLE source_releases(id INTEGER,data_source_id INTEGER,status TEXT,payload TEXT)');
        DB::table('data_sources')->insert(['id' => 1, 'key' => 'census-pilibhit-villages-2011']);
        DB::table('source_releases')->insert(['id' => 1, 'data_source_id' => 1, 'status' => 'accepted', 'payload' => json_encode(['subdistricts' => [['code' => '00792', 'name' => 'Puranpur']], 'villages' => [['code' => '132577', 'name' => 'Haripur T.Chandpur', 'subdistrict_code' => '00792', 'population' => 645]]])]);
        $resolver = app(VillageProfileLinks::class);
        $place = ['name' => 'Haripur T.Chandpur', 'code' => '132577', 'subdistrict' => 'Puranpur', 'feature_index' => 0];
        $layer = ['state' => 'Uttar Pradesh', 'district' => 'Pilibhit', 'file' => 'maps/pilibhit-villages.geojson', 'sha256' => 'abc'];
        $links = $resolver->forShape($place, $layer, true);
        $this->assertSame(route('villages.show', ['code' => '132577', 'slug' => 'haripur-tchandpur']), $links['url']);
        $this->assertSame(645, $links['census']['population']);
        $this->assertSame([], $resolver->forShape(array_replace($place, ['code' => '999999']), $layer));
        $this->assertSame([], $resolver->forShape(array_replace($place, ['subdistrict' => 'Different']), $layer));
    }

    public function test_unpublished_and_conflicting_census_identities_are_not_linked(): void
    {
        $resolver = app(VillageProfileLinks::class);
        DB::table('census_editions')->update(['status' => 'withdrawn']);
        $this->assertSame([], $resolver->records(['state' => '21', 'census2011' => '405299']));
        DB::table('census_editions')->update(['status' => 'published']);
        $row = (array) DB::table('census_catalogue_rows')->first();
        DB::table('census_catalogue_rows')->insert(array_replace($row, ['id' => 43, 'district_code' => '999']));
        $this->assertSame([], $resolver->records(['state' => '21', 'census2011' => '405299']));
    }
}
