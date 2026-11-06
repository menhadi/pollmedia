<?php

namespace Tests\Unit;

use App\Services\CensusAreaPresentation;
use PHPUnit\Framework\TestCase;

class CensusAreaPresentationTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_state_literacy_uses_summed_eligible_population_and_preserves_original_counts(): void
    {
        $districts = [['code' => '01', 'values' => ['TOT_P' => 100, 'P_06' => 20, 'P_LIT' => 40, 'No_HH' => 0]], ['code' => '002', 'values' => ['TOT_P' => 300, 'P_06' => 60, 'P_LIT' => 240, 'No_HH' => 70]]];
        $result = CensusAreaPresentation::stateValues(['No_HH' => 75], $districts, ['001', '002']);
        $this->assertSame(87.5, $result['rates']['literacy']);
        $this->assertSame(75, $result['values']['No_HH']);
        $this->assertSame(400.0, $result['values']['TOT_P']);
    }

    public function test_missing_district_or_conflicting_duplicate_is_not_zero_filled_or_double_counted(): void
    {
        $districts = [['code' => '001', 'values' => ['TOT_P' => 100, 'No_HH' => 0]], ['code' => '1', 'values' => ['TOT_P' => 100, 'No_HH' => 0]]];
        $result = CensusAreaPresentation::stateValues([], $districts, ['1']);
        $this->assertSame(100.0, $result['values']['TOT_P']);
        $this->assertSame(0.0, $result['values']['No_HH']);
        $this->assertArrayNotHasKey('TOT_P', CensusAreaPresentation::stateValues([], $districts, ['1', '2'])['values']);
        $districts[] = ['code' => '1', 'values' => ['TOT_P' => 101]];
        $this->assertArrayNotHasKey('TOT_P', CensusAreaPresentation::stateValues([], $districts, ['1'])['values']);
    }

    public function test_rates_reject_missing_or_impossible_denominators(): void
    {
        $this->assertNull(CensusAreaPresentation::rates(['TOT_P' => 100, 'P_LIT' => 80])['literacy']);
        $this->assertNull(CensusAreaPresentation::rates(['TOT_P' => 100, 'P_06' => 30, 'P_LIT' => 80])['literacy']);
    }
}
