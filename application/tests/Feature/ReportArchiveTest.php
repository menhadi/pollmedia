<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
    }

    public function test_only_administrators_can_save_report_drafts(): void
    {
        Auth::logout();
        $this->post('/reports/archive', ['edition' => 'quarterly'])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->post('/reports/archive', ['edition' => 'quarterly'])->assertForbidden();
        $this->assertDatabaseCount('report_drafts', 0);
    }

    public function test_period_end_generation_skips_other_days_and_is_repeatable_without_duplicates(): void
    {
        Storage::fake('local');
        $this->seed(PilibhitSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-29 23:50', 'Asia/Kolkata'));
        $this->artisan('reports:archive-due')->assertExitCode(0);
        $this->assertDatabaseCount('report_drafts', 0);
        $this->travelTo(Carbon::parse('2026-09-30 23:50', 'Asia/Kolkata'));
        $this->artisan('reports:archive-due')->assertExitCode(0);
        $this->artisan('reports:archive-due')->assertExitCode(0);
        $this->assertDatabaseCount('report_drafts', 3);
        $this->assertDatabaseHas('report_drafts', ['edition' => 'quarterly', 'period' => 'Q3 2026']);
        $this->travelTo(Carbon::parse('2026-12-31 23:50', 'Asia/Kolkata'));
        $this->artisan('reports:archive-due')->assertExitCode(0);
        $this->artisan('reports:archive-due')->assertExitCode(0);
        $this->assertDatabaseCount('report_drafts', 9);
        $this->assertDatabaseHas('report_drafts', ['edition' => 'annual', 'period' => '2026']);
        $this->travelBack();
    }

    public function test_missing_period_end_evidence_fails_without_archiving(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-09-30 23:50', 'Asia/Kolkata'));
        $this->artisan('reports:archive-due')->assertExitCode(1);
        $this->assertDatabaseCount('report_drafts', 0);
        $this->travelBack();
    }

    public function test_saved_report_remains_unchanged_and_detects_file_tampering(): void
    {
        Storage::fake('local');
        $this->seed(PilibhitSeeder::class);
        $this->get('/reports/archive')->assertOk()->assertSee('No saved drafts yet');
        $this->post('/reports/archive', ['edition' => 'quarterly'])->assertRedirect('/reports/archive');
        $report = DB::table('report_drafts')->first();
        $html = Storage::disk('local')->get($report->path);
        $this->assertSame(hash('sha256', $html), $report->sha256);
        $this->assertStringNotContainsString('name="_token"', $html);
        $this->assertCount(4, json_decode($report->source_release_ids, true));
        DB::table('source_releases')->update(['status' => 'pending']);
        $this->get('/reports/archive/'.$report->id.'/download')->assertOk()->assertContent($html);
        $this->get('/reports/archive')->assertOk()->assertSee('Download saved HTML')->assertSee($report->sha256);
        Storage::disk('local')->put($report->path, 'changed file');
        $this->get('/reports/archive/'.$report->id.'/download')->assertStatus(409);
        Storage::disk('local')->delete($report->path);
        $this->get('/reports/archive/'.$report->id.'/download')->assertNotFound();
    }

    public function test_invalid_editions_and_missing_evidence_do_not_create_archives(): void
    {
        Storage::fake('local');
        $this->post('/reports/archive', ['edition' => 'invalid'])->assertSessionHasErrors('edition');
        $this->post('/reports/archive', ['edition' => 'annual'])->assertStatus(503);
        $this->assertDatabaseCount('report_drafts', 0);
        $this->get('/reports/archive/unknown/download')->assertNotFound();
    }
}
