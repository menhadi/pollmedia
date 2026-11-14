<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PilibhitOtherBlocksTest extends TestCase
{
    private array $package;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 15:00:00');
        $this->root = getenv('PILIBHIT_EXPANSION_FIXTURES') ?: database_path('fixtures');
        $this->package = json_decode(file_get_contents($this->root.'/egramswaraj-accounts.json'), true);
        config(['services.egramswaraj.reports_path' => $this->root.'/egramswaraj-accounts.json']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rows(): array
    {
        return array_values(array_filter($this->package['reports'], fn ($r) => ($r['sources']['monthly']['retrieved_on'] ?? '') === '2026-11-14' && ! str_starts_with($r['block_name'], 'Amariya ·')));
    }

    private function report(array $row, ?string $village = null): array
    {
        return app(MyPlacePanchayat::class)->reports(['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => $village ? ['code' => $village] : null], $row['panchayat_code'])[0] ?? [];
    }

    public function test_other_blocks_have_verified_monthly_reports_and_only_accepted_village_links(): void
    {
        $rows = $this->rows();
        $this->assertCount(615, $rows);
        $blocks = [];
        foreach ($rows as $row) {
            $blocks[explode(' · ', $row['block_name'])[0]] = true;
            $report = $this->report($row);
            $this->assertNotEmpty($report);
            $this->assertCount(count($row['months']), $report['months']);
            $this->assertSame($row['sources']['monthly']['report_date'], $report['sources']['monthly']['report_date']);
            foreach ($report['months'] as $month) {
                $this->assertLessThanOrEqual(substr($row['sources']['monthly']['report_date'], 0, 7), $month['month']);
            }
            $this->assertNotEmpty($this->report($row, $row['mapping']['village_lgd_codes'][0]));
            $this->assertSame([], $this->report($row, '131191'));
        }
        $this->assertCount(6, $blocks);
    }

    public function test_new_activities_use_individual_official_sources_in_all_six_blocks(): void
    {
        $count = 0;
        $gps = [];
        foreach ($this->rows() as $row) {
            if (empty($row['activities'][0]['source_evidence'])) {
                continue;
            }
            $gps[] = $row['panchayat_code'];
            $report = $this->report($row);
            foreach ($report['activities'] as $activity) {
                $count++;
                $this->assertSame('https://egramswaraj.gov.in/activitySectionData.do?activityCd='.$activity['code'], $activity['source_url']);
                $this->assertSame($row['panchayat_code'], collect($row['activities'])->firstWhere('code', $activity['code'])['source_evidence']['panchayat_code']);
            }
        }
        $this->assertCount(12, $gps);
        $this->assertSame(684, $count);
    }

    public function test_new_vouchers_preserve_types_and_amounts_without_overwriting_existing_aimi_records(): void
    {
        $count = 0;
        $gps = [];
        $kinds = ['payments', 'receipts', 'contra', 'journal', 'payments_cancellations', 'receipts_cancellations', 'payments_reversals', 'receipts_reversals', 'payments_adjustments', 'receipts_adjustments'];
        foreach ($this->rows() as $row) {
            if (empty($row['mapping']['evidence']['state_directory_sha256']) || ! array_filter($kinds, fn ($kind) => ! empty($row[$kind]))) {
                continue;
            }
            $report = $this->report($row);
            $gps[] = $row['panchayat_code'];
            foreach ($kinds as $kind) {
                foreach ($report[$kind] ?? [] as $entry) {
                    $count++;
                    $this->assertIsInt($entry['amount_paise']);
                    $this->assertGreaterThanOrEqual(0, $entry['amount_paise']);
                    $this->assertArrayHasKey($entry['source_key'], $report['sources']);
                }
            }
        }
        $this->assertSame(592, $count);
        $this->assertCount(11, $gps);
        $aimi = collect($this->package['reports'])->first(fn ($r) => $r['panchayat_code'] === '82033' && $r['financial_year'] === '2026-2027');
        $this->assertCount(40, $aimi['payments']);
        $this->assertCount(15, $aimi['receipts']);
        $this->assertNotEmpty($aimi['previous_monthly_snapshots']);
    }

    public function test_remaining_names_keep_explicit_secondary_evidence_without_unverified_links(): void
    {
        $review = json_decode(file_get_contents($this->root.'/pilibhit-employment-correspondence.json'), true);
        $held = array_filter($review['records'], fn ($r) => $r['was_unmatched'] && $r['status'] === 'held');
        $this->assertCount(21, $held);
        foreach ($held as $row) {
            $this->assertSame('2026-11-14', $row['secondary_review']['reviewed_on']);
            $this->assertStringStartsWith('https://panchayatiraj.up.nic.in/', $row['secondary_review']['source_url']);
            $this->assertStringStartsWith('Held:', $row['secondary_review']['decision']);
        }
    }
}
