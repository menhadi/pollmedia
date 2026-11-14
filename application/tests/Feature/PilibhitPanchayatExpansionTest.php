<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use App\Services\RuralEmployment;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PilibhitPanchayatExpansionTest extends TestCase
{
    private string $path;

    private array $package;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 14:00:00');
        $this->fixtureRoot = getenv('PILIBHIT_EXPANSION_FIXTURES') ?: database_path('fixtures');
        $this->package = json_decode(file_get_contents($this->fixtureRoot.'/egramswaraj-accounts.json'), true);
        $this->path = tempnam(sys_get_temp_dir(), 'pilibhit-expansion-');
        $this->save();
        config(['services.egramswaraj.reports_path' => $this->path, 'services.rural_employment.path' => $this->fixtureRoot.'/pilibhit-rural-employment.json']);
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function save(): void
    {
        file_put_contents($this->path, json_encode($this->package));
    }

    private function report(int $gp, ?string $village = null): array
    {
        return app(MyPlacePanchayat::class)->reports(['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => $village ? ['code' => $village] : null], (string) $gp)[0] ?? [];
    }

    public function test_all_new_monthly_reports_preserve_counts_and_source_dates_without_future_zero_rows(): void
    {
        $rows = array_filter($this->package['reports'], fn ($r) => ($r['sources']['monthly']['retrieved_on'] ?? '') === '2026-11-14' && str_starts_with($r['block_name'], 'Amariya ·'));
        $this->assertCount(105, $rows);
        foreach ($rows as $raw) {
            $report = $this->report((int) $raw['panchayat_code']);
            $this->assertCount(8, $report['months']);
            $this->assertSame('2026-04', $report['months'][0]['month']);
            $this->assertSame('2026-11', $report['months'][7]['month']);
            $this->assertSame($raw['sources']['monthly']['report_date'], $report['sources']['monthly']['report_date']);
        }
        $html = view('panchayat-monthly-vouchers', ['reports' => [$this->report(81737)], 'hi' => false])->render();
        $this->assertStringContainsString('Receipt vouchers', $html);
        $this->assertStringContainsString('Payment vouchers', $html);
        $this->assertStringContainsString('2026-11-06', $html);
        $this->assertStringNotContainsString('2026-12', $html);
        $this->assertStringNotContainsString('₹', $html);
    }

    public function test_new_voucher_entries_are_preserved_and_never_assigned_to_another_village(): void
    {
        $kinds = ['payments', 'receipts', 'contra', 'journal', 'payments_cancellations', 'receipts_cancellations', 'payments_reversals', 'receipts_reversals', 'payments_adjustments', 'receipts_adjustments'];
        $count = 0;
        for ($gp = 81737; $gp <= 81746; $gp++) {
            $report = $this->report($gp);
            foreach ($kinds as $kind) {
                $count += count($report[$kind] ?? []);
                foreach ($report[$kind] ?? [] as $entry) {
                    $this->assertGreaterThanOrEqual(0, $entry['amount_paise']);
                    $this->assertStringStartsWith('vouchers_2026_', $entry['source_key']);
                }
            }
        }
        $this->assertSame(534, $count);
        $raw = collect($this->package['reports'])->firstWhere('panchayat_code', '81737');
        $village = $raw['mapping']['village_lgd_codes'][0];
        $this->assertNotEmpty($this->report(81737, $village));
        $this->assertSame([], $this->report(81737, '131191'));
    }

    public function test_new_activity_rows_have_verified_code_bound_sources_and_old_activities_remain(): void
    {
        $count = 0;
        for ($gp = 81721; $gp <= 81730; $gp++) {
            $report = $this->report($gp);
            $this->assertNotEmpty($report['activities']);
            foreach ($report['activities'] as $activity) {
                $count++;
                $this->assertSame('https://egramswaraj.gov.in/activitySectionData.do?activityCd='.$activity['code'], $activity['source_url']);
                $this->assertSame('2026-11-14', $activity['source_date']);
            }
        }
        $this->assertSame(651, $count);
        $this->assertCount(44, $this->report(82056)['activities']);
        $this->assertCount(67, $this->report(81720)['activities']);
    }

    public function test_wrong_panchayat_year_source_or_activity_id_is_not_published(): void
    {
        $original = $this->package;
        $index = array_search('81725', array_column($this->package['reports'], 'panchayat_code'), true);
        $normal = count($this->report(81725)['activities']);
        foreach (['panchayat_code' => '81728', 'financial_year' => '2025-2026', 'activity_code' => '999999999', 'url' => 'https://evil.test/activitySectionData.do?activityCd=1', 'sha256' => 'invalid'] as $key => $value) {
            $this->package = $original;
            $this->package['reports'][$index]['activities'][0]['source_evidence'][$key] = $value;
            $this->save();
            $this->assertCount($normal - 1, $this->report(81725)['activities'], $key);
        }
    }

    public function test_every_previously_unmatched_name_is_reviewed_and_conflicts_are_held(): void
    {
        $review = json_decode(file_get_contents($this->fixtureRoot.'/pilibhit-employment-correspondence.json'), true);
        $remaining = array_filter($review['records'], fn ($r) => $r['was_unmatched']);
        $this->assertCount(76, $remaining);
        $this->assertSame(['held' => 21, 'verified' => 55], $review['remaining_76']);
        $this->assertSame('81720', app(RuralEmployment::class)->forPanchayat(9, 81720)['gp']);
        foreach ([81747, 81750, 81757, 81774, 81794] as $gp) {
            $this->assertNull(app(RuralEmployment::class)->forPanchayat(9, $gp));
        }
        foreach ($review['records'] as $record) {
            if ($record['status'] === 'verified') {
                $this->assertSame($record['gp'], app(RuralEmployment::class)->forPanchayat(9, (int) $record['gp'])['gp']);
            } else {
                $this->assertNotEmpty($record['reason']);
            }
        }
    }
}
