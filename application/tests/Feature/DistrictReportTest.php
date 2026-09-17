<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitAssemblySeeder;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DistrictReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_drafts_preserve_reference_years_geography_and_downloadable_snapshot(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 16)->setTime(5, 0));
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class, PilibhitAssemblySeeder::class]);
        $this->get('/reports/pilibhit')->assertOk()->assertSee('Q3 2026')->assertSee('2,031,007')->assertSee('1,432 of 1,435')
            ->assertSee('Gularia Bhindara')->assertSee('not district totals')->assertSee('2024')->assertSee('2022')
            ->assertViewHas('contests', fn ($rows) => $rows->count() === 5)
            ->assertViewHas('coverage', fn ($rows) => $rows->sum('lgd_count') === 1433);
        $this->get('/reports/pilibhit?edition=annual')->assertOk()->assertSee('2026')->assertSee('Annual edition')->assertDontSee('Q3 2026');
        $this->get('/reports/pilibhit?edition=annual&download=1')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="pilibhit-annual-2026-09-16-draft.html"')
            ->assertSee('<style>', false)->assertSee('Sources & references', false);
        $this->get('/reports/pilibhit?edition=invalid')->assertSessionHasErrors('edition');
        $this->get('/reports/pilibhit?year=2001')->assertSessionHasErrors('year');
        $source = DB::table('data_sources')->where('key', 'lgd-pilibhit-electoral')->value('id');
        DB::table('source_releases')->where('data_source_id', $source)->update(['status' => 'pending']);
        $this->get('/reports/pilibhit')->assertStatus(503);
        $this->travelBack();
    }
}
