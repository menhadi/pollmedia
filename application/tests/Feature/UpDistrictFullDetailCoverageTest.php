<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpDistrictFullDetailCoverageTest extends TestCase
{
    public function test_every_published_detail_file_preserves_its_rows_and_rejects_wrong_village_links(): void
    {
        Carbon::setTestNow('2026-11-16 15:00:00');
        $directory = getenv('UP_DETAIL_FIXTURES') ?: database_path('fixtures/egramswaraj-details');
        $index = json_decode(file_get_contents($directory.'/up-districts.json'), true);
        foreach (['130' => 1188, '180' => 1068] as $district => $expected) {
            $district = (string) $district;
            $root = $directory.'/9-'.$district;
            $manifest = json_decode(file_get_contents($root.'/manifest.json'), true);
            $this->assertSame(hash_file('sha256', $root.'/manifest.json'), $index['districts'][$district]['sha256']);
            Carbon::setTestNow($manifest['captured_on'].' 23:59:59');
            $baseline = tempnam(sys_get_temp_dir(), 'gp-detail-base-');
            file_put_contents($baseline, json_encode(['schema' => 1, 'publication' => ['enabled' => true, 'evidence' => ['official' => true]], 'reports' => []]));
            config(['services.egramswaraj.reports_path' => $baseline, 'services.egramswaraj.shard_manifest_path' => null, 'services.egramswaraj.district_shards_index_path' => $directory.'/up-districts.json']);
            try {
                $this->assertCount($expected, $manifest['reports']);
                $totals = ['activities' => 0, 'vouchers' => 0];
                foreach ($manifest['reports'] as $entry) {
                    $raw = json_decode(file_get_contents($root.'/'.$entry['file']), true)['report'];
                    $context = ['state' => ['code' => '9'], 'district' => ['code' => $district], 'village' => null];
                    $reports = app(MyPlacePanchayat::class)->reports($context, $entry['panchayat_code']);
                    $this->assertSame(hash_file('sha256', $root.'/'.$entry['file']), $entry['sha256']);
                    $this->assertCount(1, $reports, $entry['panchayat_code']);
                    $member = $context;
                    $member['village'] = ['code' => $entry['village_lgd_codes'][0]];
                    $this->assertCount(1, app(MyPlacePanchayat::class)->reports($member, $entry['panchayat_code']));
                    $foreign = $context;
                    $foreign['district'] = ['code' => $district === '130' ? '180' : '130'];
                    $this->assertSame([], app(MyPlacePanchayat::class)->reports($foreign, $entry['panchayat_code']));
                    $report = $reports[0];
                    $this->assertCount(count($raw['activities'] ?? []), $report['activities'], $entry['panchayat_code'].' activities');
                    $totals['activities'] += count($report['activities']);
                    $rawActivities = array_column($raw['activities'] ?? [], null, 'code');
                    foreach ($report['activities'] as $activity) {
                        $sourceRow = $rawActivities[$activity['code']];
                        $this->assertSame($sourceRow['cost'] ?? null, $activity['cost']);
                        $this->assertSame($sourceRow['source_evidence']['url'], $activity['source_url']);
                        if (isset($sourceRow['section_details']) && empty($sourceRow['details'])) {
                            $this->assertSame($sourceRow['source_evidence']['plan_id'], $activity['details']['plan_id']);
                            $this->assertSame($sourceRow['section_details']['description'], $activity['details']['description']);
                            $this->assertSame($sourceRow['section_details']['theme'], $activity['details']['theme']);
                        }
                    }

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
                $summary = json_decode(file_get_contents($root.'/summary.json'), true);
                $this->assertSame($summary['activity_membership'], $totals['activities']);
                $this->assertSame($summary['verified_activity_details'], $totals['activities']);
                $this->assertGreaterThan(40000, $totals['vouchers']);
            } finally {
                unlink($baseline);
                Carbon::setTestNow();
            }
        }
    }
}
