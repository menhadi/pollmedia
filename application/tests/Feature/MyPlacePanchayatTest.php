<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use Tests\TestCase;

class MyPlacePanchayatTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'panchayat-test-');
        config(['services.egramswaraj.reports_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        parent::tearDown();
    }

    private function context(?string $village = null): array
    {
        return ['state' => ['code' => '29'], 'district' => ['code' => '548'], 'village' => $village ? ['code' => $village] : null];
    }

    public function test_activities_preserve_missing_costs_and_original_status_without_changing_payments(): void
    {
        $report = $this->report();
        $report['sources']['activities'] = ['url' => 'https://egramswaraj.gov.in/actionPlanReportForGP.do', 'report_date' => '2026-10-28'];
        $activity = ['code' => '123', 'name' => 'Original activity', 'cost' => null, 'focus_area' => 'Water', 'type' => 'Community Works', 'status' => 'Activity Approved', 'output_type' => 'Asset', 'plan_type' => 'Part of Main Plan'];
        $report['activities'] = [$activity, [...$activity, 'code' => '124', 'cost' => '0'], [...$activity, 'code' => '125', 'cost' => '10'], [...$activity, 'code' => '126'], [...$activity, 'code' => '126', 'name' => 'Conflicting activity']];
        $this->save([$report]);
        $data = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertCount(3, $data['activities']);
        $this->assertNull($data['activities'][0]['cost']);
        $this->assertSame('0', $data['activities'][1]['cost']);
        $this->assertSame('10', $data['activities'][2]['cost']);
        $this->assertSame('Activity Approved', $data['activities'][0]['status']);
        $this->assertSame(281600, $data['financial_summary']['totals']['payments']['amount_paise']);
        unset($report['sources']['activities']);
        $this->save([$report]);
        $this->assertSame([], app(MyPlacePanchayat::class)->reports($this->context())[0]['activities']);
    }

    public function test_activity_detail_identity_and_allocations_do_not_change_accounting_totals(): void
    {
        $package = json_decode(file_get_contents(database_path('fixtures/egramswaraj-accounts.json')), true);
        $report = collect($package['reports'])->firstWhere('panchayat_code', '82056');
        $this->save([$report]);
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => ['code' => '131596']];
        $result = app(MyPlacePanchayat::class)->reports($context)[0];
        $this->assertCount(44, $result['activities']);
        $this->assertCount(33, array_filter(array_column($result['activities'], 'details')));
        $this->assertSame(17, collect($result['activities'])->sum(fn ($row) => count($row['details']['funding'] ?? [])));
        $this->assertSame(37433700, $result['financial_summary']['totals']['payments']['amount_paise']);
        $detail = collect($result['activities'])->firstWhere('code', '133057127')['details'];
        $this->assertSame('250000', $detail['funding'][0]['amount']);
        $this->assertNull($detail['funding'][0]['unit']);
        $this->assertSame('No Data Found', $detail['expenditure_status']);
        $this->assertSame('Jatipur (131596)', $detail['asset']['Asset Location']);
        foreach (['en', 'hi'] as $language) {
            $html = view('my-place-panchayat-activities', ['panchayat' => ['reports' => [$result]], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
            $this->assertSame(33, substr_count($html, 'data-activity-description'));
            $this->assertSame(17, substr_count($html, 'data-funding-allocation'));
            $this->assertStringContainsString('XVI Finance Commission', $html);
            $this->assertStringContainsString($language === 'en' ? 'unit not stated' : 'इकाई नहीं दी गई', $html);
            $this->assertStringContainsString($language === 'en' ? 'Further details unavailable' : 'अतिरिक्त विवरण उपलब्ध नहीं', $html);
            $this->assertStringNotContainsString('₹250000', $html);
        }
        $index = array_search('133057127', array_column($report['activities'], 'code'), true);
        foreach (['panchayat_code' => '999', 'activity_code' => '123', 'financial_year' => '2025-2026', 'source_url' => 'https://example.com/', 'source_sha256' => 'missing'] as $field => $value) {
            $changed = $report;
            $changed['activities'][$index]['details'][$field] = $value;
            $this->save([$changed]);
            $result = app(MyPlacePanchayat::class)->reports($context)[0];
            $this->assertNull($result['activities'][$index]['details'], $field);
            $this->assertCount(44, $result['activities']);
            $this->assertSame(37433700, $result['financial_summary']['totals']['payments']['amount_paise']);
        }
    }

    public function test_aimi_finances_use_corroborated_village_and_keep_dated_snapshots_separate(): void
    {
        $package = json_decode(file_get_contents(database_path('fixtures/egramswaraj-accounts.json')), true);
        $report = collect($package['reports'])->firstWhere('panchayat_code', '82033');
        $this->save([$report]);
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => ['code' => '131609']];
        $result = app(MyPlacePanchayat::class)->reports($context)[0];
        $this->assertCount(40, $result['payments']);
        $this->assertCount(15, $result['receipts']);
        $this->assertCount(1, $result['contra']);
        $this->assertSame([], $result['activities']);
        $this->assertSame(103801000, $result['financial_summary']['totals']['payments']['amount_paise']);
        $this->assertSame(103795400, $result['financial_summary']['totals']['receipts']['amount_paise']);
        $this->assertSame(840000, $result['financial_summary']['totals']['contra']['amount_paise']);
        $this->assertCount(7, $result['months']);
        $this->assertSame(3, $result['months'][6]['payments']);
        $this->assertCount(8, array_filter($result['payments'], fn ($row) => str_starts_with($row['date'], '2026-10')));
        $this->assertTrue($result['opening_balance_entered']);
        $this->assertTrue($result['month_book_closed']);
        $this->assertFalse($result['year_book_closed']);
        foreach (['en', 'hi'] as $language) {
            $html = $this->renderReports([$result], $language);
            foreach (['Aimi', '₹1,038,010.00', '₹1,037,954.00', '₹8,400.00', '2026-10-25', '2026-10-28', '5THSFC/2026-27/P/38'] as $expected) {
                $this->assertStringContainsString($expected, $html);
            }
        }
        $context['village']['code'] = '131596';
        $this->assertSame([], app(MyPlacePanchayat::class)->reports($context));
        $context['village']['code'] = '82033';
        $this->assertSame([], app(MyPlacePanchayat::class)->reports($context));
    }

    private function report(): array
    {
        $source = ['url' => 'https://egramswaraj.gov.in/financialProgressReport.do', 'report_date' => '2026-10-25'];

        return [
            'panchayat_code' => '220180', 'name' => 'Example Panchayat', 'native_name' => 'ಗ್ರಾಮ ಪಂಚಾಯಿತಿ',
            'block_name' => 'Example block', 'financial_year' => '2026-2027',
            'mapping' => ['verified' => true, 'evidence' => 'test-only explicit correspondence', 'state_lgd' => '29', 'district_lgd' => '548', 'village_lgd_codes' => ['612623'], 'village_evidence' => 'test-only village correspondence'],
            'sources' => ['monthly' => $source, 'payments' => $source, 'accounting' => $source],
            'months' => [['month' => '2026-04', 'receipts' => 0, 'payments' => 15], ['month' => '2026-05', 'receipts' => null, 'payments' => 6], ['month' => '2026-11', 'receipts' => 0, 'payments' => 0]],
            'payments' => [['date' => '2026-10-06', 'voucher' => 'TEST/P/1', 'amount_paise' => 281600]],
            'opening_balance_entered' => true, 'month_book_closed' => false, 'year_book_closed' => null,
        ];
    }

    private function save(array $reports, bool $enabled = true): void
    {
        file_put_contents($this->path, json_encode(['schema' => 1, 'publication' => ['enabled' => $enabled, 'evidence' => 'test-only publication instruction'], 'reports' => $reports], JSON_UNESCAPED_UNICODE));
    }

    public function test_disabled_malformed_and_unmapped_packages_are_not_published(): void
    {
        $this->save([$this->report()], false);
        $this->assertSame([], app(MyPlacePanchayat::class)->reports($this->context()));
        file_put_contents($this->path, '{incomplete');
        $this->assertSame([], app(MyPlacePanchayat::class)->reports($this->context()));
        $report = $this->report();
        $report['mapping']['verified'] = false;
        $this->save([$report]);
        $this->assertSame([], app(MyPlacePanchayat::class)->reports($this->context()));
    }

    public function test_district_codes_and_panchayat_codes_are_not_substituted_for_village_codes(): void
    {
        $this->save([$this->report()]);
        $service = app(MyPlacePanchayat::class);
        $this->assertCount(1, $service->reports($this->context()));
        $this->assertCount(1, $service->reports($this->context('612623')));
        $this->assertSame([], $service->reports($this->context('220180')));
        $this->assertSame([], $service->reports(['state' => ['code' => '9'], 'district' => ['code' => '548'], 'village' => null]));
        $report = $this->report();
        $report['mapping']['district_lgd'] = '503';
        $this->save([$report]);
        $this->assertSame([], $service->reports($this->context()));
        $report = $this->report();
        unset($report['mapping']['village_evidence']);
        $this->save([$report]);
        $this->assertSame([], $service->reports($this->context('612623')));
    }

    public function test_verified_village_reports_outside_pilibhit_are_selected_and_rendered(): void
    {
        foreach ([['29', '548', '612623'], ['30', '551', '626745']] as [$state, $district, $village]) {
            $report = $this->report();
            $report['mapping']['state_lgd'] = $state;
            $report['mapping']['district_lgd'] = $district;
            $report['mapping']['village_lgd_codes'] = [$village];
            $this->save([$report]);
            $context = ['state' => ['code' => $state], 'district' => ['code' => $district],
                'village' => ['code' => $village, 'name' => 'Selected village']];
            $data = app(MyPlacePanchayat::class)->data($context);
            $this->assertSame('220180', $data['selected']['code'] ?? null);
            $this->assertCount(1, $data['reports']);
            $this->assertSame([['code' => $village, 'name' => 'Selected village']], $data['selected']['villages']);
            foreach (['en', 'hi'] as $language) {
                $html = $this->renderReports($data['reports'], $language);
                $this->assertStringContainsString('₹2,816.00', $html);
                $this->assertStringContainsString('ಗ್ರಾಮ ಪಂಚಾಯಿತಿ', $html);
            }
            $this->assertNull(app(MyPlacePanchayat::class)->data($context, '999')['selected']);
        }
    }

    public function test_village_fallback_does_not_resolve_conflicting_or_unverified_panchayats(): void
    {
        $report = $this->report();
        $other = [...$report, 'panchayat_code' => '220181', 'name' => 'Different Panchayat'];
        $this->save([$report, $other]);
        $service = app(MyPlacePanchayat::class);
        $context = $this->context('612623');
        $this->assertSame([], $service->data($context)['directory']);
        $this->assertSame([], $service->data($context, '220180')['reports']);
        unset($report['mapping']['village_evidence']);
        $this->save([$report]);
        $this->assertSame([], $service->data($context)['directory']);
        $this->save([$this->report()]);
        $this->assertSame([], $service->data($this->context('220180'))['reports']);
    }

    public function test_report_fallback_does_not_override_an_ambiguous_local_directory(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'panchayat-directory-test-');
        try {
            file_put_contents($directory, json_encode(['villages' => [
                ['code' => '131596', 'district_code' => '173', 'name' => 'Village',
                    'panchayats' => [['code' => '220180', 'name' => 'One'], ['code' => '220181', 'name' => 'Two']]],
            ]]));
            config(['services.egramswaraj.directory_path' => $directory]);
            $report = $this->report();
            $report['mapping'] = [...$report['mapping'], 'state_lgd' => '9', 'district_lgd' => '173', 'village_lgd_codes' => ['131596']];
            $this->save([$report]);
            $data = app(MyPlacePanchayat::class)->data(['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => ['code' => '131596']], '220180');
            $this->assertSame([], $data['directory']);
            $this->assertSame([], $data['reports']);
        } finally {
            unlink($directory);
        }
    }

    public function test_missing_values_stay_null_future_months_are_excluded_and_counts_are_not_amounts(): void
    {
        $this->save([$this->report()]);
        $report = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertCount(2, $report['months']);
        $this->assertSame(0, $report['months'][0]['receipts']);
        $this->assertNull($report['months'][1]['receipts']);
        $this->assertSame(281600, $report['payments'][0]['amount_paise']);
        $this->assertFalse($report['month_book_closed']);
        $this->assertNull($report['year_book_closed']);
        foreach (['en', 'hi'] as $language) {
            $html = $this->renderReports([$report], $language);
            $this->assertStringContainsString('₹2,816.00', $html);
            $this->assertStringContainsString('ಗ್ರಾಮ ಪಂಚಾಯಿತಿ', $html);
            $this->assertStringContainsString('2026-10-25', $html);
            $this->assertStringContainsString($language === 'en' ? 'Unavailable' : 'उपलब्ध नहीं', $html);
            $this->assertStringNotContainsString('2026-11', $html);
            $this->assertStringNotContainsString('<details', $html);
            $this->assertStringNotContainsString('href=', $html);
        }
    }

    public function test_duplicate_reports_months_and_vouchers_do_not_pick_an_arbitrary_value(): void
    {
        $report = $this->report();
        $this->save([$report, $report]);
        $this->assertSame([], app(MyPlacePanchayat::class)->reports($this->context()));
        $report['months'][] = ['month' => '2026-04', 'receipts' => 42, 'payments' => 18];
        $report['payments'][] = ['date' => '2026-10-06', 'voucher' => 'TEST/P/1', 'amount_paise' => 17];
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertSame(['2026-05'], array_column($result['months'], 'month'));
        $this->assertSame([], $result['payments']);
    }

    public function test_invalid_dates_negative_amounts_and_unsafe_sources_are_not_rendered_as_valid_data(): void
    {
        $report = $this->report();
        $report['payments'][] = ['date' => '2026-02-30', 'voucher' => 'TEST/P/2', 'amount_paise' => 20];
        $report['payments'][0]['amount_paise'] = -2;
        $report['name'] = '<script>alert(1)</script>';
        $report['sources']['monthly']['url'] = 'https://egramswaraj.gov.in.evil.example/data';
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertSame([], $result['months']);
        $this->assertCount(1, $result['payments']);
        $this->assertNull($result['payments'][0]['amount_paise']);
        $this->assertStringNotContainsString('<script>', $this->renderReports([$result], 'en'));
    }

    public function test_receipts_and_transfers_require_their_own_valid_dated_source(): void
    {
        $report = $this->report();
        $report['sources']['vouchers_2026_05'] = $report['sources']['payments'];
        $report['receipts'] = [
            ['date' => '2026-05-06', 'voucher' => 'TEST/R/1', 'amount_paise' => 5653800, 'source_key' => 'vouchers_2026_05'],
            ['date' => '2026-06-06', 'voucher' => 'TEST/R/2', 'amount_paise' => 42, 'source_key' => 'vouchers_2026_05'],
            ['date' => '2026-05-06', 'voucher' => 'TEST/R/3', 'amount_paise' => 42, 'source_key' => 'monthly'],
        ];
        $report['contra'] = [['date' => '2026-05-06', 'voucher' => 'TEST/C/1', 'amount_paise' => 171900, 'source_key' => 'vouchers_2026_05']];
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertCount(1, $result['receipts']);
        $this->assertSame(5653800, $result['receipts'][0]['amount_paise']);
        $this->assertSame(171900, $result['contra'][0]['amount_paise']);
        $this->assertNull($result['months'][0]['contra']);
        foreach (['en', 'hi'] as $language) {
            $html = $this->renderReports([$result], $language);
            $this->assertStringContainsString('₹56,538.00', $html);
            $this->assertStringContainsString('₹1,719.00', $html);
            $this->assertStringNotContainsString('TEST/R/2', $html);
            $this->assertStringNotContainsString('TEST/R/3', $html);
        }
    }

    public function test_financial_summary_keeps_transfers_separate_and_missing_amounts_unavailable(): void
    {
        $report = $this->report();
        $report['payments'][] = ['date' => '2026-10-07', 'voucher' => 'TEST/P/2', 'amount_paise' => 337100];
        $report['sources']['receipts'] = $report['sources']['payments'];
        $report['sources']['contra'] = $report['sources']['payments'];
        $report['receipts'] = [['date' => '2026-09-01', 'voucher' => 'TEST/R/1', 'amount_paise' => 0]];
        $report['contra'] = [['date' => '2026-10-06', 'voucher' => 'TEST/C/1', 'amount_paise' => 171900]];
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $summary = $result['financial_summary'];
        $this->assertSame(618700, $summary['totals']['payments']['amount_paise']);
        $this->assertSame(0, $summary['totals']['receipts']['amount_paise']);
        $this->assertSame(171900, $summary['totals']['contra']['amount_paise']);
        $this->assertArrayNotHasKey('journal', $summary['totals']);
        $this->assertNull($summary['months'][0]['payments']);
        $this->assertNull($summary['months'][1]['receipts']);
        $this->assertSame('2026-10-06', $summary['totals']['payments']['from']);
        $this->assertSame('2026-10-07', $summary['totals']['payments']['to']);
        foreach (['en', 'hi'] as $language) {
            $html = $this->renderReports([$result], $language);
            $this->assertStringContainsString('₹6,187.00', $html);
            $this->assertStringContainsString('₹0.00', $html);
            $this->assertStringContainsString('gp-chart-tooltip', $html);
            $this->assertStringNotContainsString('₹7,906.00', $html);
        }
        $report['payments'][1]['amount_paise'] = null;
        $this->save([$report]);
        $summary = app(MyPlacePanchayat::class)->reports($this->context())[0]['financial_summary'];
        $this->assertNull($summary['totals']['payments']['amount_paise']);
        $this->assertNull($summary['months'][1]['payments']);
    }

    public function test_reverse_receipts_and_payments_keep_source_types_out_of_money_flow(): void
    {
        $report = $this->report();
        $report['sources']['receipts'] = $report['sources']['payments'];
        $report['receipts'] = [
            ['date' => '2026-04-30', 'voucher' => 'XVFC/2026-27/R/1', 'voucher_type' => 'Reverse Receipt -PFMS', 'amount_paise' => 22689100],
            ['date' => '2026-04-30', 'voucher' => 'TEST/R/2', 'voucher_type' => 'Receipt -PFMS', 'amount_paise' => 10000],
        ];
        $report['payments'][] = ['date' => '2026-10-06', 'voucher' => 'TEST/P/2', 'voucher_type' => 'Reverse Payment -PFMS', 'amount_paise' => 50000];
        $report['payments'][] = ['date' => '2026-05-15', 'voucher' => 'FFC/2026-27/P/1', 'voucher_type' => 'OB Cancellation', 'amount_paise' => 7248900];
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $summary = $result['financial_summary'];
        $this->assertCount(1, $result['receipts']);
        $this->assertSame('Reverse Receipt -PFMS', $result['receipts_reversals'][0]['voucher_type']);
        $this->assertSame('Receipt -PFMS', $result['receipts'][0]['voucher_type']);
        $this->assertSame(10000, $summary['totals']['receipts']['amount_paise']);
        $this->assertSame(22689100, $summary['totals']['receipts_reversals']['amount_paise']);
        $this->assertSame(281600, $summary['totals']['payments']['amount_paise']);
        $this->assertSame(50000, $summary['totals']['payments_reversals']['amount_paise']);
        $this->assertSame(7248900, $summary['totals']['payments_adjustments']['amount_paise']);
        $this->assertSame('OB Cancellation', $result['payments_adjustments'][0]['voucher_type']);
        $this->assertSame(10000, $summary['months'][0]['receipts']);
        $this->assertSame(281600, $summary['months'][1]['payments']);
        foreach (['en', 'hi'] as $language) {
            $html = $this->renderReports([$result], $language);
            $this->assertStringContainsString('Reverse Receipt -PFMS', $html);
            $this->assertStringContainsString('Reverse Payment -PFMS', $html);
            $this->assertStringContainsString('OB Cancellation', $html);
            $this->assertStringContainsString($language === 'en' ? 'Payment-side balance adjustments' : 'भुगतान पक्ष के शेष समायोजन', $html);
            $this->assertStringContainsString('₹226,891.00', $html);
            $this->assertStringContainsString($language === 'en' ? 'Receipt reversals' : 'प्राप्ति की उलटी प्रविष्टियाँ', $html);
            $this->assertStringNotContainsString('₹226,991.00', $html);
        }
        $report['receipts'] = [$report['receipts'][0]];
        $report['payments'] = [];
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertArrayNotHasKey('receipts', $result['financial_summary']['totals']);
        $this->assertSame([], $result['financial_summary']['months']);
        $this->assertStringNotContainsString('gp-money-chart', $this->renderReports([$result], 'en'));
        $report['receipts'][0]['amount_paise'] = null;
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertNull($result['financial_summary']['totals']['receipts_reversals']['amount_paise']);
    }

    public function test_cancellations_preserve_source_side_without_inflating_totals_or_charts(): void
    {
        $report = $this->report();
        $report['sources']['receipts'] = $report['sources']['payments'];
        $report['receipts'] = [
            ['date' => '2026-08-01', 'voucher' => 'TEST/R/1', 'voucher_type' => 'Direct Receipts', 'amount_paise' => 10000],
            ['date' => '2026-08-01', 'voucher' => 'TEST/R/2', 'voucher_type' => 'Payment Cancellation', 'amount_paise' => 50000],
        ];
        // The official Moira report lists Receipt Cancellation on the payments side.
        $report['payments'][] = ['date' => '2026-08-01', 'voucher' => 'TEST/P/CANCEL', 'voucher_type' => 'Receipt Cancellation', 'amount_paise' => 819600];
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $summary = $result['financial_summary'];
        $this->assertCount(1, $result['payments']);
        $this->assertCount(1, $result['receipts']);
        $this->assertSame('Receipt Cancellation', $result['payments_cancellations'][0]['voucher_type']);
        $this->assertSame('Payment Cancellation', $result['receipts_cancellations'][0]['voucher_type']);
        $this->assertSame(819600, $summary['totals']['payments_cancellations']['amount_paise']);
        $this->assertSame(50000, $summary['totals']['receipts_cancellations']['amount_paise']);
        $this->assertSame(281600, $summary['totals']['payments']['amount_paise']);
        $this->assertSame(10000, $summary['totals']['receipts']['amount_paise']);
        $this->assertSame(['month' => '2026-08', 'receipts' => 10000, 'payments' => null], $summary['months'][0]);
        $this->assertSame(['month' => '2026-10', 'receipts' => null, 'payments' => 281600], $summary['months'][1]);
        foreach (['en', 'hi'] as $language) {
            $html = $this->renderReports([$result], $language);
            $this->assertStringContainsString($language === 'en' ? 'Cancelled entries · payments' : 'रद्द प्रविष्टियाँ · भुगतान', $html);
            $this->assertStringContainsString($language === 'en' ? 'Cancelled entries · receipts' : 'रद्द प्रविष्टियाँ · प्राप्तियाँ', $html);
            $this->assertStringContainsString('Receipt Cancellation', $html);
            $this->assertStringContainsString('Payment Cancellation', $html);
            $this->assertStringContainsString('₹8,196.00', $html);
            $this->assertStringNotContainsString('₹11,012.00', $html);
            $this->assertStringNotContainsString('₹600.00', $html);
        }
        $report['payments'] = [$report['payments'][1]];
        $report['receipts'] = [$report['receipts'][1]];
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertSame([], $result['financial_summary']['months']);
        $this->assertArrayNotHasKey('payments', $result['financial_summary']['totals']);
        $this->assertArrayNotHasKey('receipts', $result['financial_summary']['totals']);
        foreach (['en', 'hi'] as $language) {
            $this->assertStringNotContainsString('gp-money-chart', $this->renderReports([$result], $language));
        }
        $report['payments'][0]['amount_paise'] = null;
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertNull($result['financial_summary']['totals']['payments_cancellations']['amount_paise']);
    }

    public function test_repeated_voucher_numbers_require_distinct_corroborated_source_records(): void
    {
        $report = $this->report();
        $report['sources']['receipts'] = $report['sources']['payments'];
        foreach (['9' => 1122200, '10' => 20000000] as $id => $amount) {
            $report['receipts'][] = [
                'date' => '2026-05-29', 'voucher' => 'XVFC/2026-27/R/1', 'voucher_type' => 'Refund of Excess Payment', 'amount_paise' => $amount,
                'record_source' => [
                    'url' => 'https://egramswaraj.gov.in/receiptVoucherDetail.do?finYear=2026-2027&PRIEntity_code=220180&stateCode=29&voucherID='.$id,
                    'sha256' => str_repeat('a', 64), 'voucher' => 'XVFC/2026-27/R/1', 'date' => '2026-05-29', 'amount_paise' => $amount,
                ],
            ];
        }
        $this->save([$report]);
        $result = app(MyPlacePanchayat::class)->reports($this->context())[0];
        $this->assertCount(2, $result['receipts']);
        $this->assertSame(['9', '10'], array_column($result['receipts'], 'source_record_id'));
        $this->assertSame(21122200, $result['financial_summary']['totals']['receipts']['amount_paise']);
        foreach (['en', 'hi'] as $language) {
            $html = $this->renderReports([$result], $language);
            $this->assertSame(2, substr_count($html, 'XVFC/2026-27/R/1'));
            $this->assertStringContainsString('₹11,222.00', $html);
            $this->assertStringContainsString('₹200,000.00', $html);
        }
        foreach (['sha256' => 'missing', 'date' => '2026-05-28', 'amount_paise' => 1, 'voucher' => 'another voucher', 'url' => str_replace('stateCode=29', 'stateCode=30', $report['receipts'][1]['record_source']['url'])] as $field => $value) {
            $changed = $report;
            $changed['receipts'][1]['record_source'][$field] = $value;
            $this->save([$changed]);
            $this->assertSame([], app(MyPlacePanchayat::class)->reports($this->context())[0]['receipts'], $field);
        }
        $report['receipts'][1]['record_source']['url'] = $report['receipts'][0]['record_source']['url'];
        $this->save([$report]);
        $this->assertSame([], app(MyPlacePanchayat::class)->reports($this->context())[0]['receipts']);
    }

    private function renderReports(array $reports, string $language): string
    {
        return view('my-place-panchayat', ['hub' => ['panchayat_reports' => $reports], 'context' => $this->context(), 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
    }
}
