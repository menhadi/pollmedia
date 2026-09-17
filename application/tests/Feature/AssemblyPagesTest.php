<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssemblyPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_village_coverage_distinguishes_report_records_from_imported_census_profiles(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitAssemblySeeder::class]);
        $this->get('/india/pc/pilibhit')->assertOk()->assertSee('Explore villages by constituency')
            ->assertViewHas('villageCoverage', fn ($coverage) => $coverage['rows']->sum('reported') === 1759
                && $coverage['rows']->sum(fn ($row) => $row['profiles']['2011']) === 1432)
            ->assertSee('/india/villages?ac=pilibhit&amp;year=2011', false)
            ->assertSee('/india/villages?ac=pilibhit&amp;year=2001', false);
        $this->get('/india/ac/pilibhit')->assertOk()->assertViewHas('villageCoverage', function ($coverage) {
            $row = $coverage['rows']->sole();

            return $row['reported'] === 246 && $row['profiles']['2011'] === 245;
        });
        $this->get('/india/ac/baheri')->assertOk()->assertSee('No profiles imported')
            ->assertViewHas('villageCoverage', function ($coverage) {
                $row = $coverage['rows']->sole();

                return $row['reported'] === 326 && $row['profiles']['2011'] === 0 && $row['profiles']['2001'] === 0;
            })->assertDontSee('/india/villages?ac=baheri');
        $this->get('/india/district/pilibhit')->assertOk()->assertViewHas('villageCoverage', fn ($coverage) => $coverage['rows']->sum('reported') === 1433);
        $this->get('/india/district/bareilly')->assertOk()->assertViewHas('villageCoverage', fn ($coverage) => $coverage['rows']->count() === 1 && $coverage['rows']->first()['ac'] === 'baheri');
    }

    public function test_assembly_results_and_reciprocal_geography(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitAssemblySeeder::class]);
        foreach (['baheri' => '3,355', 'pilibhit' => '6,970', 'barkhera' => '81,472', 'puranpur' => '26,576', 'bisalpur' => '50,409'] as $slug => $margin) {
            $this->get('/india/ac/'.$slug)->assertOk()->assertSee('Full candidate results · 2022')->assertSee($margin)->assertSee('/india/pc/pilibhit')->assertSee('/india/ac/'.$slug.'#elections')->assertDontSee('607,158');
        }
        $this->get('/india/ac/baheri')->assertSee('/india/district/bareilly')->assertDontSee('1,784 listed records');
        $this->get('/india/ac/pilibhit?year=2022')->assertOk()->assertSee('SANJAY SINGH GANGWAR');
        $this->get('/india/ac/pilibhit?year=2019')->assertNotFound();
        $this->get('/india/ac/unknown')->assertNotFound();
        $this->get('/india/pc/pilibhit')->assertSee('/india/ac/baheri')->assertSee('/india/ac/bisalpur');
        $this->get('/india/district/pilibhit')->assertSee('/india/ac/pilibhit')->assertDontSee('/india/ac/baheri');
        $this->get('/india/district/bareilly')->assertOk()->assertSee('/india/ac/baheri')->assertDontSee('20-village sample');
        $this->seed([PilibhitSeeder::class, PilibhitAssemblySeeder::class]);
        $this->assertDatabaseCount('election_contests', 5);
        $this->assertDatabaseCount('election_candidate_results', 59);
    }
}
