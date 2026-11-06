<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use App\Services\MyPlacePanchayatPaymentTotals;
use Tests\TestCase;

class MyPlacePaymentTotalsTest extends TestCase
{
    public function test_basti_preserves_missing_years_and_source_zeroes_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '131'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1187, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = match ($gp['code']) {
                '54527' => ['2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'],
                '267887', '54569' => ['2026-2027'],
                default => ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'],
            };
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(8296, $published);
        foreach (['267887', '54569'] as $code) {
            $this->assertSame(['0.00'], array_column($service->data($context, $code)['payment_totals'], 'source_amount'));
        }
        $signed = collect($service->data($context, '54352')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-2,12,066.00', $signed['source_amount']);
        $gp = collect($directory)->firstWhere('code', '54352');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '122']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_hapur_preserves_seven_year_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '661'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(273, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(1911, $published);
        $sample = collect($service->data($context, '63505')['payment_totals']);
        $this->assertSame('32,55,369.00', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('58,04,108.41', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $this->assertSame('33,66,693.00', $sample->firstWhere('year', '2025-2026')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '63505');
        $villageContext = array_replace($context, ['village' => ['code' => '119785']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '259728']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_shamli_preserves_seven_year_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '660'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(230, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(1610, $published);
        $sample = collect($service->data($context, '81253')['payment_totals']);
        $this->assertSame('18,90,942.00', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('19,77,202.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $this->assertSame('26,86,417.00', $sample->firstWhere('year', '2025-2026')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '81253');
        $villageContext = array_replace($context, ['village' => ['code' => '110563']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '259727']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_sambhal_preserves_missing_years_and_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '659'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(670, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            if (in_array($gp['code'], ['297273', '297150', '297329', '297364'], true)) {
                array_pop($expectedYears);
            }
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4686, $published);
        $sample = collect($service->data($context, '80155')['payment_totals']);
        $this->assertSame('20,12,892.00', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('10,40,903.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $this->assertSame('18,02,979.00', $sample->firstWhere('year', '2025-2026')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '80155');
        $villageContext = array_replace($context, ['village' => ['code' => '115667']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame('-,28,424.00', $sample->firstWhere('year', '2020-2021')['source_amount']);
        foreach (['297273', '297150', '297329', '297364'] as $code) {
            $missingYear = collect($service->data($context, $code)['payment_totals']);
            $this->assertCount(6, $missingYear);
            $this->assertNull($missingYear->firstWhere('year', '2020-2021'));
        }
        $duplicate = collect($service->data($context, '297187')['payment_totals'])->where('year', '2020-2021');
        $this->assertCount(1, $duplicate);
        $this->assertSame('0.00', $duplicate->first()['source_amount']);
        $manifest = json_decode(file_get_contents(database_path('fixtures/up-payment-totals.json')), true);
        $shard = json_decode(file_get_contents(database_path('fixtures/'.$manifest['district_shards']['659']['2971']['file'])), true);
        $original = collect($shard['records'])->first(fn ($row) => $row['panchayat_code'] === '297187' && $row['financial_year'] === '2020-2021');
        $this->assertCount(2, $original['duplicate_source_rows']);
        $this->assertSame(['0.00', '0.00'], array_column($original['duplicate_source_rows'], 'amount'));
        $wrong = array_replace($context, ['district' => ['code' => '259726']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_kasganj_preserves_missing_years_and_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '633'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(423, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            if ($gp['code'] === '59636') {
                array_shift($expectedYears);
            }
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(2960, $published);
        $sample = collect($service->data($context, '59605')['payment_totals']);
        $this->assertSame('19,66,571.00', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('16,68,182.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $this->assertSame('19,40,907.00', $sample->firstWhere('year', '2025-2026')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '59605');
        $villageContext = array_replace($context, ['village' => ['code' => '215592']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $missingYear = collect($service->data($context, '59636')['payment_totals']);
        $this->assertCount(6, $missingYear);
        $this->assertNull($missingYear->firstWhere('year', '2026-2027'));
        $wrong = array_replace($context, ['district' => ['code' => '577']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_amethi_preserves_missing_years_and_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '640'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(682, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            if ($gp['code'] === '91288') {
                array_shift($expectedYears);
            }
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4773, $published);
        $sample = collect($service->data($context, '90811')['payment_totals']);
        $this->assertSame('13,20,687.00', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('9,94,510.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $this->assertSame('11,06,308.00', $sample->firstWhere('year', '2025-2026')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '90811');
        $villageContext = array_replace($context, ['village' => ['code' => '169359']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $missingYear = collect($service->data($context, '91288')['payment_totals']);
        $this->assertCount(6, $missingYear);
        $this->assertNull($missingYear->firstWhere('year', '2026-2027'));
        $wrong = array_replace($context, ['district' => ['code' => '584']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_varanasi_preserves_missing_years_and_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '187'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(694, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            if ($gp['code'] === '265899') {
                array_shift($expectedYears);
            }
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4857, $published);
        $this->assertSame([], $service->data($context, '93141')['payment_totals']);
        $this->assertSame([], $service->data($context, '93154')['payment_totals']);
        $sample = collect($service->data($context, '92953')['payment_totals']);
        $this->assertSame('26,51,303.00', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('20,38,226.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $this->assertSame('26,35,907.36', $sample->firstWhere('year', '2025-2026')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '92953');
        $villageContext = array_replace($context, ['village' => ['code' => '209377']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $missingYear = collect($service->data($context, '265899')['payment_totals']);
        $this->assertCount(6, $missingYear);
        $this->assertNull($missingYear->firstWhere('year', '2026-2027'));
        $wrong = array_replace($context, ['district' => ['code' => '178']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_unnao_preserves_negative_source_totals_and_seven_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '186'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1037, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(7259, $published);
        $this->assertSame([], $service->data($context, '272240')['payment_totals']);
        $this->assertSame([], $service->data($context, '92887')['payment_totals']);
        $sample = collect($service->data($context, '92938')['payment_totals']);
        $this->assertSame('19,88,508.00', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('29,40,533.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $this->assertSame('-,84,477.00', $sample->firstWhere('year', '2020-2021')['source_amount']);
        $otherNegative = collect($service->data($context, '92930')['payment_totals']);
        $this->assertSame('-,40,579.00', $otherNegative->firstWhere('year', '2023-2024')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '92938');
        $villageContext = array_replace($context, ['village' => ['code' => '142836']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '177']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_sultanpur_preserves_missing_years_and_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '185'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(979, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            if (in_array($gp['code'], ['91068', '91563'], true)) {
                array_shift($expectedYears);
            }
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(6851, $published);
        $this->assertSame([], $service->data($context, '91693')['payment_totals']);
        $this->assertSame([], $service->data($context, '91703')['payment_totals']);
        $sample = collect($service->data($context, '90738')['payment_totals']);
        $this->assertSame('29,81,067.00', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('15,96,027.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '90738');
        $villageContext = array_replace($context, ['village' => ['code' => '171125']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '176']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_sonbhadra_preserves_seven_years_and_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '184'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(621, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4347, $published);
        $this->assertSame([], $service->data($context, '90637')['payment_totals']);
        $this->assertSame([], $service->data($context, '90647')['payment_totals']);
        $sample = collect($service->data($context, '90237')['payment_totals']);
        $this->assertSame('17,02,437.00', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('21,67,927.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '90237');
        $villageContext = array_replace($context, ['village' => ['code' => '214321']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '175']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_sitapur_preserves_seven_years_and_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '183'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1588, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(11116, $published);
        $this->assertSame([], $service->data($context, '88984')['payment_totals']);
        $this->assertSame([], $service->data($context, '271014')['payment_totals']);
        $sample = collect($service->data($context, '88908')['payment_totals']);
        $this->assertSame('43,51,841.50', $sample->firstWhere('year', '2023-2024')['source_amount']);
        $this->assertSame('30,58,196.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '88908');
        $villageContext = array_replace($context, ['village' => ['code' => '137474']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '174']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_siddharthnagar_preserves_seven_years_and_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '182'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1136, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(7952, $published);
        $this->assertSame([], $service->data($context, '269875')['payment_totals']);
        $this->assertSame([], $service->data($context, '274016')['payment_totals']);
        $sample = collect($service->data($context, '273850')['payment_totals']);
        $this->assertSame('7,95,608.00', $sample->firstWhere('year', '2022-2023')['source_amount']);
        $this->assertSame('7,12,578.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '273850');
        $villageContext = array_replace($context, ['village' => ['code' => '176935']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '173']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_shravasti_preserves_seven_years_and_source_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '181'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(397, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(2779, $published);
        $this->assertSame([], $service->data($context, '86819')['payment_totals']);
        $this->assertSame([], $service->data($context, '87110')['payment_totals']);
        $sample = collect($service->data($context, '87574')['payment_totals']);
        $this->assertSame('14,70,490.00', $sample->firstWhere('year', '2022-2023')['source_amount']);
        $this->assertSame('15,72,718.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '87574');
        $villageContext = array_replace($context, ['village' => ['code' => '173099']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '172']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_shahjahanpur_preserves_seven_years_and_fractional_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '180'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1068, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = in_array($gp['code'], ['86968', '87299', '87572'], true) ? ['2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'] : ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(7473, $published);
        $this->assertSame([], $service->data($context, '86819')['payment_totals']);
        $this->assertSame([], $service->data($context, '87110')['payment_totals']);
        $sample = collect($service->data($context, '86817')['payment_totals']);
        $this->assertSame('36,14,322.22', $sample->firstWhere('year', '2022-2023')['source_amount']);
        $this->assertSame('32,69,590.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '86817');
        $villageContext = array_replace($context, ['village' => ['code' => '132797']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        foreach (['86968' => '134324', '87299' => '134671', '87572' => '133742'] as $missingCode => $villageCode) {
            $missingContext = array_replace($context, ['village' => ['code' => $villageCode]]);
            $missing = $service->data($missingContext, (string) $missingCode)['payment_totals'];
            $this->assertCount(6, $missing);
            $this->assertNotContains('2026-2027', array_column($missing, 'year'));
        }
        $wrong = array_replace($context, ['district' => ['code' => '171']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_bhadohi_preserves_seven_years_and_fractional_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '179'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(546, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = $gp['code'] === '297165' ? ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022'] : ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3821, $published);
        $this->assertSame([], $service->data($context, '86438')['payment_totals']);
        $this->assertSame([], $service->data($context, '86460')['payment_totals']);
        $sample = collect($service->data($context, '86365')['payment_totals']);
        $this->assertSame('15,34,961.60', $sample->firstWhere('year', '2020-2021')['source_amount']);
        $this->assertSame('17,38,817.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '86365');
        $villageContext = array_replace($context, ['village' => ['code' => '210908']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $missingContext = array_replace($context, ['village' => ['code' => '209999']]);
        $missing = $service->data($missingContext, '297165')['payment_totals'];
        $this->assertCount(6, $missing);
        $this->assertNotContains('2020-2021', array_column($missing, 'year'));
        $wrong = array_replace($context, ['district' => ['code' => '170']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_sant_kabir_nagar_preserves_seven_years_and_negative_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '178'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(730, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(5110, $published);
        $this->assertSame([], $service->data($context, '85679')['payment_totals']);
        $this->assertSame([], $service->data($context, '86181')['payment_totals']);
        $sample = collect($service->data($context, '86085')['payment_totals']);
        $this->assertSame('-6,81,363.00', $sample->firstWhere('year', '2020-2021')['source_amount']);
        $this->assertSame('39,99,359.00', $sample->firstWhere('year', '2021-2022')['source_amount']);
        foreach (['en', 'hi'] as $language) {
            $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => [$sample->firstWhere('year', '2020-2021')]], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
            $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
        }
        $gp = collect($directory)->firstWhere('code', '86085');
        $villageContext = array_replace($context, ['village' => ['code' => '183019']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '169']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_saharanpur_preserves_seven_years_and_fractional_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '177'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(884, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(6188, $published);
        $this->assertSame([], $service->data($context, '85173')['payment_totals']);
        $this->assertSame([], $service->data($context, '85181')['payment_totals']);
        $sample = collect($service->data($context, '85233')['payment_totals']);
        $this->assertSame('13,69,841.10', $sample->firstWhere('year', '2020-2021')['source_amount']);
        $this->assertSame('13,17,644.47', $sample->firstWhere('year', '2021-2022')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '85233');
        $villageContext = array_replace($context, ['village' => ['code' => '110090']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '168']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_rampur_preserves_seven_years_and_fractional_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '176'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(680, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4760, $published);
        $this->assertSame([], $service->data($context, '84817')['payment_totals']);
        $this->assertSame([], $service->data($context, '84848')['payment_totals']);
        $sample = collect($service->data($context, '84612')['payment_totals']);
        $this->assertSame('29,43,568.25', $sample->firstWhere('year', '2020-2021')['source_amount']);
        $this->assertSame('29,24,745.00', $sample->firstWhere('year', '2025-2026')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '84612');
        $villageContext = array_replace($context, ['village' => ['code' => '117299']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '167']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_rae_bareli_preserves_seven_years_and_fractional_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '175'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(980, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(6860, $published);
        $this->assertSame([], $service->data($context, '271814')['payment_totals']);
        $this->assertSame([], $service->data($context, '84069')['payment_totals']);
        $sample = collect($service->data($context, '83464')['payment_totals']);
        $this->assertSame('24,79,820.60', $sample->firstWhere('year', '2020-2021')['source_amount']);
        $this->assertSame('40,98,837.40', $sample->firstWhere('year', '2025-2026')['source_amount']);
        $gp = collect($directory)->firstWhere('code', '83464');
        $villageContext = array_replace($context, ['village' => ['code' => '143823']]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '166']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_pratapgarh_preserves_missing_years_and_negative_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '174'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1148, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = $gp['code'] === '83403'
                ? ['2024-2025', '2023-2024', '2021-2022', '2020-2021']
                : ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(8033, $published);
        $this->assertSame([], $service->data($context, '82334')['payment_totals']);
        $historic = collect($service->data($context, '269035')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-,66,54,457.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '269035');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '165']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_muzaffarnagar_preserves_missing_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '172'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(487, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = $gp['code'] === '81546'
                ? ['2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021']
                : ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3408, $published);
        $this->assertSame([], $service->data($context, '268306')['payment_totals']);
        $historic = collect($service->data($context, '81048')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('13,51,655.52', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '81048');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '163']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_kaushambi_preserves_missing_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '158'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(451, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = $gp['code'] === '299393'
                ? ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022']
                : ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3156, $published);
        $this->assertSame([], $service->data($context, '72630')['payment_totals']);
        $historic = collect($service->data($context, '72627')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('24,12,821.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '72627');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '149']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_kanpur_dehat_preserves_missing_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '156'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(618, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = $gp['code'] === '298795'
                ? ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022']
                : ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4325, $published);
        $this->assertSame([], $service->data($context, '71558')['payment_totals']);
        $historic = collect($service->data($context, '71499')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('5,91,884.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '71499');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '147']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_moradabad_preserves_missing_years_and_negative_totals_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '171'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(643, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = in_array($gp['code'], ['297207', '297211', '297213', '297216', '297218', '297220', '297222', '297224', '297227', '297247', '297263', '297265', '297271', '297274', '297280', '297284', '297286', '297289', '297290', '297294', '297295', '297296', '297299', '297301', '297308', '297309', '297312', '297315', '297320', '297331', '297333', '297337', '297340', '297342', '297346', '297348', '297352', '297355', '297357', '297360', '297362', '297365', '297368', '297370', '297374', '297375', '297494'], true)
                ? ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022']
                : ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4454, $published);
        $historic = collect($service->data($context, '80509')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('15,83,167.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '80509');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        foreach (['80605', '80664', '80669'] as $unassignedCode) {
            $this->assertSame([], $service->data($context, $unassignedCode)['payment_totals']);
        }
        $signed = collect($service->data($context, '80509')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-1,12,30,865.00', $signed['source_amount']);
        $wrong = array_replace($context, ['district' => ['code' => '162']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_mirzapur_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '170'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(809, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(5663, $published);
        $historic = collect($service->data($context, '79404')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('26,65,401.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '79404');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame([], $service->data($context, '77053')['payment_totals']);
        $fractional = collect($service->data($context, '79404')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('58,53,937.55', $fractional['source_amount']);
        $wrong = array_replace($context, ['district' => ['code' => '161']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_meerut_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '169'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(479, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3353, $published);
        $historic = collect($service->data($context, '78877')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('7,42,818.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '78877');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame([], $service->data($context, '77053')['payment_totals']);
        $fractional = collect($service->data($context, '78877')['payment_totals'])->firstWhere('year', '2021-2022');
        $this->assertSame('15,36,178.50', $fractional['source_amount']);
        $wrong = array_replace($context, ['district' => ['code' => '160']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_mainpuri_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '166'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(549, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3843, $published);
        $historic = collect($service->data($context, '77300')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('14,09,538.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '77300');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame([], $service->data($context, '77053')['payment_totals']);
        foreach (['77306', '270119', '77337'] as $unassignedCode) {
            $this->assertSame([], $service->data($context, $unassignedCode)['payment_totals']);
        }
        $fractional = collect($service->data($context, '77300')['payment_totals'])->firstWhere('year', '2022-2023');
        $this->assertSame('6,26,607.97', $fractional['source_amount']);
        $wrong = array_replace($context, ['district' => ['code' => '157']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_mau_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '168'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(645, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4515, $published);
        $historic = collect($service->data($context, '270597')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('1,74,609.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '270597');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame([], $service->data($context, '77053')['payment_totals']);
        foreach (['78281', '78297', '78305'] as $unassignedCode) {
            $this->assertSame([], $service->data($context, $unassignedCode)['payment_totals']);
        }
        $fractional = collect($service->data($context, '270597')['payment_totals'])->firstWhere('year', '2023-2024');
        $this->assertSame('-4,48,133.44', $fractional['source_amount']);
        $wrong = array_replace($context, ['district' => ['code' => '159']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_mathura_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '167'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(495, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            if (in_array($gp['code'], ['77804', '77824', '266419', '78166', '266720'], true)) {
                array_shift($expectedYears);
            }
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3460, $published);
        $historic = collect($service->data($context, '42859')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('17,57,325.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '42859');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame([], $service->data($context, '77053')['payment_totals']);
        foreach (['77889', '77913', '266461', '78136', '78137', '78139', '266465', '78158', '78161'] as $unassignedCode) {
            $this->assertSame([], $service->data($context, $unassignedCode)['payment_totals']);
        }
        $fractional = collect($service->data($context, '77818')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('28,20,127.05', $fractional['source_amount']);
        foreach (['77804' => '124115', '77824' => '124130', '266419' => '124434', '78166' => '123916', '266720' => '124063'] as $missingCode => $villageCode) {
            $missingContext = array_replace($context, ['village' => ['code' => $villageCode]]);
            $missingValues = $service->data($missingContext, (string) $missingCode)['payment_totals'];
            $this->assertCount(6, $missingValues);
            $this->assertNotContains('2026-2027', array_column($missingValues, 'year'));
        }
        $wrong = array_replace($context, ['district' => ['code' => '158']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_mahoba_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '165'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(273, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(1911, $published);
        $historic = collect($service->data($context, '77053')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('12,49,836.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '77053');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame([], $service->data($context, '76276')['payment_totals']);
        $wrong = array_replace($context, ['district' => ['code' => '156']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_mahrajganj_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '164'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(882, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(6174, $published);
        $historic = collect($service->data($context, '76276')['payment_totals'])->firstWhere('year', '2022-2023');
        $this->assertSame('27,42,763.68', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '76276');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame([], $service->data($context, '268605')['payment_totals']);
        $wrong = array_replace($context, ['district' => ['code' => '155']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_hathras_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '163'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(462, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3234, $published);
        $historic = collect($service->data($context, '76017')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-3,29,934.05', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '76017');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame([], $service->data($context, '76220')['payment_totals']);
        $wrong = array_replace($context, ['district' => ['code' => '154']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_lucknow_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '162'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(491, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3437, $published);
        $historic = collect($service->data($context, '75608')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-1,25,43,168.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '75608');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $this->assertSame([], $service->data($context, '265335')['payment_totals']);
        $wrong = array_replace($context, ['district' => ['code' => '153']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_lalitpur_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '161'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(415, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(2905, $published);
        $historic = collect($service->data($context, '271310')['payment_totals'])->firstWhere('year', '2023-2024');
        $this->assertSame('-,95,955.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '271310');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '152']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_kushinagar_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '160'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(980, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(6860, $published);
        $this->assertSame([], $service->data($context, '74078')['payment_totals']);
        $historic = collect($service->data($context, '74420')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-,64,77,418.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '74420');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '151']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_kheri_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '159'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1164, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(8148, $published);
        $this->assertSame([], $service->data($context, '73190')['payment_totals']);
        $historic = collect($service->data($context, '73067')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('15,71,433.53', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '73067');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '150']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_kanpur_nagar_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '157'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(590, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4130, $published);
        $this->assertSame([], $service->data($context, '268976')['payment_totals']);
        $historic = collect($service->data($context, '72078')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('16,31,944.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '72078');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '148']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_kannauj_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '155'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(499, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3493, $published);
        $this->assertSame([], $service->data($context, '268976')['payment_totals']);
        $historic = collect($service->data($context, '71084')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('14,55,689.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '71084');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '146']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_amroha_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '154'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(576, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4032, $published);
        $this->assertSame([], $service->data($context, '238570')['payment_totals']);
        $historic = collect($service->data($context, '238571')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('7,47,614.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '238571');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '145']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_jhansi_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '153'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(496, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3472, $published);
        $this->assertSame([], $service->data($context, '70049')['payment_totals']);
        $historic = collect($service->data($context, '70648')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('20,14,188.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '70648');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '144']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_jaunpur_preserves_source_year_absences_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '152'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1734, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = match ($gp['code']) {
                '299245', '299261', '299246', '299260' => ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022'],
                default => ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'],
            };
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(12134, $published);
        $this->assertSame([], $service->data($context, '70049')['payment_totals']);
        $historic = collect($service->data($context, '69134')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('2,26,975.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '69134');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '143']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_jalaun_preserves_source_year_absences_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '151'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(574, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = match ($gp['code']) {
                '69065' => ['2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'],
                default => ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'],
            };
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4017, $published);
        $this->assertSame([], $service->data($context, '68388')['payment_totals']);
        $historic = collect($service->data($context, '68570')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('8,20,149.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '68570');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '142']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_hardoi_preserves_source_year_absences_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '150'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1293, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = match ($gp['code']) {
                '67789' => ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2021-2022', '2020-2021'],
                '67811' => ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2020-2021'],
                default => ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'],
            };
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(9048, $published);
        $this->assertSame([], $service->data($context, '272361')['payment_totals']);
        $historic = collect($service->data($context, '67469')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('17,44,776.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '67469');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '141']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_hamirpur_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '149'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(330, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(2310, $published);
        $this->assertSame([], $service->data($context, '66798')['payment_totals']);
        $historic = collect($service->data($context, '67155')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('20,60,859.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '67155');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '140']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_gorakhpur_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '148'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1273, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(8911, $published);
        $this->assertSame([], $service->data($context, '66798')['payment_totals']);
        $historic = collect($service->data($context, '271723')['payment_totals'])->firstWhere('year', '2023-2024');
        $this->assertSame('-,10,90,886.00', $historic['source_amount']);
        $gp = collect($directory)->firstWhere('code', '271723');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '139']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_deoria_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '137'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1121, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(7847, $published);
        $this->assertSame([], $service->data($context, '58554')['payment_totals']);
        $latest = collect($service->data($context, '271045')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('3,79,883.39', $latest['source_amount']);
        $historic = collect($service->data($context, '271045')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-2,37,30,832.00', $historic['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '128']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_gonda_preserves_86_missing_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '147'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1192, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $years = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $years = array_values(array_filter($years, fn ($year) => match ($gp['code']) {
                '65564' => $year !== '2026-2027',
                '297472', '297387', '297389', '297380', '297398', '297392', '297401', '297404', '297269', '297270', '297264', '297166', '297188', '297161', '297490', '297171', '297164', '297190', '297201', '297192', '297170', '297172', '297418', '297421', '297417', '297493', '297422', '297416', '297414', '297415', '297423', '297420', '297419', '297469', '297468', '297465', '297457', '297464', '297467', '297455', '297456', '297466', '297463', '297462', '297458', '297461', '297283', '297292', '297288', '297287', '297300', '297449', '297448', '297445', '297447', '297446', '297322', '297325', '297305', '297314', '297319', '297317', '297413', '297409', '297410', '297408', '297359', '297491', '297378', '297369', '297366', '297344', '297353', '297372', '297377', '297429', '297430', '297435', '297433', '297441', '297436', '297431', '297434', '297440', '297438' => $year !== '2020-2021',
                default => true,
            }));
            $this->assertSame($years, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($years), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(8258, $published);
        $this->assertSame([], $service->data($context, '64942')['payment_totals']);
        $latest = collect($service->data($context, '297480')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('11,24,997.00', $latest['source_amount']);
        $gp = collect($directory)->firstWhere('code', '297480');
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '138']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_ghazipur_preserves_three_missing_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '146'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1238, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $years = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $years = array_values(array_filter($years, fn ($year) => match ($gp['code']) {
                '63920', '64863' => $year !== '2026-2027',
                '297146' => $year !== '2020-2021',
                default => true,
            }));
            $this->assertSame($years, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($years), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(8663, $published);
        $this->assertSame([], $service->data($context, '239121')['payment_totals']);
        $latest = collect($service->data($context, '63830')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('13,29,909.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '137']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_ghaziabad_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '145'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(142, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $years = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($years, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($years), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(994, $published);
        $this->assertSame([], $service->data($context, '239121')['payment_totals']);
        $latest = collect($service->data($context, '63456')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('7,95,042.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '136']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_gautam_buddha_nagar_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '144'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(82, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $years = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($years, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($years), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(574, $published);
        $this->assertSame([], $service->data($context, '274241')['payment_totals']);
        $latest = collect($service->data($context, '63233')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('8,30,740.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '135']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_firozabad_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '143'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(564, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $years = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($years, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($years), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3948, $published);
        $this->assertSame([], $service->data($context, '62907')['payment_totals']);
        $latest = collect($service->data($context, '264853')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('4,33,545.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '134']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_fatehpur_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '142'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(816, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $years = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            if (in_array($gp['code'], ['62247', '62479'], true)) {
                array_shift($years);
            }
            $this->assertSame($years, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($years), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(5710, $published);
        $this->assertSame([], $service->data($context, '61960')['payment_totals']);
        $latest = collect($service->data($context, '61944')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('16,08,179.00', $latest['source_amount']);
        foreach (['62247', '62479'] as $code) {
            $this->assertCount(6, $service->data($context, $code)['payment_totals']);
            $this->assertNull(collect($service->data($context, $code)['payment_totals'])->firstWhere('year', '2026-2027'));
        }
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '133']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_etah_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '138'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(569, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $years = ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            if (in_array($gp['code'], ['60173', '60214'], true)) {
                array_shift($years);
            }
            $this->assertSame($years, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($years), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3981, $published);
        $this->assertSame([], $service->data($context, '59952')['payment_totals']);
        $latest = collect($service->data($context, '59520')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('9,10,856.00', $latest['source_amount']);
        foreach (['60173', '60214'] as $code) {
            $this->assertCount(6, $service->data($context, $code)['payment_totals']);
            $this->assertNull(collect($service->data($context, $code)['payment_totals'])->firstWhere('year', '2026-2027'));
        }
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '129']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_farrukhabad_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '141'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(580, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                    if (str_starts_with($value['source_amount'], '-')) {
                        $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
                    }
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4060, $published);
        $this->assertSame([], $service->data($context, '61532')['payment_totals']);
        $latest = collect($service->data($context, '267523')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('5,60,450.00', $latest['source_amount']);
        $historic = collect($service->data($context, '267523')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-1,68,65,129.00', $historic['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '132']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_ayodhya_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '140'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(772, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(5404, $published);
        $this->assertSame([], $service->data($context, '60839')['payment_totals']);
        $latest = collect($service->data($context, '60838')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('9,74,131.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '131']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_etawah_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '139'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(469, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3283, $published);
        $this->assertSame([], $service->data($context, '60458')['payment_totals']);
        $latest = collect($service->data($context, '60418')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('14,05,417.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '130']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_chitrakoot_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '136'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(328, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(2296, $published);
        $this->assertSame([], $service->data($context, '266660')['payment_totals']);
        $latest = collect($service->data($context, '58222')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('26,54,670.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '127']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_chandauli_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '135'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(734, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(5138, $published);
        $latest = collect($service->data($context, '57602')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('14,12,618.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '126']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_bulandshahr_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '134'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(946, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(6622, $published);
        $this->assertSame([], $service->data($context, '259789')['payment_totals']);
        $latest = collect($service->data($context, '57105')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('17,50,886.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '125']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_bijnor_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '132'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1123, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(7861, $published);
        $this->assertSame([], $service->data($context, '54981')['payment_totals']);
        $latest = collect($service->data($context, '54753')['payment_totals'])->firstWhere('year', '2026-2027');
        $this->assertSame('9,14,178.00', $latest['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '123']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_budaun_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '133'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1037, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(7259, $published);
        $this->assertSame([], $service->data($context, '55876')['payment_totals']);
        $signed = collect($service->data($context, '55804')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-1,98,91,351.00', $signed['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '124']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_bareilly_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '130'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1188, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(8316, $published);
        $this->assertSame([], $service->data($context, '268109')['payment_totals']);
        $signed = collect($service->data($context, '53473')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-1,80,13,960.00', $signed['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '121']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_barabanki_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '129'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1155, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(8085, $published);
        $this->assertSame([], $service->data($context, '51570')['payment_totals']);
        $signed = collect($service->data($context, '51594')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-2,84,388.00', $signed['source_amount']);
        $signedSecond = collect($service->data($context, '52449')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-2,44,154.00', $signedSecond['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '120']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_banda_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '128'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(469, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3283, $published);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '119']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_balrampur_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '127'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(793, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(5551, $published);
        $this->assertSame([], $service->data($context, '50631')['payment_totals']);
        $signed = collect($service->data($context, '50693')['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-8,60,725.00', $signed['source_amount']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '118']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_ballia_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '126'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(940, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(6580, $published);
        $this->assertSame([], $service->data($context, '49912')['payment_totals']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '117']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_bahraich_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '125'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1043, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expectedYears = in_array($gp['code'], ['302484', '302485'], true) ? [] : ['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'];
            $this->assertSame($expectedYears, array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(count($expectedYears), substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(7287, $published);
        $this->assertSame([], $service->data($context, '49175')['payment_totals']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '116']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_baghpat_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '124'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(244, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(1708, $published);
        $this->assertSame([], $service->data($context, '48680')['payment_totals']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '115']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_azamgarh_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '123'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1810, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(12670, $published);
        $this->assertSame([], $service->data($context, '47538')['payment_totals']);
        $this->assertSame([], $service->data($context, '47056')['payment_totals']);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '114']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_auraiya_has_seven_source_years_without_exposing_deferred_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '122'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(477, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(3339, $published);
        $gp = $directory[0];
        $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
        $this->assertCount(7, $service->data($villageContext, $gp['code'])['payment_totals']);
        $this->assertSame([], $service->data($villageContext, $gp['code'])['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '113']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
    }

    public function test_ambedkar_nagar_preserves_signed_totals_and_excludes_unmapped_codes(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '121'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(899, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertCount(7, $values, $gp['code']);
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame(7, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(6293, $published);
        $data = $service->data($context, '269196');
        $negative = collect($data['payment_totals'])->firstWhere('year', '2020-2021');
        $this->assertSame('-,10,46,194.00', $negative['source_amount']);
        $this->assertSame([], $data['allocations']);
        foreach (['en', 'hi'] as $language) {
            $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => [$negative]], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
            $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
        }
        $this->assertSame([], $service->data($context, '271641')['payment_totals']);
        $wrong = array_replace($context, ['district' => ['code' => '112']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $directory[0]));
    }

    public function test_prayagraj_preserves_missing_year_and_rejects_unmapped_historical_codes(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '120'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(1540, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expected = $gp['code'] === '45096' ? 6 : 7;
            $this->assertCount($expected, $values, $gp['code']);
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame($expected, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(10779, $published);
        $missing = $service->data($context, '45096');
        $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2020-2021'], array_column($missing['payment_totals'], 'year'));
        $this->assertSame([], $missing['allocations']);
        $this->assertSame([], $service->data($context, '44189')['payment_totals']);
        $wrong = array_replace($context, ['district' => ['code' => '111']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $directory[0]));
        $wrongVillage = array_replace($context, ['village' => ['code' => '122664']]);
        $this->assertSame([], $service->data($wrongVillage, '45096')['payment_totals']);
    }

    public function test_aligarh_preserves_year_gaps_and_excludes_unassigned_historical_codes(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '119'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(852, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expected = $gp['code'] === '44184' ? 5 : 7;
            $this->assertCount($expected, $values, $gp['code']);
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => $values], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame($expected, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertNull($value['unit']);
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(5962, $published);
        $missing = $service->data($context, '44184')['payment_totals'];
        $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2020-2021'], array_column($missing, 'year'));
        $this->assertSame([], $service->data($context, '43591')['payment_totals']);
        $negative = collect($service->data($context, '43924')['payment_totals'])->firstWhere('year', '2021-2022');
        $this->assertSame('-,48,680.00', $negative['source_amount']);
        $this->assertSame([], $service->data($context, '43924')['allocations']);
        $wrong = array_replace($context, ['district' => ['code' => '110']]);
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $directory[0]));
    }

    public function test_agra_totals_use_exact_civil_district_and_village_links_without_enabling_allocations(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '118'], 'village' => null];
        $service = app(MyPlacePanchayat::class);
        $directory = $service->data($context)['directory'];
        $this->assertCount(690, $directory);
        $published = 0;
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $expected = $gp['code'] === '42733' ? 6 : 7;
            $this->assertCount($expected, $values, $gp['code']);
            $this->assertSame($gp['code'] === '42733' ? '2025-2026' : '2026-2027', $values[0]['year']);
            $this->assertNull($values[0]['unit']);
            $villageContext = array_replace($context, ['village' => ['code' => $gp['villages'][0]['code']]]);
            $data = $service->data($villageContext, $gp['code']);
            $this->assertSame($values, $data['payment_totals']);
            $this->assertSame([], $data['allocations']);
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => $data, 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertSame($expected, substr_count($html, 'data-payment-total='));
                foreach ($values as $value) {
                    $this->assertStringContainsString($value['source_amount'], $html);
                }
                $this->assertStringNotContainsString('₹', $html);
            }
            $published += count($values);
        }
        $this->assertSame(4829, $published);
        $gp = $directory[0];
        foreach (['109', '173'] as $wrongDistrict) {
            $wrong = array_replace($context, ['district' => ['code' => $wrongDistrict]]);
            $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($wrong, $gp));
        }
        $context['village'] = ['code' => '999999'];
        $this->assertSame([], $service->data($context, $gp['code'])['payment_totals']);
    }

    public function test_original_negative_comma_format_is_preserved_for_agra(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '118'], 'village' => null];
        $totals = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, ['namespace' => 'LGD Gram Panchayat', 'code' => '43228']);
        $row = collect($totals)->firstWhere('year', '2022-2023');
        $this->assertSame('-,73,493.00', $row['source_amount']);
        foreach (['en', 'hi'] as $language) {
            $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => [$row]], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
            $this->assertStringContainsString('-,73,493.00', $html);
            $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
        }
    }

    public function test_tampered_or_unsafe_district_directories_are_not_used(): void
    {
        $manifest = json_decode(file_get_contents(database_path('fixtures/up-panchayat-directories.json')), true);
        $path = tempnam(database_path('fixtures'), 'directory-index-test-');
        config(['services.egramswaraj.directory_manifest_path' => $path]);
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '118'], 'village' => null];
        try {
            foreach (['../pilibhit-lgd.json', 'up-panchayat-directory-9-173.json', 'up-panchayat-directory-9-118.json'] as $file) {
                $manifest['districts']['118'] = ['file' => $file, 'sha256' => str_repeat('0', 64)];
                file_put_contents($path, json_encode($manifest));
                $this->assertSame([], app(MyPlacePanchayat::class)->data($context)['directory']);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_all_official_gp_codes_have_a_summary_without_changing_vouchers(): void
    {
        $service = app(MyPlacePanchayat::class);
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => null];
        $before = $service->reports($context);
        $directory = $service->data($context)['directory'];
        $this->assertCount(720, $directory);
        foreach ($directory as $gp) {
            $values = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $gp);
            $this->assertCount(7, $values, $gp['code']);
            $this->assertSame(['2026-2027', '2025-2026', '2024-2025', '2023-2024', '2022-2023', '2021-2022', '2020-2021'], array_column($values, 'year'));
            $this->assertNull($values[0]['unit']);
            $this->assertNull($values[1]['unit']);
        }
        $this->assertSame($before, $service->reports($context));
        $this->assertSame('17,12,839.00', $service->data($context, '81720')['payment_totals'][0]['source_amount']);
        $context['village'] = ['code' => '999999'];
        $this->assertSame([], $service->data($context, '81720')['payment_totals']);
        $context['village'] = null;
        $context['district']['code'] = '164';
        $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $directory[0]));
    }

    public function test_bilingual_card_preserves_unknown_units_and_source_scope(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => null];
        $data = app(MyPlacePanchayat::class)->data($context, '81720');
        foreach (['en', 'hi'] as $language) {
            $html = view('my-place-panchayat-payment-totals', ['panchayat' => $data, 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
            $this->assertStringContainsString('17,12,839.00', $html);
            $this->assertStringContainsString('74,91,263.00', $html);
            $this->assertStringContainsString('81720|2026-2027', $html);
            $this->assertStringContainsString('81720|2025-2026', $html);
            $this->assertSame(7, substr_count($html, 'data-payment-total='));
            $this->assertStringContainsString($language === 'en' ? 'Source unit unconfirmed' : 'स्रोत की इकाई अपुष्ट', $html);
            $this->assertStringContainsString($language === 'en' ? 'not independently verified' : 'स्वतंत्र रूप से सत्यापित नहीं', $html);
            $this->assertStringNotContainsString('₹', $html);
            $this->assertStringNotContainsString('Recorded receipts', $html);
        }
    }

    public function test_conflicting_years_and_untrusted_sources_are_excluded(): void
    {
        $package = json_decode(file_get_contents(database_path('fixtures/up-payment-totals.json')), true);
        $package = json_decode(file_get_contents(database_path('fixtures/'.$package['shards']['8172']['file'])), true);
        $row = collect($package['records'])->firstWhere('panchayat_code', '81720');
        $path = tempnam(sys_get_temp_dir(), 'payment-totals-');
        config(['services.egramswaraj.payment_totals_path' => $path]);
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => null];
        $selected = ['namespace' => 'LGD Gram Panchayat', 'code' => '81720'];
        try {
            $package['records'] = [$row, $row];
            file_put_contents($path, json_encode($package));
            $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $selected));
            $row['official_detail_url'] = 'https://example.org/';
            $package['records'] = [$row];
            file_put_contents($path, json_encode($package));
            $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $selected));
        } finally {
            unlink($path);
        }
    }

    public function test_signed_historical_totals_and_report_dates_are_preserved(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => null];
        foreach (['81960' => '-4,31,676.00', '82219' => '-3,57,28,087.00'] as $code => $amount) {
            $totals = app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, ['namespace' => 'LGD Gram Panchayat', 'code' => (string) $code]);
            $row = collect($totals)->firstWhere('year', '2020-2021');
            $this->assertSame($amount, $row['source_amount']);
            $this->assertStringContainsString('July 18, 2022', $row['source_generated_text']);
            foreach (['en', 'hi'] as $language) {
                $html = view('my-place-panchayat-payment-totals', ['panchayat' => ['payment_totals' => [$row]], 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
                $this->assertStringContainsString($amount, $html);
                $this->assertStringContainsString('July 18, 2022', $html);
                $this->assertStringContainsString($language === 'en' ? 'Negative amount as reported' : 'स्रोत में बताई गई ऋणात्मक राशि', $html);
            }
        }
    }

    public function test_missing_tampered_and_traversal_shards_are_rejected(): void
    {
        $package = json_decode(file_get_contents(database_path('fixtures/up-payment-totals.json')), true);
        $path = tempnam(database_path('fixtures'), 'payment-index-test-');
        config(['services.egramswaraj.payment_totals_path' => $path]);
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => null];
        $selected = ['namespace' => 'LGD Gram Panchayat', 'code' => '81720'];
        try {
            foreach ([['file' => '../outside.json', 'sha256' => str_repeat('0', 64)], ['file' => $package['shards']['8172']['file'], 'sha256' => str_repeat('0', 64)], ['file' => 'up-payment-totals-9-173-0000.json', 'sha256' => str_repeat('0', 64)]] as $invalid) {
                $package['shards']['8172'] = $invalid;
                file_put_contents($path, json_encode($package));
                $this->assertSame([], app(MyPlacePanchayatPaymentTotals::class)->forSelection($context, $selected));
            }
        } finally {
            unlink($path);
        }
    }
}
