<?php

namespace Tests\Feature;

use App\Services\GramPanchayatDirectory;
use App\Services\MyPlacePanchayat;
use App\Services\NationalPanchayatDetails;
use App\Services\NationalPanchayatSummaries;
use PDO;

class NationalPanchayatDetailsTest extends NationalPanchayatSummariesTest
{
    private string $detailsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detailsRoot = sys_get_temp_dir().'/national-details-'.bin2hex(random_bytes(6));
        mkdir($this->detailsRoot);
        config(['services.egramswaraj.national_details_path' => $this->detailsRoot]);
        file_put_contents($this->detailsRoot.'/index.json', json_encode(['schema' => 1, 'publication_enabled' => true]));
        $db = new PDO('sqlite:'.$this->detailsRoot.'/state-9.sqlite');
        $db->exec('CREATE TABLE metadata(key TEXT PRIMARY KEY,value TEXT)');
        foreach (['schema' => '1', 'state' => '9', 'encoding' => 'zlib-json', 'financial_year' => '2026-2027'] as $key => $value) {
            $db->prepare('INSERT INTO metadata VALUES(?,?)')->execute([$key, $value]);
        }
        $db->exec('CREATE TABLE records(gp TEXT,kind TEXT,record_key TEXT,label TEXT,date TEXT,amount_paise INTEGER,level TEXT,payload BLOB,source_url TEXT,source_sha256 TEXT,captured_on TEXT,PRIMARY KEY(gp,kind,record_key))');
        $db->exec('CREATE TABLE requests(id TEXT PRIMARY KEY,gp TEXT,kind TEXT,status TEXT,url TEXT,sha TEXT,captured_on TEXT,note TEXT)');
        $insert = $db->prepare('INSERT INTO records VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        for ($i = 0; $i < 53; $i++) {
            $key = (string) (100000 + $i);
            $value = ['voucher' => 'PAY-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'date' => '2026-11-15', 'amount_paise' => $i === 0 ? 0 : 123456, 'voucher_type' => 'Payment', 'notes' => [], 'detail_url' => null];
            $insert->execute(['81720', 'payments', $key, $value['voucher'], $value['date'], $value['amount_paise'], 'complete', gzcompress(json_encode($value)), 'https://egramswaraj.gov.in/voucherWiseReport.do?state=9&village=81720&finYear=2026-2027&month=11', str_repeat('a', 64), '2026-11-15']);
            $value = ['name' => 'Activity '.$i, 'status' => 'Approved', 'reported_cost' => '123.45', 'description' => 'A village road', 'original' => ['totalCost' => 123.45], 'notes' => ['Source and plan names differ.']];
            $insert->execute(['81720', 'activities', $key, $value['name'], null, null, 'complete', gzcompress(json_encode($value)), 'https://egramswaraj.gov.in/activitySectionData.do?activityCd='.$key, str_repeat('b', 64), '2026-11-15']);
        }
        $db->prepare('INSERT INTO requests VALUES(?,?,?,?,?,?,?,?)')->execute(['failed', '81720', 'activity', 'note', 'https://egramswaraj.gov.in/activitySectionData.do?activityCd=999999', null, '2026-11-15', 'Official source could not be retrieved.']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->detailsRoot.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->detailsRoot);
        parent::tearDown();
    }

    public function test_tables_use_independent_fifty_row_pagination_and_preserve_zero_and_precision(): void
    {
        $service = app(NationalPanchayatDetails::class);
        $first = $service->tables('9', '81720');
        $this->assertCount(50, $first['vouchers']['items']);
        $this->assertCount(50, $first['activities']['items']);
        $this->assertTrue($first['vouchers']['more']);
        $this->assertSame(53, $first['vouchers']['total']);
        $this->assertEquals(['gp' => '81720', 'vouchers' => 53, 'activities' => 53], $service->listingCounts('9', ['81720'])['81720']);
        $this->assertSame(0, $first['vouchers']['items'][0]['amount_paise']);
        $this->assertSame(123456, $first['vouchers']['items'][1]['amount_paise']);
        $this->assertCount(3, $service->tables('9', '81720', 2)['vouchers']['items']);
        $this->assertCount(50, $service->tables('9', '81720', 2)['activities']['items']);
        $this->assertCount(1, $first['notes']);
        $this->assertSame('123.45', $first['activities']['items'][0]['reported_cost']);
    }

