<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use App\Services\PilibhitMyPlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class MyPlaceTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir().'/pollmedia-my-place-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec('CREATE TABLE directory(lgd TEXT,state TEXT,name TEXT,census2011 TEXT,subdistrict TEXT); CREATE TABLE ancestry(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT)');
        $db->exec("INSERT INTO directory VALUES ('131596','9','Jatipur','131596','Pilibhit'),('131191','9','Bagnera','131191','Bisalpur'),('999999','9','Other district','999999','Other'),('888888','9','Conflicting A','888888','Pilibhit'),('888888','9','Conflicting B','888888','Pilibhit')");
        $db->exec("INSERT INTO ancestry VALUES ('131596','9','173','790'),('131191','9','173','791'),('999999','9','174','999'),('888888','9','173','790')");
        $db = null;
        $this->mock(ElectoralVillageMap::class)->shouldReceive('directory')->andReturn($this->directory);
        foreach (['census-pilibhit-villages-2011' => 'pilibhit-villages-2011.json', 'jjm-pilibhit-j1' => 'jjm-pilibhit-j1-20261013.json'] as $key => $file) {
            $payload = file_get_contents(database_path('fixtures/'.$file));
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $url = $data['landing'] ?? $data['source_url'];
            $id = DB::table('data_sources')->insertGetId(['key' => $key, 'publisher' => 'Government of India', 'url' => $url]);
            DB::table('source_releases')->insert(['data_source_id' => $id, 'version_key' => 'existing-fixture', 'url' => $url, 'status' => 'accepted', 'retrieved_at' => '2026-10-13', 'payload' => $payload]);
        }
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/links.sqlite');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_district_and_village_pages_show_existing_values_and_bilingual_services(): void
    {
        $this->get('/my-place/pilibhit?lang=en')->assertOk()->assertSee('My place · Pilibhit')->assertSee('2,031,007')->assertSee('98.19%')->assertSee('306,759')->assertSee('301,197')->assertSee('District Hospital')->assertSee('tel:+917839882618', false)->assertSee('https://www.google.com/maps/search/', false)->assertSee('https://kys.udiseplus.gov.in/home/', false)->assertSee('2018-02-23');
        $this->get('/my-place/pilibhit?village=131596&lang=en')->assertOk()->assertSee('My place · Jatipur')->assertSee('1,388')->assertSee('226')->assertSee('77.58%')->assertSee('/india/villages/uttar-pradesh/pilibhit/jatipur-131596', false);
        $this->get('/my-place/pilibhit?village=131596&lang=hi')->assertOk()->assertSee('मेरा क्षेत्र')->assertSee('तहसील')->assertSee('जिला अस्पताल')->assertSee('फोन करें')->assertSee('पद्धति, स्रोत और तारीखें')->assertSee('lang="hi"', false);
        $this->get('/my-place?lang=hi')->assertOk()->assertSee('मेरी लोकेशन इस्तेमाल करें');
    }

    public function test_unknown_conflicting_and_outside_district_identities_are_not_assigned(): void
    {
        $this->assertSame(['131191', '131596'], array_column(app(PilibhitMyPlace::class)->villages(), 'code'));
        foreach (['999999', '888888', '123'] as $code) {
            $this->get('/my-place/pilibhit?village='.$code)->assertNotFound();
        }
        $this->get('/my-place/pilibhit?subdistrict=999')->assertNotFound();
        $this->getJson('/my-place/pilibhit?village=bad')->assertUnprocessable();
    }

    public function test_unavailable_observations_are_not_shown_as_zero(): void
    {
        DB::table('source_releases')->update(['status' => 'rejected']);
        $this->get('/my-place/pilibhit?village=131596&lang=en')->assertOk()->assertSee('Unavailable')->assertDontSee('0.00%');
    }

    public function test_service_entries_have_safe_bilingual_actions_and_source_evidence(): void
    {
        $directory = app(PilibhitMyPlace::class)->services();
        $this->assertCount(12, $directory['services']);
        foreach ($directory['services'] as $service) {
            $this->assertNotEmpty($service['name']['en']);
            $this->assertNotEmpty($service['name']['hi']);
            if ($service['phone']) {
                $this->assertMatchesRegularExpression('/^[0-9]{10}$/', $service['phone']);
            }
            $source = $directory['sources'][$service['source']];
            $this->assertSame(200, $source['status']);
            $this->assertContains(parse_url($source['url'], PHP_URL_HOST), ['pilibhit.nic.in', 'kys.udiseplus.gov.in']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $source['sha256']);
        }
    }
}
