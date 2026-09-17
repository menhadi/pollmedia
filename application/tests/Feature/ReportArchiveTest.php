<?php

namespace Tests\Feature;

use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportArchiveTest extends TestCase
{
    use RefreshDatabase;

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
