<?php

namespace Tests\Feature;

use App\Services\GramPanchayatDirectory;
use App\Services\MyPlaceDirectory;
use App\Services\RuralEmployment;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class RuralEmploymentTest extends TestCase
{
    private string $path;

    private array $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 12:00:00');
        $this->package = json_decode(file_get_contents(database_path('fixtures/pilibhit-rural-employment.json')), true);
        $this->path = tempnam(sys_get_temp_dir(), 'rural-employment-test-');
        config(['services.rural_employment.path' => $this->path]);
        $this->save();
        $directory = Mockery::mock(MyPlaceDirectory::class);
        $directory->shouldReceive('states')->andReturn([['code' => '9', 'name' => 'Uttar Pradesh']]);
        $this->app->instance(MyPlaceDirectory::class, $directory);
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

    private function index(): int
    {
        return array_search('81725', array_column($this->package['reports'], 'gp'), true);
    }

    public function test_pilot_keeps_source_units_and_reconciled_work_totals(): void
    {
        $row = app(RuralEmployment::class)->forPanchayat(9, 81725);
        $this->assertSame('VB-G RAM G', $row['scheme']);
        $this->assertSame('3.04', $row['finance']['unskilled_wage_expenditure']);
        $this->assertSame('0.09', $row['finance']['unskilled_wage_due']);
        $this->assertCount(6, $row['works']);
        $this->assertSame(30420000, array_sum(array_column($row['works'], 'amount_paise')));
        $this->assertSame(32, array_sum(array_map(fn ($work) => count($work['musters']), $row['works'])));
        $balliya = app(RuralEmployment::class)->forPanchayat(9, 81728);
        $this->assertSame(175320000, array_sum(array_column($balliya['works'], 'amount_paise')));
        $this->assertSame(86, array_sum(array_map(fn ($work) => count($work['musters']), $balliya['works'])));
        $this->assertNull(app(RuralEmployment::class)->forPanchayat(29, 81725));
        $this->assertNull(app(RuralEmployment::class)->forPanchayat(9, 999999));
    }

    public function test_village_link_requires_unique_directory_membership_and_snapshot_membership(): void
    {
        $gp = Mockery::mock(GramPanchayatDirectory::class);
        $member = (int) $this->package['reports'][$this->index()]['villages'][0];
        $gp->shouldReceive('forVillage')->with(9, $member)->andReturn(['gp' => 81725]);
        $gp->shouldReceive('forVillage')->with(9, 999999)->andReturn(['gp' => 81725]);
        $gp->shouldReceive('forVillage')->with(9, 999998)->andReturn(null);
        $this->app->instance(GramPanchayatDirectory::class, $gp);
        $service = app(RuralEmployment::class);
        $this->assertSame('81725', $service->forVillage(9, $member)['gp']);
        $this->assertNull($service->forVillage(9, 999999));
        $this->assertNull($service->forVillage(9, 999998));
        $this->assertNull($service->forVillage(29, $member));
    }

    public function test_duplicate_unverified_and_future_source_records_are_not_published(): void
    {
        $original = $this->package;
        foreach (['duplicate', 'unverified', 'future', 'bad_year', 'bad_amount', 'bad_source'] as $case) {
            $this->package = $original;
            $i = $this->index();
            if ($case === 'duplicate') {
                $this->package['reports'][] = $this->package['reports'][$i];
            } elseif ($case === 'unverified') {
                $this->package['reports'][$i]['mapping']['verified'] = false;
            } elseif ($case === 'future') {
                $this->package['reports'][$i]['captured_on'] = '2026-11-15';
            } elseif ($case === 'bad_year') {
                $this->package['reports'][$i]['financial_year'] = '2026-2028';
            } elseif ($case === 'bad_amount') {
                $this->package['reports'][$i]['finance']['total_due'] = '-0.1';
            } else {
                $this->package['reports'][$i]['finance_source'] = 'https://vbgramgrep.dord.gov.in.evil.test/report';
            }
            $this->save();
            $this->assertNull(app(RuralEmployment::class)->forPanchayat(9, 81725), $case);
        }
    }

    public function test_muster_totals_and_dates_cannot_claim_unverified_payments(): void
    {
        $i = $this->index();
        $original = $this->package;
        $this->package['reports'][$i]['works'][0]['musters'][0]['amount_paise']++;
        $this->save();
        $this->assertNull(app(RuralEmployment::class)->forPanchayat(9, 81725));
        $this->package = $original;
        $this->package['reports'][$i]['works'][0]['musters'][0]['reported_payment_date'] = '2026-11-16';
        $this->save();
        $this->assertNull(app(RuralEmployment::class)->forPanchayat(9, 81725));
    }

    public function test_public_view_does_not_publish_personal_identifiers_or_hide_large_tables(): void
    {
        $employment = app(RuralEmployment::class)->forPanchayat(9, 81728);
        $html = view('rural-employment', ['employment' => $employment, 'hi' => false])->render();
        $this->assertStringContainsString('Reported payment date', $html);
        $this->assertStringContainsString('Reported amount (₹ lakh)', $html);
        $this->assertStringContainsString('data-rural-paginate', $html);
        $this->assertStringContainsString('not independent confirmation of bank credit', $html);
        $this->assertStringContainsString('Gram Panchayat', $html);
        $this->assertStringContainsString(RuralEmployment::WORKER_LOOKUP, $html);
        $this->assertStringNotContainsString('MMAYG', $html);
        $this->assertStringNotContainsString('UP-26-', $html);
        $this->assertSame(86, substr_count($html, '<time datetime="2026-') - 1);
        $this->assertStringNotContainsString('<details open', $html);
    }
}
