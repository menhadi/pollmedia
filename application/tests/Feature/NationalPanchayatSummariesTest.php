<?php

namespace Tests\Feature;

use App\Services\GramPanchayatDirectory;
use App\Services\MyPlacePanchayat;
use App\Services\NationalPanchayatSummaries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PDO;
use Tests\TestCase;

class NationalPanchayatSummariesTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private array $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/national-summary-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $db = new PDO('sqlite:'.$this->root.'/state-9.sqlite');
        $db->exec('CREATE TABLE metadata(key TEXT PRIMARY KEY,value TEXT)');
        foreach (['schema' => '1', 'state' => '9', 'encoding' => 'zlib-json', 'financial_year' => '2026-2027'] as $key => $value) {
            $db->prepare('INSERT INTO metadata VALUES(?,?)')->execute([$key, $value]);
        }
        $db->exec('CREATE TABLE panchayats(gp TEXT PRIMARY KEY,name TEXT,native_name TEXT,native_districts TEXT,source_contexts TEXT,status TEXT,identity_match INTEGER,report_blob BLOB,captured_on TEXT)');
        for ($i = 1; $i <= 51; $i++) {
            $gp = (string) (81719 + $i);
            $report = ['name' => 'Source GP '.$i, 'financial_year' => '2026-2027', 'source_url' => 'https://egramswaraj.gov.in/FileRedirect.jsp?FD=FinancialYear2026-2027/9&name='.$gp.'.html', 'captured_on' => '2026-11-15', 'report_date' => '2026-11-14', 'monthly' => [['month' => '2026-04', 'receipts' => 0, 'payments' => 7, 'contra' => 0, 'journal' => null]], 'notes' => [], 'source_state' => 'Uttar Pradesh', 'source_district' => 'Pilibhit'];
            $db->prepare('INSERT INTO panchayats VALUES(?,?,?,?,?,?,?,?,?)')->execute([$gp, 'GP '.$i, 'GP '.$i, json_encode(['173' => 'Pilibhit']), '[]', 'available', $i === 2 ? 0 : 1, gzcompress(json_encode($report)), '2026-11-15']);
        }
        unset($db);
        $this->index = ['schema' => 1, 'publication_enabled' => true, 'financial_year' => '2026-2027', 'states' => ['9' => ['name' => 'Uttar Pradesh', 'file' => 'state-9.sqlite', 'sha256' => hash_file('sha256', $this->root.'/state-9.sqlite'), 'bytes' => filesize($this->root.'/state-9.sqlite'), 'total' => 51, 'counts' => ['available' => 51], 'captured_on' => '2026-11-15']]];
        $this->save();
        config(['services.egramswaraj.national_index_path' => $this->root.'/index.json']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function save(): void
    {
        file_put_contents($this->root.'/index.json', json_encode($this->index));
    }

    public function test_monthly_counts_dates_and_pagination_are_preserved(): void
    {
        $service = app(NationalPanchayatSummaries::class);
        $row = $service->record('9', '81720');
        $this->assertSame(7, $row['summary']['monthly'][0]['payments']);
        $this->assertSame(0, $row['summary']['monthly'][0]['receipts']);
        $this->assertNull($row['summary']['monthly'][0]['journal']);
        $this->assertSame('2026-11-14', $row['summary']['report_date']);
        $this->assertCount(50, $service->listing('9', '', 1)['items']);
        $this->assertTrue($service->listing('9', '', 1)['more']);
        $this->assertCount(1, $service->listing('9', '', 2)['items']);
        $this->assertFalse($service->listing('9', '', 2)['more']);
        $this->assertCount(50, $service->listing('9', 'Pilibhit', 1)['items']);
        $this->assertCount(1, $service->listing('9', '81720', 1)['items']);
        $this->assertSame([], $service->listing('9', 'not-a-place', 1)['items']);
        $this->get(route('panchayat-accounts.show', ['state' => '9', 'gp' => '81720']))->assertOk()->assertSee('Monthly voucher counts')->assertSee('2026-11-14');
        $this->get(route('panchayat-accounts.index', ['state' => '9']))->assertOk()->assertSee('Next');
    }

    public function test_existing_detail_rows_remain_and_only_matching_year_summaries_are_suppressed(): void
    {
        $base = $this->root.'/base.json';
        config(['services.egramswaraj.reports_path' => $base]);
        foreach (['2025-2026', '2026-2027'] as $year) {
            $report = ['name' => 'GP 1', 'panchayat_code' => '81720', 'financial_year' => $year,
                'mapping' => ['verified' => true, 'evidence' => ['official' => true], 'state_lgd' => '9', 'district_lgd' => '173', 'village_lgd_codes' => []],
                'sources' => ['monthly' => ['url' => 'https://egramswaraj.gov.in/FileRedirect.jsp?name=81720.html', 'report_date' => '2026-11-14']],
                'months' => [['month' => substr($year, 0, 4).'-04', 'receipts' => 2, 'payments' => 3, 'contra' => 0, 'journal' => 0]]];
            file_put_contents($base, json_encode(['schema' => 1, 'publication' => ['enabled' => true, 'evidence' => ['official' => true]], 'reports' => [$report]]));
            $own = app(MyPlacePanchayat::class)->profileRecords(['gp' => '81720', 'name' => 'GP 1', 'state' => '9'], ['173']);
            $this->assertSame(3, $own['reports'][0]['months'][0]['payments']);
            if ($year === '2026-2027') {
                $this->assertNull($own['national_summary']);
            } else {
                $this->assertSame(7, $own['national_summary']['summary']['monthly'][0]['payments']);
            }
        }
    }

    public function test_wrong_state_district_hash_and_paths_are_rejected(): void
    {
        $service = app(NationalPanchayatSummaries::class);
        $this->assertNull($service->record('29', '81720'));
        $this->assertNull($service->forSelection(['state' => ['code' => '9'], 'district' => ['code' => '130'], 'village' => null], '81720'));
        $original = $this->index;
        $this->index['states']['9']['file'] = '../state-9.sqlite';
        $this->save();
        $this->assertNull($service->record('9', '81720'));
        $this->index = $original;
        $this->index['states']['9']['sha256'] = str_repeat('0', 64);
        $this->save();
        $this->assertNull($service->record('9', '81720'));
        $this->index = $original;
        $this->index['publication_enabled'] = false;
        $this->save();
        $this->assertNull($service->record('9', '81720'));
    }

    public function test_unconfirmed_source_names_are_not_attached_to_village_data(): void
    {
        $this->mock(GramPanchayatDirectory::class, function ($mock) {
            $mock->shouldReceive('forVillage')->andReturn(['gp' => '81721']);
        });
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => ['code' => '131318']];
        $this->assertNull(app(NationalPanchayatSummaries::class)->forSelection($context, '81720'));
        $this->assertNull(app(NationalPanchayatSummaries::class)->forSelection($context, '81721'));
        $context['village'] = null;
        $this->assertNotNull(app(NationalPanchayatSummaries::class)->forSelection($context, '81721'));
    }
}
