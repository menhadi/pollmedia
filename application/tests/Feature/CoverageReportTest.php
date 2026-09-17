<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PilibhitElectionSeeder;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoverageReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_and_accepted_result_filters_do_not_claim_national_totals(): void
    {
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class]);
        DB::table('places')->insert(['slug' => 'unmapped-indian-district', 'name' => 'Unmapped district', 'type' => 'district', 'country_code' => 'IN']);
        DB::table('places')->insert(['slug' => 'foreign-district', 'name' => 'Foreign district', 'type' => 'district', 'country_code' => 'GB']);
        $this->get('/reports/coverage/uttar-pradesh')->assertOk()->assertSee('Uttar Pradesh')
            ->assertSee('not complete Uttar Pradesh statistics')
            ->assertViewHas('elections', fn ($rows) => $rows->count() === 2 && $rows->every(fn ($row) => $row->constituencies === 1))
            ->assertViewHas('coverage', fn ($rows) => $rows->firstWhere('type', 'district')->total === 1);
        $expected = DB::table('places')->where('country_code', 'IN')->count();
        $this->get('/reports/coverage/india')->assertOk()
            ->assertViewHas('coverage', fn ($rows) => $rows->sum('total') === $expected);
        DB::table('election_contests')->update(['active' => false]);
        $this->get('/reports/coverage/india')->assertSee('No published election results in this scope.');
        $this->get('/reports/coverage/unknown')->assertNotFound();
        $this->get('/reports/coverage/india?edition=monthly')->assertSessionHasErrors('edition');
    }

    public function test_saved_state_and_country_reports_retain_scope_and_fixed_evidence(): void
    {
        Storage::fake('local');
        $this->seed([PilibhitSeeder::class, PilibhitElectionSeeder::class]);
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        foreach (['india', 'uttar-pradesh'] as $scope) {
            $this->post('/reports/archive', ['scope' => $scope, 'edition' => 'annual'])->assertRedirect(route('reports.archive'));
            $report = DB::table('report_drafts')->where('scope', $scope)->first();
            $html = Storage::disk('local')->get($report->path);
            $this->assertStringNotContainsString('name="_token"', $html);
            $this->assertNotEmpty(json_decode($report->source_release_ids, true));
            $this->get('/reports/archive/'.$report->id.'/download')->assertOk()->assertContent($html)
                ->assertHeader('Content-Disposition', 'attachment; filename="'.$scope.'-annual-'.$report->id.'-draft.html"');
        }
        $this->get('/reports/archive')->assertOk()->assertSee('India')->assertSee('Uttar Pradesh');
        $this->get('/reports/archive?scope=india&edition=annual')->assertOk()
            ->assertViewHas('reports', fn ($reports) => $reports->total() === 1 && $reports->first()->scope === 'india');
        $this->get('/reports/archive?scope=unknown')->assertSessionHasErrors('scope');
        $this->assertDatabaseCount('report_drafts', 2);
    }
}
