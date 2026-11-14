<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use Tests\TestCase;

class PanchayatSourceNotesTest extends TestCase
{
    public function test_all_source_only_identities_are_visible_without_unverified_village_links(): void
    {
        $root = getenv('PANCHAYAT_NOTES_FIXTURES') ?: database_path('fixtures/egramswaraj-details');
        $base = tempnam(sys_get_temp_dir(), 'source-notes-');
        file_put_contents($base, json_encode(['schema' => 1, 'publication' => ['enabled' => true, 'evidence' => ['official' => true]], 'reports' => []]));
        config(['services.egramswaraj.reports_path' => $base, 'services.egramswaraj.shard_manifest_path' => null, 'services.egramswaraj.district_shards_index_path' => $root.'/up-districts.json']);
        try {
            foreach (['130' => 5, '180' => 9] as $district => $count) {
                $context = ['state' => ['code' => '9'], 'district' => ['code' => (string) $district], 'village' => null];
                $items = app(MyPlacePanchayat::class)->sourceOnlyPanchayats($context);
                $this->assertCount($count, $items);
                foreach ($items as $item) {
                    $reports = app(MyPlacePanchayat::class)->reports($context, $item['code']);
                    $this->assertCount(1, $reports);
                    $this->assertNotEmpty($reports[0]['source_notes']);
                    $this->assertSame([], $reports[0]['months']);
                    $this->assertSame([], $reports[0]['financial_summary']['totals']);
                    $village = $context;
                    $village['village'] = ['code' => '999999'];
                    $this->assertSame([], app(MyPlacePanchayat::class)->reports($village, $item['code']));
                    $this->assertSame([], app(MyPlacePanchayat::class)->sourceOnlyPanchayats($village));
                }
            }
            foreach (['86968', '87299', '87572'] as $gp) {
                $reports = app(MyPlacePanchayat::class)->reports(['state' => ['code' => '9'], 'district' => ['code' => '180'], 'village' => null], $gp);
                $this->assertNotEmpty($reports[0]['source_notes']);
                $this->assertNotEmpty($reports[0]['activities']);
                $this->assertSame([], $reports[0]['months']);
            }
            $reports = app(MyPlacePanchayat::class)->reports(['state' => ['code' => '9'], 'district' => ['code' => '130'], 'village' => null], '53188');
            $report = $reports[0];
            $this->assertCount(6, $report['source_reported_vouchers']);
            $this->assertCount(1, array_filter($report['source_reported_vouchers'], fn ($row) => $row['date'] > $row['report_date']));
            foreach (['receipts', 'payments'] as $kind) {
                $this->assertSame([], array_values(array_filter($report[$kind], fn ($row) => str_starts_with($row['date'], '2026-11'))));
            }
            $rendered = view('my-place-panchayat', ['hub' => ['panchayat_reports' => $reports], 'context' => ['village' => null], 't' => fn ($en, $hi) => $en])->render();
            $this->assertSame(6, substr_count($rendered, 'data-source-reported-voucher'));
            $this->assertStringContainsString('Entry date is later than the source report date.', $rendered);
        } finally {
            unlink($base);
        }
    }
}
