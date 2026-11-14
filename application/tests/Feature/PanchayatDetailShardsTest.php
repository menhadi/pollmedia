<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PanchayatDetailShardsTest extends TestCase
{
    private string $root;

    private array $report;

    private array $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 15:00:00');
        $this->root = sys_get_temp_dir().'/gp-shards-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->report = [
            'name' => 'Verified GP', 'panchayat_code' => '81720', 'financial_year' => '2026-2027',
            'mapping' => ['verified' => true, 'evidence' => ['official' => true], 'state_lgd' => '9', 'district_lgd' => '173',
                'village_lgd_codes' => ['131318'], 'village_evidence' => ['official' => true]],
            'sources' => ['monthly' => ['url' => 'https://egramswaraj.gov.in/FileRedirect.jsp?name=81720.html', 'report_date' => '2026-11-06']],
            'months' => [['month' => '2026-04', 'receipts' => 1, 'payments' => 2, 'contra' => 0, 'journal' => 0]],
        ];
        file_put_contents($this->root.'/base.json', json_encode(['schema' => 1, 'publication' => ['enabled' => true, 'evidence' => ['official' => true]], 'reports' => [$this->report]]));
        $this->report['months'][0]['payments'] = 3;
        $entry = ['panchayat_code' => '81720', 'financial_year' => '2026-2027', 'state_lgd' => '9', 'district_lgd' => '173',
            'village_lgd_codes' => ['131318'], 'file' => '9-173-81720-2026-2027.json'];
        $this->manifest = ['schema' => 1, 'publication_enabled' => true, 'reports' => [$entry]];
        $this->save();
        config(['services.egramswaraj.reports_path' => $this->root.'/base.json', 'services.egramswaraj.shard_manifest_path' => $this->root.'/manifest.json']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->root);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function save(): void
    {
        file_put_contents($this->root.'/9-173-81720-2026-2027.json', json_encode(['schema' => 1, 'report' => $this->report]));
        $this->manifest['reports'][0]['sha256'] = hash_file('sha256', $this->root.'/9-173-81720-2026-2027.json');
        file_put_contents($this->root.'/manifest.json', json_encode($this->manifest));
    }

    private function rows(?string $code = '81720', ?string $village = null, string $district = '173'): array
    {
        return app(MyPlacePanchayat::class)->reports(['state' => ['code' => '9'], 'district' => ['code' => $district],
            'village' => $village ? ['code' => $village] : null], $code);
    }

    public function test_only_selected_panchayat_or_its_verified_village_loads_its_detail_file(): void
    {
        $this->assertSame(3, $this->rows()[0]['months'][0]['payments']);
        $this->assertSame(3, $this->rows(null, '131318')[0]['months'][0]['payments']);
        $this->assertSame([], $this->rows('81721'));
        $this->assertSame([], $this->rows(null, '131191'));
        $this->assertSame([], $this->rows('81720', null, '174'));
        $this->assertSame(2, $this->rows(null)[0]['months'][0]['payments']);
    }

    public function test_missing_tampered_or_escaping_shard_keeps_existing_published_records(): void
    {
        file_put_contents($this->root.'/9-173-81720-2026-2027.json', 'tampered');
        $this->assertSame(2, $this->rows()[0]['months'][0]['payments']);
        $this->save();
        $this->manifest['reports'][0]['file'] = '../outside.json';
        file_put_contents($this->root.'/manifest.json', json_encode($this->manifest));
        $this->assertSame(2, $this->rows()[0]['months'][0]['payments']);
        unlink($this->root.'/manifest.json');
        $this->assertSame(2, $this->rows()[0]['months'][0]['payments']);
    }

    public function test_activity_description_theme_and_plan_are_bound_to_the_verified_source(): void
    {
        $this->report['sources']['activities'] = ['url' => 'https://egramswaraj.gov.in/getActivityList.do?planCd=6000001', 'report_date' => '2026-11-14'];
        $this->report['activities'] = [['code' => '123456', 'name' => 'School repair', 'focus_area' => 'Education', 'type' => 'Community Works',
            'status' => 'Activity Approved', 'output_type' => 'Asset', 'plan_type' => 'Part of Main Plan',
            'section_details' => ['description' => 'Repair school building', 'theme' => 'Child friendly village'],
            'source_evidence' => ['activity_code' => '123456', 'panchayat_code' => '81720', 'financial_year' => '2026-2027',
                'plan_id' => '6000001', 'sha256' => str_repeat('a', 64), 'retrieved_on' => '2026-11-14',
                'url' => 'https://egramswaraj.gov.in/activitySectionData.do?activityCd=123456']]];
        $this->save();
        $details = $this->rows()[0]['activities'][0]['details'];
        $this->assertSame('Repair school building', $details['description']);
        $this->assertSame('Child friendly village', $details['theme']);
        $this->assertSame('6000001', $details['plan_id']);
        $this->assertNull($details['technical_status']);
        $this->report['activities'][0]['source_evidence']['panchayat_code'] = '81721';
        $this->save();
        $this->assertSame([], $this->rows()[0]['activities']);
    }

    public function test_malformed_manifest_metadata_falls_back_without_a_page_error(): void
    {
        $original = $this->manifest;
        foreach (['panchayat_code', 'financial_year', 'state_lgd', 'district_lgd', 'sha256'] as $key) {
            $this->manifest = $original;
            $this->manifest['reports'][0][$key] = ['invalid'];
            file_put_contents($this->root.'/manifest.json', json_encode($this->manifest));
            $this->assertSame(2, $this->rows()[0]['months'][0]['payments']);
        }
        $this->manifest = $original;
        $this->manifest['reports'][0]['village_lgd_codes'] = 'invalid';
        file_put_contents($this->root.'/manifest.json', json_encode($this->manifest));
        $this->assertSame(2, $this->rows()[0]['months'][0]['payments']);
    }

    public function test_snapshot_sources_keep_their_own_period_and_original_report_date(): void
    {
        $key = 'vouchers_2026_04_snapshot_'.str_repeat('a', 16);
        $this->report['sources'][$key] = ['url' => 'https://egramswaraj.gov.in/voucherWiseReport.do?village=81720', 'report_date' => '2026-11-14'];
        $this->report['payments'] = [
            ['voucher' => 'XVFC/2026-27/P/1', 'date' => '2026-04-15', 'amount_paise' => 12345, 'source_key' => $key],
            ['voucher' => 'XVFC/2026-27/P/2', 'date' => '2026-05-15', 'amount_paise' => 56789, 'source_key' => $key],
        ];
        $this->save();
        $report = $this->rows()[0];
        $this->assertCount(1, $report['payments']);
        $this->assertSame(12345, $report['payments'][0]['amount_paise']);
        $this->assertSame('2026-11-14', $report['payments'][0]['report_date']);
        $this->assertSame('2026-11-06', $report['sources']['monthly']['report_date']);
    }

    public function test_wrong_report_identity_village_or_duplicate_manifest_cannot_replace_existing_data(): void
    {
        $original = $this->report;
        foreach (['panchayat_code' => '81721', 'financial_year' => '2025-2026'] as $key => $value) {
            $this->report = $original;
            $this->report[$key] = $value;
            $this->save();
            $this->assertSame(2, $this->rows()[0]['months'][0]['payments']);
        }
        $this->report = $original;
        $this->report['mapping']['village_lgd_codes'] = ['131191'];
        $this->save();
        $this->assertSame(2, $this->rows()[0]['months'][0]['payments']);
        $this->report = $original;
        $this->save();
        $this->manifest['reports'][] = $this->manifest['reports'][0];
        file_put_contents($this->root.'/manifest.json', json_encode($this->manifest));
        $this->assertSame(2, $this->rows()[0]['months'][0]['payments']);
    }
}
