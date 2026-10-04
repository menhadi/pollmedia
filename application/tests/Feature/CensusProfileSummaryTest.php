<?php

namespace Tests\Feature;

use App\Services\CensusProfileSummary;
use Tests\TestCase;

class CensusProfileSummaryTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_history_uses_source_jurisdictions_and_preserves_missing_values(): void
    {
        $service = app(CensusProfileSummary::class);
        $national = $service->series();
        $this->assertCount(12, $national['rows']);
        $this->assertSame(1901, $national['rows'][0]['year']);
        $this->assertNull($national['rows'][0]['households']);
        $this->assertNull($national['rows'][0]['growth']);
        $this->assertSame(-0.31, $national['rows'][2]['growth']);
        $place = (object) ['state_code' => '09', 'district_code' => '151', 'level' => 'VILLAGE'];
        $row = (object) ['values' => '{"TOT_P":0,"TOT_M":0,"TOT_F":0,"No_HH":null}', 'flags' => '[]', 'residence' => 'Rural'];
        $village = $service->series($place, [$row], 2011);
        $this->assertCount(1, $village['rows']);
        $this->assertSame(0, $village['rows'][0]['population']);
        $this->assertNull($village['rows'][0]['ratio']);
        $place->level = 'DISTRICT';
        $this->assertCount(1, $service->series($place, [$row], 2001)['rows']);
        $this->assertCount(1, $service->series($place, [$row], 2011)['rows']);
    }

    public function test_census_charts_have_controls_and_dated_sources(): void
    {
        $html = view('census-profile-charts', ['censusSeries' => app(CensusProfileSummary::class)->series()])->render();
        $this->assertStringContainsString('data-chart-from', $html);
        $this->assertStringContainsString('data-chart-to', $html);
        $this->assertStringContainsString('Decadal population growth', $html);
        $this->assertStringContainsString('Females per 1,000 males', $html);
        $this->assertStringContainsString('Official population history', $html);
        $this->assertStringNotContainsString('<h3>Households</h3>', $html);
        $this->assertStringContainsString('<details class="census-source-notes">', $html);
        $this->assertStringNotContainsString('census-source-notes" open', $html);
    }

    public function test_empty_census_values_do_not_render_charts_or_controls(): void
    {
        $html = view('census-profile-charts', ['censusSeries' => ['rows' => [['year' => 2011, 'population' => null, 'male' => null, 'female' => null, 'households' => null, 'literates' => null, 'growth' => null, 'ratio' => null, 'notes' => []]], 'source' => null]])->render();
        $this->assertStringNotContainsString('data-history-chart', $html);
        $this->assertStringNotContainsString('data-chart-from', $html);
        $this->assertStringNotContainsString('View figures', $html);
    }
}
