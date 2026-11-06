<?php

namespace Tests\Feature;

use App\Services\UrbanCensusLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDO;
use Tests\TestCase;

class UrbanCensusLinksTest extends TestCase
{
    private string $path;

    private UrbanCensusLinks $links;

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/test/census/{record}', fn () => 'Census')->name('civic.place');
        Route::getRoutes()->refreshNameLookups();
        DB::statement('CREATE TABLE census_catalogue_rows(id INTEGER,edition_id INTEGER,name TEXT,level TEXT,"values" TEXT,geography TEXT,state_code TEXT)');
        DB::statement('CREATE TABLE census_editions(id INTEGER,year INTEGER,status TEXT,source_key TEXT,source_url TEXT)');
        DB::statement('CREATE TABLE census_publications(edition_id INTEGER,source_key TEXT)');
        DB::table('census_editions')->insert(['id' => 1, 'year' => 2011, 'status' => 'published', 'source_key' => 'official', 'source_url' => 'https://censusindia.gov.in/']);
        DB::table('census_publications')->insert(['edition_id' => 1, 'source_key' => 'official']);
        DB::table('census_catalogue_rows')->insert(['id' => 10, 'edition_id' => 1, 'name' => 'BBMP WARD 159', 'level' => 'WARD', 'values' => json_encode(['TOT_P' => 40771, 'P_06' => 4281, 'P_LIT' => 33011, 'No_HH' => 10168])]);
        $this->path = tempnam(sys_get_temp_dir(), 'urban-census-test');
        $db = new PDO('sqlite:'.$this->path);
        $db->exec("CREATE TABLE links(kind TEXT,code TEXT,state TEXT,record TEXT,basis TEXT,label TEXT,source TEXT);
            INSERT INTO links VALUES ('village','938441','29','10','named-ward','Kengeri','https://site.bbmp.gov.in/'),
            ('village','2','29','10','coverage-only','Related municipality','https://lgdirectory.gov.in/'),
            ('local-body','3','29','10','lgd-town-code','Urban body','https://lgdirectory.gov.in/');");
        $this->links = new UrbanCensusLinks($this->path);
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        parent::tearDown();
    }

    public function test_verified_ward_provides_2011_figures_and_link(): void
    {
        $r = $this->links->overview(['lgd' => '938441', 'state' => '29']);
        $this->assertSame(40771, $r['figures']['population']);
        $this->assertSame(10168, $r['figures']['households']);
        $this->assertSame(90.47, $r['figures']['literacy_rate']);
        $this->assertSame(route('civic.place', ['record' => 10]), $r['url']);
        $this->assertSame('named-ward', $r['basis']);
    }

    public function test_no_name_or_namespace_fallback_and_no_overwrite_of_village_population(): void
    {
        $this->assertSame([], $this->links->forPlace('village', '613025', '29'));
        $this->assertSame([], $this->links->forPlace('local-body', '938441', '29'));
        $this->assertSame([], $this->links->forPlace('village', '938441', '9'));
        $this->assertNull($this->links->overview(['lgd' => '938441', 'state' => '29', 'census' => ['population' => 50]]));
    }

    public function test_coverage_only_never_supplies_village_overview_totals(): void
    {
        $this->assertCount(1, $this->links->forPlace('village', '2', '29'));
        $this->assertNull($this->links->overview(['lgd' => '2', 'state' => '29']));
    }

    public function test_withdrawn_or_unpublished_records_are_not_shown(): void
    {
        DB::table('census_publications')->delete();
        $this->assertSame([], $this->links->forPlace('village', '938441', '29'));
        DB::table('census_publications')->insert(['edition_id' => 1, 'source_key' => 'official']);
        DB::table('census_editions')->update(['status' => 'draft']);
        $this->assertSame([], $this->links->forPlace('village', '938441', '29'));
    }

    public function test_multiple_scopes_remain_separate_and_do_not_become_one_overview(): void
    {
        DB::table('census_catalogue_rows')->insert(['id' => 11, 'edition_id' => 1, 'name' => 'Example (M + OG)', 'level' => 'TOWN', 'state_code' => '28', 'geography' => json_encode(['Town/Village' => '800001', 'Subdistt' => '00100']), 'values' => json_encode(['TOT_P' => 50000])]);
        $db = new PDO('sqlite:'.$this->path);
        $db->exec("INSERT INTO links VALUES ('village','938441','29','11','lgd-town-scopes','Example','https://lgdirectory.gov.in/'), ('local-body','4','36','11','lgd-town-scopes','Example','https://lgdirectory.gov.in/')");
        $this->assertCount(2, $this->links->forPlace('village', '938441', '29'));
        $this->assertNull($this->links->overview(['lgd' => '938441', 'state' => '29']));
        $record = $this->links->forPlace('local-body', '4', '36')[0];
        $this->assertSame('800001', $record['geography']['Town/Village']);
        $this->assertStringContainsString('Andhra Pradesh', $record['historical_state_note']);
    }

    public function test_ladakh_reference_preserves_2011_jammu_kashmir_edition(): void
    {
        DB::table('census_catalogue_rows')->where('id', 10)->update(['state_code' => '01', 'level' => 'TOWN']);
        $db = new PDO('sqlite:'.$this->path);
        $db->exec("INSERT INTO links VALUES ('local-body','248177','37','10','historical-ut-code','Leh','https://ldma.ladakh.gov.in/aboutleh.html')");
        $record = $this->links->forPlace('local-body', '248177', '37')[0];
        $this->assertStringContainsString('Jammu and Kashmir in Census 2011', $record['historical_state_note']);
        $this->assertSame('https://ldma.ladakh.gov.in/aboutleh.html', $record['historical_state_source']);
    }
}
