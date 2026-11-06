<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use App\Services\IssueLocationResolver;
use App\Services\WaterMapData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class MyPlaceHubTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(WaterMapData::class)->shouldReceive('records')->andReturn([]);
        $this->directory = sys_get_temp_dir().'/my-place-hub-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec('CREATE TABLE directory(lgd TEXT,state TEXT,name TEXT,census2011 TEXT,district TEXT,subdistrict TEXT); CREATE TABLE ancestry(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT); CREATE TABLE states(code TEXT,name TEXT)');
        $db->exec("INSERT INTO states VALUES ('9','Uttar Pradesh'); INSERT INTO directory VALUES ('131596','9','Jatipur','131596','Pilibhit','Pilibhit'),('100','9','Another village','100','Other district','Other'); INSERT INTO ancestry VALUES ('131596','9','173','790'),('100','9','174','791')");
        $this->mock(ElectoralVillageMap::class)->shouldReceive('directory')->andReturn($this->directory);
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/links.sqlite');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_all_six_sections_and_official_tools_follow_the_selected_place(): void
    {
        $page = $this->get('/my-place?state=9&district=173&village=131596&lang=en')->assertOk();
        foreach (['local-issues', 'agri-insight', 'government-schemes', 'schools-services', 'local-markets', 'gram-panchayat'] as $id) {
            $page->assertSee('id="'.$id.'"', false);
        }
        $this->assertSame(6, substr_count($page->getContent(), 'data-hub-panel'));
        $page->assertSee('egramswaraj.gov.in')->assertSee('kys.udiseplus.gov.in')->assertSee('myScheme')->assertSee('AGMARKNET')->assertSee('data-src=', false)->assertSee('village=131596', false)->assertSee('data-hub-report', false);
        $this->get('/my-place?state=9&district=173&village=131596&lang=hi')->assertOk()->assertSee('स्थानीय समस्याएँ')->assertSee('सरकारी योजनाएँ')->assertSee('बाज़ार और भाव');
    }

    public function test_unrelated_and_unpublished_reports_are_not_listed(): void
    {
        foreach ([['Current report', 'open', 'village:131596'], ['Other place report', 'open', 'village:100'], ['Unpublished report', 'pending', 'village:131596']] as [$title,$status,$scope]) {
            $id = (string) Str::ulid();
            DB::table('citizen_issues')->insert(['id' => $id, 'title' => $title, 'description' => 'Published source text', 'category' => 'water', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('citizen_issue_scopes')->insert(['issue_id' => $id, 'area_key' => $scope, 'name' => 'Village', 'kind' => 'village']);
        }
        $this->get('/my-place/summary?state=9&district=173&village=131596&lang=en')->assertOk()->assertSee('Current report')->assertDontSee('Other place report')->assertDontSee('Unpublished report');
    }

    public function test_all_sections_remain_available_outside_the_pilibhit_pilot_in_both_languages(): void
    {
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec("INSERT INTO states VALUES ('29','Karnataka'),('24','Gujarat'); INSERT INTO directory VALUES ('612623','29','Arepalya','612623','Tumakuru','Kunigal'),('999','24','Example village','','Mahesana','Vijapur'); INSERT INTO ancestry VALUES ('612623','29','548','5541'),('999','24','471','999')");
        foreach ([['29', '548', '612623'], ['24', '471', '999']] as [$state, $district, $village]) {
            foreach (['en', 'hi'] as $language) {
                $page = $this->get('/my-place/summary?'.http_build_query(compact('state', 'district', 'village') + ['lang' => $language]))->assertOk();
                foreach (['local-issues', 'agri-insight', 'government-schemes', 'schools-services', 'local-markets', 'gram-panchayat'] as $id) {
                    $page->assertSee('id="'.$id.'"', false);
                }
                $this->assertSame(6, substr_count($page->getContent(), 'data-hub-panel'));
                $page->assertSee('village='.$village, false)->assertDontSee('village=131596', false)->assertDontSee('District Hospital</h4>', false);
            }
        }
    }

    public function test_inline_reporting_uses_confirmed_place_and_preserves_other_session_work(): void
    {
        $this->mock(IssueLocationResolver::class)->shouldReceive('village')->once()->with('131596')->andReturn(['name' => 'Jatipur', 'state' => '9', 'district' => '173', 'source' => ['lgd' => '131596'], 'scopes' => [['key' => 'village:131596', 'name' => 'Jatipur', 'kind' => 'village'], ['key' => 'district:9:173', 'name' => 'Pilibhit', 'kind' => 'district']]]);
        $this->withSession(['issue_detected_token' => 'prior-other-flow'])->postJson('/my-place/report', ['state' => '9', 'district' => '173', 'village' => '131596', 'place_confirmed' => '1', 'category' => 'water', 'title' => 'Broken local pipe', 'description' => 'Water pipe needs repair', 'consent' => '1'])->assertCreated()->assertJsonStructure(['url'])->assertSessionHas('issue_detected_token', 'prior-other-flow');
        $id = DB::table('citizen_issues')->where('title', 'Broken local pipe')->value('id');
        $this->assertDatabaseHas('citizen_issue_scopes', ['issue_id' => $id, 'area_key' => 'village:131596']);
        $snapshot = json_decode(DB::table('citizen_issue_locations')->where('issue_id', $id)->value('snapshot'), true);
        $this->assertNull($snapshot['reported_from']);
        $this->postJson('/my-place/report', ['state' => '9', 'district' => '173', 'village' => '100', 'place_confirmed' => '1'])->assertNotFound();
        $this->postJson('/my-place/report', ['state' => '9', 'district' => '173'])->assertUnprocessable();
    }

    public function test_embedded_forms_do_not_duplicate_navigation_and_agri_location_is_validated(): void
    {
        $this->get('/my-place/schemes?state=9&district=173&embed=1&lang=en')->assertOk()->assertDontSee('id="public-navigation"', false)->assertSee('my-place-embed.js');
        $this->get('/agri-insights?state=9&district=173&village=131596&embed=1&lang=en')->assertOk()->assertSee('&quot;village&quot;:&quot;131596&quot;', false)->assertSee('&quot;subdistrict&quot;:&quot;790&quot;', false)->assertDontSee('id="public-navigation"', false);
        $this->get('/agri-insights?state=9&district=173&village=100&embed=1')->assertNotFound();
        $this->get('/agri-insights')->assertOk()->assertSee('data-place-context="null"', false)->assertSee('id="public-navigation"', false);
    }
}
