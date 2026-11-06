<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class ElectoralVillageMapTest extends TestCase
{
    public function test_display_links_are_additive_and_invalidated_when_original_lgd_data_changes(): void
    {
        $original = $this->directory.'/links.sqlite';
        $path = $this->directory.'/display-links.sqlite';
        $db = new PDO('sqlite:'.$path);
        $db->exec('CREATE TABLE shapes(file TEXT,feature_index INTEGER,lgd TEXT,state TEXT,basis TEXT,sha256 TEXT); CREATE TABLE metadata(json TEXT)');
        $db->prepare('INSERT INTO metadata VALUES (?)')->execute([json_encode(['original_links_sha256' => hash_file('sha256', $original)])]);
        $db->prepare('INSERT INTO shapes VALUES (?,?,?,?,?,?)')->execute(['maps/'.basename($this->geometry), 5, '1', '9', 'Display crosswalk', hash_file('sha256', $this->geometry)]);
        try {
            $service = app(ElectoralVillageMap::class);
            $this->assertSame(4, (int) $service->database()->query('SELECT count(*) FROM shapes')->fetchColumn());
            $this->assertSame(3, (int) (new PDO('sqlite:'.$original))->query('SELECT count(*) FROM shapes')->fetchColumn());
            $db->exec('DELETE FROM metadata');
            $db->prepare('INSERT INTO metadata VALUES (?)')->execute([json_encode(['original_links_sha256' => str_repeat('0', 64)])]);
            $this->assertSame(3, (int) $service->database()->query('SELECT count(*) FROM shapes')->fetchColumn());
        } finally {
            $db = null;
            @unlink($path);
        }
    }

    public function test_additional_layers_keep_original_registry_and_reject_conflicting_geometry_hashes(): void
    {
        $original = $this->directory.'/links.sqlite';
        $path = $this->directory.'/display-links.sqlite';
        $extra = str_replace('.geojson', '-additional.geojson', $this->geometry);
        file_put_contents($extra, file_get_contents($this->geometry));
        $file = 'maps/'.basename($extra);
        $sha = hash_file('sha256', $extra);
        $db = new PDO('sqlite:'.$path);
        $db->exec('CREATE TABLE shapes(file TEXT,feature_index INTEGER,lgd TEXT,state TEXT,basis TEXT,sha256 TEXT); CREATE TABLE layers(file TEXT,sha256 TEXT,source TEXT,edition TEXT); CREATE TABLE metadata(json TEXT)');
        $db->prepare('INSERT INTO metadata VALUES (?)')->execute([json_encode(['original_links_sha256' => hash_file('sha256', $original)])]);
        $db->prepare('INSERT INTO layers VALUES (?,?,?,?)')->execute([$file, $sha, 'Official existing source', 'Unverified']);
        $db->prepare('INSERT INTO shapes VALUES (?,?,?,?,?,?)')->execute([$file, 0, '1', '9', 'Explicit Census to LGD crosswalk', $sha]);
        try {
            $service = app(ElectoralVillageMap::class);
            $this->assertSame(2, (int) $service->database()->query('SELECT count(*) FROM layers')->fetchColumn());
            $this->assertSame(1, (int) (new PDO('sqlite:'.$original))->query('SELECT count(*) FROM layers')->fetchColumn());
            $this->assertSame(4, (int) $service->database()->query('SELECT count(*) FROM shapes')->fetchColumn());
            $this->get('/api/electoral-villages/layers/'.hash('sha256', $file))->assertOk();
            $db->exec("UPDATE layers SET sha256='changed'");
            $this->assertSame(3, (int) $service->database()->query('SELECT count(*) FROM shapes')->fetchColumn());
            $this->get('/api/electoral-villages/layers/'.hash('sha256', $file))->assertStatus(409);
        } finally {
            $db = null;
            File::delete($extra, $path);
        }
    }

    private string $directory;

    private string $geometry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/electoral-villages-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->geometry = public_path('maps/electoral-villages-test-'.bin2hex(random_bytes(8)).'.geojson');
        file_put_contents($this->geometry, '{"type":"FeatureCollection","features":[]}');
        $file = 'maps/'.basename($this->geometry);
        $database = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $database->exec('CREATE TABLE states(code TEXT,name TEXT); CREATE TABLE seats(state TEXT,kind TEXT,code TEXT,name TEXT); CREATE TABLE membership(source_row INTEGER,state TEXT,pc TEXT,ac TEXT,entity_type TEXT,entity_code TEXT,entity_name TEXT,coverage TEXT); CREATE TABLE shapes(file TEXT,feature_index INTEGER,lgd TEXT,state TEXT,basis TEXT); CREATE TABLE layers(file TEXT,sha256 TEXT,source TEXT,edition TEXT); CREATE TABLE metadata(json TEXT)');
        $database->exec('CREATE TABLE ancestry(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT); CREATE TABLE directory(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT,name TEXT,census2011 TEXT)');
        $database->exec("INSERT INTO directory VALUES ('1','9','Pilibhit','Puranpur','Full village','123456')");
        $database->exec("INSERT INTO states VALUES ('9','Uttar Pradesh'),('29','Karnataka'); INSERT INTO seats VALUES ('9','pc','100','Pilibhit'),('9','ac','200','Pilibhit'),('29','pc','100','Different state seat')");
        $statement = $database->prepare('INSERT INTO membership VALUES (?,?,?,?,?,?,?,?)');
        foreach ([[3, '9', '100', '200', 'Village', '1', 'Full village', 'Fully Covered'], [4, '9', '100', '200', 'Village', '1', 'Full village', 'Fully Covered'], [5, '9', '100', '200', 'Village', '2', 'Partial village', 'Partly Covered'], [6, '9', '100', '200', 'Village', '2', 'Partial village', 'Fully Covered'], [7, '9', '100', '200', 'Ward', '3', 'Urban ward', 'Fully Covered']] as $row) {
            $statement->execute($row);
        }
        $database->prepare('INSERT INTO layers VALUES (?,?,?,?)')->execute([$file, hash_file('sha256', $this->geometry), 'https://surveyofindia.gov.in/documents/UTTAR_PRADESH.zip', 'Unverified']);
        $statement = $database->prepare('INSERT INTO shapes VALUES (?,?,?,?,?)');
        $statement->execute([$file, 0, '1', '9', 'Checked official code crosswalk']);
        $statement->execute([$file, 1, '2', '9', 'Checked official code crosswalk']);
        $statement->execute([$file, 2, '1', '29', 'Different state']);
        $database->prepare('INSERT INTO metadata VALUES (?)')->execute([json_encode(['source_url' => 'https://lgdirectory.gov.in/downloadDirectory.do', 'checked_on' => '2026-10-13'])]);
        $directory = $this->directory;
        $this->app->instance(ElectoralVillageMap::class, new class($directory) extends ElectoralVillageMap
        {
            public function __construct(private string $path) {}

            public function directory(): string
            {
                return $this->path;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        File::delete($this->geometry);
        parent::tearDown();
    }

    public function test_administrative_filters_follow_lgd_codes_and_keep_state_scope(): void
    {
        $database = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $database->exec("INSERT INTO ancestry VALUES ('1','9','150','792'),('8','29','150','792'); INSERT INTO directory VALUES ('8','29','Other district','Other tehsil','Other village','999998')");
        $this->getJson('/api/india-map/hierarchy?state=9')->assertOk()->assertJsonPath('options.0.code', '150')->assertJsonPath('options.0.name', 'Pilibhit')->assertJsonCount(1, 'options');
        $this->getJson('/api/india-map/hierarchy?state=9&district=150')->assertOk()->assertJsonPath('options.0.code', '792');
        $this->getJson('/api/india-map/hierarchy?state=9&district=150&subdistrict=792')->assertOk()->assertJsonPath('options.0.code', '1')->assertJsonPath('options.0.name', 'Full village')->assertJsonCount(1, 'options');
        $this->getJson('/api/india-map/hierarchy?state=9&district=999&subdistrict=792')->assertOk()->assertJsonCount(0, 'options');
        $this->getJson('/api/india-map/hierarchy?state=9&subdistrict=792')->assertUnprocessable();
        $this->getJson('/api/india-map/area?state=9&district=150&subdistrict=792')->assertOk()->assertJsonCount(1, 'layers')->assertJsonPath('layers.0.indices', [0]);
        $this->getJson('/api/india-map/area?state=9&district=999&subdistrict=792')->assertOk()->assertJsonCount(0, 'layers');
        $this->getJson('/api/electoral-villages/membership?state=9&kind=ac&code=200')->assertOk()->assertJsonCount(1, 'villages')->assertJsonPath('villages.0.lgd', '1');
    }

    public function test_membership_deduplicates_and_withholds_partial_and_other_state_shapes(): void
    {
        $this->getJson('/api/electoral-villages/membership?state=9&kind=pc&code=100')->assertOk()
            ->assertJsonPath('counts.villages', 2)->assertJsonPath('counts.partly_covered_villages', 1)
            ->assertJsonPath('counts.linked_villages', 1)->assertJsonPath('counts.linked_shapes', 1)
            ->assertJsonPath('layers.0.indices', [0])->assertJsonCount(3, 'entities');
        $this->getJson('/api/electoral-villages/membership?state=9&kind=ac&code=200')->assertOk()->assertJsonPath('counts.linked_shapes', 1);
        $this->getJson('/api/electoral-villages/membership?state=9&kind=pc&code=26')->assertNotFound();
        $this->getJson('/api/electoral-villages/membership?state=29&kind=pc&code=100')->assertOk()->assertJsonCount(0, 'layers');
    }

    public function test_national_page_and_existing_geometry_have_checked_identity(): void
    {
        $this->get('/india/elections/village-map')->assertRedirect(route('india-map.index'));
        $id = hash('sha256', 'maps/'.basename($this->geometry));
        $this->get('/api/electoral-villages/layers/'.$id)->assertOk()->assertHeader('Content-Type', 'application/geo+json');
        file_put_contents($this->geometry, '{"changed":true}');
        $this->get('/api/electoral-villages/layers/'.$id)->assertStatus(409);
        $this->getJson('/api/electoral-villages/membership?state=9&kind=village&code=1')->assertUnprocessable();
        $this->get('/india/elections/village-map/villages/1')->assertOk()->assertSee('123456')->assertSee('Official LGD village code: 1');
    }

    public function test_old_map_links_open_the_shared_india_component_with_their_selection(): void
    {
        $this->get('/india/elections/village-map?state=9&kind=pc&code=100')->assertRedirect(route('india-map.index', ['state' => '9', 'kind' => 'pc', 'code' => '100']));
        $catalogue = app(ElectoralVillageMap::class)->catalogue();
        $this->assertContains('100', array_column($catalogue['seats'], 'code'));
        $this->getJson('/api/electoral-villages/membership?state=9&kind=ac&code=200')->assertOk()->assertJsonPath('layers.0.file', 'maps/'.basename($this->geometry));
    }

    public function test_only_fully_covered_parent_memberships_expand_through_official_codes(): void
    {
        $database = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $database->exec("INSERT INTO membership VALUES (8,'9','100','200','District','50','Full district','Fully Covered'),(9,'9','100','200','SubDistrict','60','Partial subdistrict','Partly Covered'); INSERT INTO ancestry VALUES ('4','9','50','61'),('5','9','51','60')");
        $statement = $database->prepare('INSERT INTO shapes VALUES (?,?,?,?,?)');
        $statement->execute(['maps/'.basename($this->geometry), 3, '4', '9', 'Official ancestry']);
        $statement->execute(['maps/'.basename($this->geometry), 4, '5', '9', 'Partial parent excluded']);
        $this->getJson('/api/electoral-villages/membership?state=9&kind=pc&code=100')->assertOk()
            ->assertJsonPath('counts.villages', 3)->assertJsonPath('counts.linked_shapes', 2)->assertJsonPath('layers.0.indices', [0, 3]);
    }

    public function test_existing_structured_district_geometry_keeps_row_indices(): void
    {
        $previous = $this->geometry;
        $this->geometry = str_replace('.geojson', '.json', $previous);
        File::delete($previous);
        file_put_contents($this->geometry, json_encode(['rows' => [['name' => 'No shape', 'code' => '1', 'subdistrict' => 'Puranpur', 'geometry' => null], ['name' => 'Existing village', 'code' => '2', 'subdistrict' => 'Puranpur', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[79, 28], [80, 28], [80, 29], [79, 28]]]]]]]));
        $database = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $file = 'maps/'.basename($this->geometry);
        $database->prepare('UPDATE layers SET file=?,sha256=?')->execute([$file, hash_file('sha256', $this->geometry)]);
        $this->getJson('/api/electoral-villages/layers/'.hash('sha256', $file))->assertOk()
            ->assertJsonCount(2, 'features')->assertJsonPath('features.0.geometry', null)
            ->assertJsonPath('features.1.properties.name', 'Existing village');
    }
}
