<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use App\Services\PageMarketContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class PageMarketContextTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/page-market-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $db = new PDO('sqlite:'.$this->directory.'/links.sqlite');
        $db->exec('CREATE TABLE directory(lgd TEXT,state TEXT,name TEXT,census2011 TEXT,district TEXT,subdistrict TEXT); CREATE TABLE ancestry(lgd TEXT,state TEXT,district TEXT,subdistrict TEXT); CREATE TABLE states(code TEXT,name TEXT)');
        $db->exec("INSERT INTO states VALUES ('9','Uttar Pradesh'); INSERT INTO directory VALUES ('131596','9','Jatipur','131596','Pilibhit','790'),('200','9','Second','000200','Bareilly','791'),('301','9','Conflict A','000300','Pilibhit','790'),('302','9','Conflict B','000300','Bareilly','791'); INSERT INTO ancestry VALUES ('131596','9','173','790'),('200','9','150','791'),('301','9','173','790'),('302','9','150','791')");
        unset($db);
        $this->mock(ElectoralVillageMap::class)->shouldReceive('directory')->andReturn($this->directory);
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/links.sqlite');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_exact_village_ancestry_is_required_and_wrong_state_stays_unavailable(): void
    {
        $service = app(PageMarketContext::class);
        $result = $service->lgdVillages(['131596']);
        $this->assertSame('9:173', $result['options'][0]['key']);
        $this->assertSame('Pilibhit', $result['options'][0]['district_name']);
        $this->assertSame([], $service->lgdVillages(['131596'], '8')['options']);
        $this->assertSame([], $service->lgdVillages(['999999'])['options']);
        $this->assertSame([], $service->censusVillage(2001, '131596')['options']);
    }

    public function test_conflicting_crosswalks_are_excluded_and_split_districts_remain_separate(): void
    {
        $result = app(PageMarketContext::class)->censusCodes(['131596', '200', '000300', '999999']);
        $this->assertCount(2, $result['options']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(2, $result['unlinked']);
        $this->assertSame(['9:150', '9:173'], array_column($result['options'], 'key'));
        $this->assertSame([], app(PageMarketContext::class)->censusCodes(['300'])['options']);
    }

    public function test_bilingual_panel_uses_existing_endpoints_without_fetching_before_expansion(): void
    {
        Http::preventStrayRequests();
        $scope = app(PageMarketContext::class)->censusCodes(['131596', '200']);
        foreach (['en' => 'Mandi prices &amp; trends', 'hi' => 'मंडी भाव और रुझान'] as $language => $title) {
            request()->query->set('lang', $language);
            $html = view('place-market-panel', ['marketPageScope' => $scope])->render();
            $this->assertStringContainsString($title, $html);
            $this->assertStringContainsString('<option value="">', $html);
            $this->assertStringContainsString('/my-place/markets', $html);
            $this->assertStringContainsString('/my-place/market-history', $html);
            $this->assertStringContainsString('data-market-page-mount></div>', $html);
            $this->assertStringNotContainsString('<details open', $html);
        }
        Http::assertNothingSent();
    }

    public function test_district_uses_only_published_2011_village_correspondences(): void
    {
        Schema::create('census_editions', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->integer('year');
            $table->timestamps();
        });
        Schema::create('census_catalogue_rows', function (Blueprint $table): void {
            $table->id();
            $table->integer('edition_id');
            $table->string('level');
            $table->string('state_code');
            $table->string('district_code');
            $table->json('geography');
        });
        DB::table('census_editions')->insert([
            ['id' => 1, 'status' => 'published', 'year' => 2011],
            ['id' => 2, 'status' => 'draft', 'year' => 2011],
        ]);
        foreach ([[1, '131596'], [1, '131596'], [2, '000200']] as [$edition, $code]) {
            DB::table('census_catalogue_rows')->insert(['edition_id' => $edition, 'level' => 'VILLAGE', 'state_code' => '09', 'district_code' => '151', 'geography' => json_encode(['Town/Village' => $code])]);
        }
        $result = app(PageMarketContext::class)->censusDistrict('09', '151');
        $this->assertCount(1, $result['options']);
        $this->assertSame('9:173', $result['options'][0]['key']);
        $this->assertSame(1, $result['matched']);
    }
}
