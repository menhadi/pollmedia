<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PilibhitFullDetailCoverageTest extends TestCase
{
    public function test_every_published_detail_file_preserves_its_rows_and_rejects_wrong_village_links(): void
    {
        Carbon::setTestNow('2026-11-14 15:00:00');
        $root = getenv('PILIBHIT_DETAIL_FIXTURES') ?: database_path('fixtures/egramswaraj-details');
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true);
        $baseline = tempnam(sys_get_temp_dir(), 'gp-detail-base-');
        file_put_contents($baseline, json_encode(['schema' => 1, 'publication' => ['enabled' => true, 'evidence' => ['official' => true]], 'reports' => []]));
        config(['services.egramswaraj.reports_path' => $baseline, 'services.egramswaraj.shard_manifest_path' => $root.'/manifest.json']);
        try {
            $this->assertCount(720, $manifest['reports']);
            $totals = ['activities' => 0, 'vouchers' => 0];
            foreach ($manifest['reports'] as $entry) {
                $raw = json_decode(file_get_contents($root.'/'.$entry['file']), true)['report'];
                $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => null];
                $reports = app(MyPlacePanchayat::class)->reports($context, $entry['panchayat_code']);
                $this->assertCount(1, $reports, $entry['panchayat_code']);
                $report = $reports[0];
                $this->assertCount(count($raw['activities'] ?? []), $report['activities'], $entry['panchayat_code'].' activities');
                $totals['activities'] += count($report['activities']);
                $rawVouchers = 0;
                $normalizedVouchers = 0;
                foreach (['payments', 'receipts', 'contra', 'journal'] as $kind) {
                    $rawVouchers += count($raw[$kind] ?? []);
                    $normalizedVouchers += count($report[$kind] ?? []);
                }
                foreach (['payments', 'receipts'] as $kind) {
                    foreach (['cancellations', 'reversals', 'adjustments'] as $type) {
                        $normalizedVouchers += count($report[$kind.'_'.$type] ?? []);
                    }
                }
                $this->assertSame($rawVouchers, $normalizedVouchers, $entry['panchayat_code'].' voucher identity/source validation');
                $totals['vouchers'] += $normalizedVouchers;
                $context['village'] = ['code' => '999999'];
                $this->assertSame([], app(MyPlacePanchayat::class)->reports($context, $entry['panchayat_code']));
            }
            $this->assertGreaterThan(39000, $totals['activities']);
            $this->assertGreaterThan(34000, $totals['vouchers']);
        } finally {
            unlink($baseline);
            Carbon::setTestNow();
        }
    }
}
