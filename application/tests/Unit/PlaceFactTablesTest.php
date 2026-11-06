<?php

namespace Tests\Unit;

use App\Services\PlaceFactTables;
use PHPUnit\Framework\TestCase;

class PlaceFactTablesTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_rankings_preserve_real_zeros_exclude_missing_values_and_report_all_ties(): void
    {
        $rows = [['name' => 'Zero', 'value' => 0], ['name' => 'Missing', 'value' => null], ['name' => 'Beta', 'value' => 100], ['name' => 'Alpha', 'value' => 100], ['name' => 'Invalid', 'value' => -1]];
        $rank = PlaceFactTables::extremes($rows, 'value');
        $this->assertSame(3, $rank['count']);
        $this->assertSame(['Zero'], array_column($rank['lowest'], 'name'));
        $this->assertSame(['Alpha', 'Beta'], array_column($rank['highest'], 'name'));
        $this->assertSame(2, PlaceFactTables::extremes($rows, 'value', 1)['count']);
    }

    public function test_soil_percentages_require_complete_indicator_specific_counts(): void
    {
        $metric = PlaceFactTables::soilValue(['N_Low' => 0, 'N_Medium' => 10, 'N_High' => 5, 'P_Low' => 400], 'N', ['Low', 'Medium', 'High'], 'Low');
        $this->assertSame(['value' => 0.0, 'samples' => 15], $metric);
        $this->assertNull(PlaceFactTables::soilValue(['N_Low' => 5, 'N_High' => 2], 'N', ['Low', 'Medium', 'High'], 'Low')['value']);
        $this->assertNull(PlaceFactTables::soilValue(['N_Low' => 0, 'N_Medium' => 0, 'N_High' => 0], 'N', ['Low', 'Medium', 'High'], 'Low')['value']);
    }

    public function test_official_state_display_names_normalize_without_substituting_geographies(): void
    {
        $this->assertSame('dadra-and-nagar-haveli-and-daman-and-diu', PlaceFactTables::stateSlug('The Dadra And Nagar Haveli And Daman And Diu'));
        $this->assertSame('andaman-and-nicobar-islands', PlaceFactTables::stateSlug('Andaman & Nicobar Islands'));
        $this->assertNotSame(PlaceFactTables::stateSlug('Telangana'), PlaceFactTables::stateSlug('Andhra Pradesh'));
    }
}
