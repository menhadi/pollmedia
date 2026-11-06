<?php

namespace Tests\Feature;

use App\Services\ElectoralVillageMap;
use App\Services\VillageProfileLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

class CensusHistoryLinksTest extends TestCase
{
    public function test_national_history_is_visible_only_for_matching_published_identity(): void
    {
        $originalPath = database_path();
        $directory = sys_get_temp_dir().'/national-history-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory.'/fixtures', 0755, true);
        $this->app->useDatabasePath($directory);
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE directory(lgd TEXT,state TEXT,census2011 TEXT); INSERT INTO directory VALUES ('1','33','630269')");
        $this->app->instance(ElectoralVillageMap::class, new class($db) extends ElectoralVillageMap
        {
            public function __construct(private PDO $db) {}

            public function database(): PDO
            {
                return $this->db;
            }
        });
        DB::statement('CREATE TABLE census_editions(id INTEGER,source_key TEXT,sha256 TEXT,year INTEGER,status TEXT,source_url TEXT)');
        DB::statement('CREATE TABLE census_publications(edition_id INTEGER,source_key TEXT)');
        DB::statement('CREATE TABLE census_catalogue_rows(id INTEGER,edition_id INTEGER,level TEXT,residence TEXT,state_code TEXT,district_code TEXT,geography TEXT,`values` TEXT)');
        $sha = str_repeat('b', 64);
        $identity = ['record_id' => 1, 'source_key' => 'test-2001', 'source_sha256' => $sha, 'state2011' => '33', 'code2011' => '630269', 'state2001' => '33', 'district2001' => '04', 'subdistrict2001' => '0001', 'code2001' => '00174100', 'sheet' => 'Sheet1', 'source_row' => 8];
        File::put($directory.'/fixtures/national-2001-profile-links.json', json_encode(['links' => ['1' => $identity]]));
        DB::table('census_editions')->insert(['id' => 1, 'source_key' => 'test-2001', 'sha256' => $sha, 'year' => 2001, 'status' => 'draft', 'source_url' => 'https://censusindia.gov.in/']);
        DB::table('census_publications')->insert(['edition_id' => 1, 'source_key' => 'test-2001']);
        $geo = ['SUB-DISTT' => '0001', 'TOWN_VILL' => '00174100', 'WARD' => 0, 'EB' => 0];
        DB::table('census_catalogue_rows')->insert(['id' => 1, 'edition_id' => 1, 'level' => 'VILLAGE', 'residence' => 'Rural', 'state_code' => '33', 'district_code' => '04', 'geography' => json_encode($geo), 'values' => json_encode(['TOT_P' => 100])]);
        $method = new ReflectionMethod(VillageProfileLinks::class, 'nationalHistoricalRecords');
        $resolve = fn () => $method->invoke(new VillageProfileLinks, [], '1');
        try {
            $this->assertSame([], $resolve());
            DB::table('census_editions')->update(['status' => 'published']);
            $this->assertSame(100, $resolve()['census_history'][0]['population']);
            $this->assertSame(8, $resolve()['census_history'][0]['source_row']);
            DB::table('census_catalogue_rows')->update(['geography' => json_encode(array_replace($geo, ['SUB-DISTT' => '0002']))]);
            $this->assertSame([], $resolve());
            DB::table('census_catalogue_rows')->update(['geography' => json_encode($geo)]);
            DB::table('census_editions')->update(['sha256' => str_repeat('c', 64)]);
            $this->assertSame([], $resolve());
            DB::table('census_editions')->update(['sha256' => $sha]);
            $db->exec("UPDATE directory SET census2011='999999'");
            $this->assertSame([], $resolve());
        } finally {
            $this->app->useDatabasePath($originalPath);
            File::deleteDirectory($directory);
        }
    }

    public function test_history_requires_current_identity_unique_historical_parent_and_accepted_source_version(): void
    {
        $originalDatabasePath = database_path();
        $directory = sys_get_temp_dir().'/census-history-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory.'/fixtures', 0755, true);
        $this->app->useDatabasePath($directory);
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE directory(lgd TEXT,state TEXT,census2011 TEXT); INSERT INTO directory VALUES ('1','9','131191')");
        $this->app->instance(ElectoralVillageMap::class, new class($db) extends ElectoralVillageMap
        {
            public function __construct(private PDO $db) {}

            public function database(): PDO
            {
                return $this->db;
            }
        });
        DB::statement('CREATE TABLE data_sources(id INTEGER PRIMARY KEY, key TEXT)');
        DB::statement('CREATE TABLE source_releases(id INTEGER PRIMARY KEY,data_source_id INTEGER,status TEXT,sha256 TEXT,payload TEXT)');
        $sha = str_repeat('a', 64);
        $identity = ['code2001' => '02399700', 'code2011' => '131191', 'state2001' => '09', 'district2001' => '21', 'subdistrict2001' => '0001'];
        File::put($directory.'/fixtures/pilibhit-2001-profile-links.json', json_encode(['source_release_id' => 3, 'source_sha256' => $sha, 'links' => ['1' => $identity]]));
        $record = ['code' => '02399700', 'subdistrict_code' => '0001', 'name' => 'Original name', 'population' => 120, 'households' => 30];
        $payload = ['year' => 2001, 'district_code' => '21', 'source_url' => 'https://censusindia.gov.in/', 'villages' => [$record]];
        DB::table('data_sources')->insert(['id' => 1, 'key' => 'census-pilibhit-villages-2001']);
        DB::table('source_releases')->insert(['id' => 3, 'data_source_id' => 1, 'status' => 'accepted', 'sha256' => $sha, 'payload' => json_encode($payload)]);
        $method = new ReflectionMethod(VillageProfileLinks::class, 'historicalRecords');
        $input = ['census' => ['year' => 2011, 'population' => 150]];
        $resolve = fn () => $method->invoke(new VillageProfileLinks, $input, '1');
        try {
            $linked = $resolve();
            $this->assertSame($input['census'], $linked['census']);
            $this->assertSame(120, $linked['census_history'][0]['population']);
            $this->assertSame('pending', $linked['census_history'][0]['boundary_comparison']);
            $this->assertStringContainsString('year=2001', $linked['navigation'][0]['url']);
            $db->exec("UPDATE directory SET census2011='999999'");
            $this->assertSame($input, $resolve());
            $db->exec("UPDATE directory SET census2011='131191'");
            $payload['villages'][] = $record;
            DB::table('source_releases')->where('id', 3)->update(['payload' => json_encode($payload)]);
            $this->assertSame($input, $resolve());
            $payload['villages'] = [array_replace($record, ['subdistrict_code' => '0002'])];
            DB::table('source_releases')->where('id', 3)->update(['payload' => json_encode($payload)]);
            $this->assertSame($input, $resolve());
            $payload['villages'] = [$record];
            DB::table('source_releases')->where('id', 3)->update(['payload' => json_encode($payload), 'sha256' => str_repeat('b', 64)]);
            $this->assertSame($input, $resolve());
        } finally {
            $this->app->useDatabasePath($originalDatabasePath);
            File::deleteDirectory($directory);
        }
    }
}
