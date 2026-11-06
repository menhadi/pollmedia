<?php

namespace Tests\Feature;

use App\Services\CensusProfileSummary;
use App\Services\ElectionGeographySummary;
use App\Services\HomeElectionSummary;
use App\Services\IndiaVillageMap;
use App\Services\SeoPages;
use App\Services\VillageProfileLinks;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class IndiaVillageMapTest extends TestCase
{
    private string $directory;

    private string $geometry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(ElectionGeographySummary::class)->shouldReceive('states')->andReturn(collect());
        $this->mock(SeoPages::class)->shouldReceive('current')->andReturn(null);
        $this->mock(CensusProfileSummary::class)->shouldReceive('landing')->andReturn(['series' => ['rows' => []], 'places' => []]);

        $this->directory = sys_get_temp_dir().'/india-map-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->geometry = public_path('maps/india-map-test-'.bin2hex(random_bytes(8)).'.geojson');
        file_put_contents($this->geometry, '{"type":"FeatureCollection","features":[]}');
        $layer = ['id' => str_repeat('b', 20), 'file' => 'maps/'.basename($this->geometry), 'sha256' => hash_file('sha256', $this->geometry), 'state' => 'Uttar Pradesh', 'district' => 'Pilibhit', 'shapes' => 2, 'source' => 'https://surveyofindia.gov.in/documents/UTTAR_PRADESH.zip', 'metadata' => []];
        file_put_contents($this->directory.'/catalogue.json', json_encode(['layers' => [$layer], 'states' => [], 'audit' => ['indexed_shapes' => 2, 'topology_validation' => 'Structural checks only', 'structural_errors' => [], 'unclassified_files' => [], 'duplicate_files' => []]]));
        $database = new PDO('sqlite:'.$this->directory.'/villages.sqlite');
        $database->exec('CREATE TABLE places(id TEXT, layer TEXT, feature_index INTEGER, source_id TEXT, name TEXT, code TEXT, subdistrict TEXT, state TEXT, district TEXT, bbox TEXT, properties TEXT)');
        $database->exec('CREATE TABLE feature_aliases(layer TEXT,feature_index INTEGER,place_id TEXT)');
        $database->exec("CREATE VIRTUAL TABLE search USING fts5(name,code,subdistrict,district,state,content='places',content_rowid='rowid')");
        $statement = $database->prepare('INSERT INTO places VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach (['a', 'c'] as $index => $character) {
            $statement->execute([str_repeat($character, 32), $layer['id'], $index, (string) $index, 'Haripur', '123456', 'Puranpur', 'Uttar Pradesh', 'Pilibhit', '[79,28,80,29]', '{"name":"Haripur","status":"ambiguous"}']);
        }
        $database->exec("INSERT INTO search(search) VALUES ('rebuild')");
        $database->prepare('INSERT INTO feature_aliases VALUES (?,?,?)')->execute([$layer['id'], 2, str_repeat('a', 32)]);
        $path = $this->directory;
        $this->app->instance(IndiaVillageMap::class, new class($path) extends IndiaVillageMap
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

    public function test_homepage_has_both_finders_and_collapsed_reference_material(): void
    {
        $this->mock(ElectionGeographySummary::class)->shouldReceive('states')->andReturn(collect());
        $empty = ['rows' => [], 'top_parties' => [], 'stats' => []];
        $this->mock(HomeElectionSummary::class)->shouldReceive('dashboard')->andReturn(['pc' => $empty, 'ac' => $empty]);

        $this->get('/')->assertOk()->assertSee('Explore India.')->assertSee('Back to India')->assertSee('Education · Literacy (%)')->assertSee('id="area-navigation"', false)->assertSee('See how India votes.')->assertSee('public-header')->assertSee('id="path-electoral"', false)->assertSee('id="path-admin"', false)->assertSee('id="admin-subdistrict"', false)->assertSee('id="village-filter"', false)->assertSee('<details id="coverage"', false)->assertDontSee('<details id="coverage" open', false);
    }

    public function test_national_map_reuses_existing_sources_for_clickable_electoral_drill_down(): void
    {
        $this->get('/india/map')->assertOk()->assertSee('<option value="literacy" selected>Education · Literacy (%)</option>', false)->assertSee('id="map-level"', false)->assertSee('id="pc-filter"', false)->assertSee('id="ac-filter"', false)->assertSee('maps/electoral/india-pc.json')->assertSee('india-electoral-catalogue')->assertSee('Ward records');
    }

    public function test_national_search_preserves_places_without_separate_source_pages(): void
    {
        $response = $this->getJson(route('india-map.search', ['q' => 'Hari', 'state' => 'Uttar Pradesh']))->assertOk()->assertJsonCount(2, 'results');
        $this->assertNotSame($response->json('results.0.id'), $response->json('results.1.id'));
        $this->assertArrayNotHasKey('url', $response->json('results.0'));
        $this->get('/india/map/places/'.str_repeat('a', 32))->assertNotFound();
        $this->getJson(route('india-map.search', ['q' => 'Haripur', 'state' => 'Kerala']))->assertOk()->assertJsonCount(0, 'results');
        $this->getJson(route('india-map.search', ['q' => '" OR *']))->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_layer_hash_prevents_clicks_using_changed_geometry(): void
    {
        $url = route('india-map.geometry', ['layer' => str_repeat('b', 20)]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/geo+json');
        file_put_contents($this->geometry, '{"changed":true}');
        $this->get($url)->assertStatus(409);
        $this->getJson(route('india-map.feature', ['layer' => str_repeat('b', 20), 'index' => 999]))->assertNotFound();
        $this->getJson(route('india-map.feature', ['layer' => str_repeat('b', 20), 'index' => 0, 'sha' => str_repeat('f', 64)]))->assertStatus(409);
        $this->get('/api/india-map/layers/../../.env')->assertNotFound();
    }

    public function test_search_selection_and_polygon_click_share_the_existing_profile(): void
    {
        $url = route('electoral-villages.village', ['code' => '123456']);
        $this->app->instance(VillageProfileLinks::class, new class($url) extends VillageProfileLinks
        {
            public function __construct(private string $url) {}

            public function forShape(array $place, array $layer, bool $withData = false, bool $allowSourceMatch = true): array
            {
                return ['url' => $this->url, 'lgd_code' => '123456'];
            }
        });
        $this->getJson(route('india-map.search', ['q' => 'Hari']))->assertOk()->assertJsonPath('results.0.url', $url);
        $this->getJson(route('india-map.detail', ['id' => str_repeat('a', 32)]))->assertOk()->assertJsonPath('url', $url);
        $this->getJson(route('india-map.feature', ['layer' => str_repeat('b', 20), 'index' => 0]))->assertOk()->assertJsonPath('url', $url);
    }

    public function test_map_and_feature_endpoints_expose_coverage_and_selection(): void
    {
        $this->get(route('india-map.index'))->assertOk()->assertSee('Explore India.')->assertSee('Back to India')->assertSee('Education · Literacy (%)')->assertSee('id="area-navigation"', false)->assertSee('not a count of current rural villages');
        $this->getJson(route('india-map.feature', ['layer' => str_repeat('b', 20), 'index' => 1]))->assertOk()->assertJsonPath('id', str_repeat('c', 32));
        $this->getJson(route('india-map.feature', ['layer' => str_repeat('b', 20), 'index' => 2]))->assertOk()->assertJsonPath('id', str_repeat('a', 32));
        $this->getJson(route('india-map.search', ['q' => 'a']))->assertUnprocessable();
        $this->getJson(route('india-map.detail', ['id' => str_repeat('f', 32)]))->assertNotFound();
    }

    public function test_district_only_coverage_opens_existing_dashboard_without_inventing_villages(): void
    {
        $catalogue = json_decode(file_get_contents($this->directory.'/catalogue.json'), true);
        $place = ['id' => str_repeat('d', 32), 'layer' => str_repeat('e', 20), 'feature_index' => 0, 'name' => 'Leh', 'kind' => 'district', 'url' => 'https://pollmedia.org/india/districts/source/ladakh/leh/dashboard'];
        $catalogue['context_layers'] = [['id' => $place['layer'], 'kind' => 'district', 'context_place' => $place]];
        file_put_contents($this->directory.'/catalogue.json', json_encode($catalogue));
        $this->getJson(route('india-map.detail', ['id' => $place['id']]))->assertOk()->assertJsonPath('kind', 'district')->assertJsonPath('url', $place['url']);
        $this->getJson(route('india-map.feature', ['layer' => $place['layer'], 'index' => 0]))->assertOk()->assertJsonPath('id', $place['id']);
        $this->getJson(route('india-map.feature', ['layer' => $place['layer'], 'index' => 1]))->assertNotFound();
        $this->getJson(route('india-map.search', ['q' => 'Leh']))->assertOk()->assertJsonCount(0, 'results');
    }
}