    public function test_public_tables_and_activity_details_render_without_hiding_rows(): void
    {
        $this->get(route('panchayat-accounts.show', ['state' => '9', 'gp' => '81720']))->assertOk()->assertSee('Payments &amp; vouchers', false)->assertSee('PAY-000')->assertDontSee('PAY-050')->assertSee('1,234.56')->assertSee('Official source could not be retrieved.')->assertSee('voucherPage=2', false);
        $this->get(route('panchayat-accounts.show', ['state' => '9', 'gp' => '81720', 'voucherPage' => 2]))->assertOk()->assertSee('PAY-050')->assertDontSee('PAY-000');
        $this->get(route('panchayat-accounts.activity', ['state' => '9', 'gp' => '81720', 'activity' => '100000']))->assertOk()->assertSee('A village road')->assertSee('123.45')->assertSee('Source and plan names differ.');
        $this->get(route('panchayat-accounts.activity', ['state' => '9', 'gp' => '81720', 'activity' => '999999']))->assertNotFound();
    }

    public function test_wrong_scope_and_corrupt_payloads_are_never_assigned(): void
    {
        $service = app(NationalPanchayatDetails::class);
        $this->assertSame([], $service->tables('../9', '81720'));
        $this->assertSame([], $service->tables('3', '81720'));
        $this->assertNull($service->activity('9', '81721', '100000'));
        $db = new PDO('sqlite:'.$this->detailsRoot.'/state-9.sqlite');
        $db->exec("UPDATE records SET source_url='https://example.com/foreign' WHERE kind='activities'");
        $this->assertSame([], $service->tables('9', '81720')['activities']['items']);
        $db->exec("UPDATE records SET source_url='https://egramswaraj.gov.in/voucherWiseReport.do?state=3&village=81720&finYear=2026-2027' WHERE kind='payments'");
        $this->assertSame([], $service->tables('9', '81720')['vouchers']['items']);
        $db->exec("UPDATE metadata SET value='3' WHERE key='state'");
        $this->assertSame([], $service->tables('9', '81720'));
    }

    public function test_village_accounts_follow_explicit_membership_and_render_panchayat_level_records(): void
    {
        $this->mock(GramPanchayatDirectory::class, function ($mock) {
            $mock->shouldReceive('forVillage')->with(9, 131191)->andReturn(['gp' => '81720', 'name' => 'GP 1']);
            $mock->shouldReceive('forVillage')->with(9, 999999)->andReturn(null);
            $mock->shouldReceive('profile')->with(9, 81720)->andReturn(['record' => ['gp' => '81720', 'state' => '9', 'name' => 'GP 1'], 'villages' => [['village' => '131191', 'district' => '173']]]);
        });
        $this->mock(MyPlacePanchayat::class, function ($mock) {
            $mock->shouldReceive('profileRecords')->andReturn(['reports' => []]);
        });
        $this->assertNotNull(app(NationalPanchayatSummaries::class)->forVillage(9, 131191));
        $this->assertNull(app(NationalPanchayatSummaries::class)->forVillage(9, 999999));
        $html = view('national-village-panchayat', ['accountVillage' => ['state' => '9', 'lgd' => '131191']])->render();
        $this->assertStringContainsString('Gram Panchayat accounts', $html);
        $this->assertStringContainsString('PAY-000', $html);
        $this->assertStringContainsString('GP 1', $html);
    }
}
