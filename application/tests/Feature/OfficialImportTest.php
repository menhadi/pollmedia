<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OfficialDownload;
use App\Services\OfficialImport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class OfficialImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        Storage::fake('local');
    }

    private function connector(string $format = 'csv'): int
    {
        return DB::table('import_connectors')->insertGetId(['name' => 'Official test source', 'url' => 'https://data.gov.in/example.'.$format,
            'format' => $format, 'record_key' => 'code', 'options' => json_encode(['header_row' => 1]), 'automatic' => true,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_scheduler_registers_due_imports_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($event) => str_contains($event->command ?? '', 'imports:refresh --due'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $worker = $events->first(fn ($event) => str_contains($event->command ?? '', 'queue:work database --queue=imports'));
        $this->assertNotNull($worker);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertTrue($worker->runInBackground);
        $this->assertStringContainsString('--stop-when-empty', $worker->command);
        $this->assertStringContainsString('--timeout=60', $worker->command);
    }

    public function test_changed_extraction_settings_reprocess_identical_source_bytes(): void
    {
        $this->admin();
        $id = $this->connector();
        $this->mock(OfficialDownload::class, fn ($mock) => $mock->shouldReceive('get')->twice()->andReturn("code,name\n001,First\n002,Second\n"));
        app(OfficialImport::class)->run($id);
        DB::table('import_connectors')->where('id', $id)->update(['options' => json_encode(['header_row' => 1, 'filter_column' => 'code', 'filter_value' => '002'])]);
        $run = app(OfficialImport::class)->run($id);
        $record = DB::table('import_runs')->find($run);
        $this->assertSame('needs_review', $record->status);
        $this->assertSame(['002'], array_column(json_decode($record->extracted, true)['rows'], 'code'));
    }

    public function test_due_imports_skip_disabled_and_future_sources_and_continue_after_failure(): void
    {
        $this->admin();
        $failed = $this->connector();
        $due = $this->connector();
        $disabled = $this->connector();
        $future = $this->connector();
        DB::table('import_connectors')->where('id', $disabled)->update(['automatic' => false]);
        DB::table('import_connectors')->where('id', $future)->update(['next_check_at' => now()->addDay()]);
        $this->mock(OfficialDownload::class, function ($mock): void {
            $mock->shouldReceive('get')->once()->ordered()->andThrow(new RuntimeException('Source unavailable'));
            $mock->shouldReceive('get')->once()->ordered()->andReturn("code,name\n001,Official row\n");
        });
        $this->artisan('imports:refresh --due')->assertExitCode(1);
        $this->assertDatabaseHas('import_runs', ['import_connector_id' => $failed, 'status' => 'failed']);
        $this->assertDatabaseHas('import_runs', ['import_connector_id' => $due, 'status' => 'needs_review']);
        $this->assertDatabaseCount('import_runs', 2);
        $this->assertDatabaseCount('source_releases', 0);
        $this->assertDatabaseHas('import_connectors', ['id' => $due, 'accepted_run_id' => null]);
        $this->artisan('imports:refresh --due')->expectsOutput('No automatic sources are due.')->assertExitCode(0);
    }

    public function test_download_extract_compare_review_and_unchanged_preserve_public_data(): void
    {
        $this->admin();
        $id = $this->connector();
        $this->mock(OfficialDownload::class, function ($mock): void {
            $mock->shouldReceive('get')->times(3)->andReturn("code,name\n001,First\n002,Second\n", "code,name\n001,Changed\n003,Third\n", "code,name\n001,Changed\n003,Third\n");
        });
        $this->post('/admin/imports/'.$id.'/fetch')->assertRedirect();
        $first = DB::table('import_runs')->first();
        $this->assertSame('needs_review', $first->status);
        $this->assertSame('001', json_decode($first->extracted, true)['rows'][0]['code']);
        $this->get('/admin/imports/runs/'.$first->id)->assertOk()->assertSee('First')->assertSee('Accept reviewed baseline');
        $this->post('/admin/imports/runs/'.$first->id.'/review', ['decision' => 'accept'])->assertRedirect();
        $this->post('/admin/imports/'.$id.'/fetch')->assertRedirect();
        $second = DB::table('import_runs')->orderByDesc('id')->first();
        $summary = json_decode($second->summary, true);
        $this->assertSame([1, 1, 1], [$summary['added'], $summary['changed'], $summary['removed']]);
        $this->assertDatabaseHas('import_connectors', ['id' => $id, 'accepted_run_id' => $first->id]);
        $this->post('/admin/imports/'.$id.'/fetch')->assertRedirect();
        $this->assertSame('unchanged', DB::table('import_runs')->orderByDesc('id')->value('status'));
        $this->assertDatabaseCount('source_releases', 0);
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseHas('import_runs', ['id' => $second->id, 'status' => 'needs_review']);
    }

    public function test_duplicate_keys_and_stale_reviews_cannot_replace_baseline(): void
    {
        $this->admin();
        $id = $this->connector();
        $this->mock(OfficialDownload::class, function ($mock): void {
            $mock->shouldReceive('get')->andReturn("code,name\n001,First\n001,Duplicate\n", "code,name\n001,First\n", "code,name\n001,Later\n");
        });
        $service = app(OfficialImport::class);
        $duplicate = $service->run($id);
        $this->post('/admin/imports/runs/'.$duplicate.'/review', ['decision' => 'accept'])->assertStatus(422);
        $first = $service->run($id);
        $later = $service->run($id);
        $this->post('/admin/imports/runs/'.$first.'/review', ['decision' => 'accept'])->assertRedirect();
        $this->post('/admin/imports/runs/'.$later.'/review', ['decision' => 'accept'])->assertStatus(409);
        $recomputed = $service->run($id);
        $this->assertDatabaseHas('import_runs', ['id' => $recomputed, 'base_run_id' => $first, 'status' => 'needs_review']);
        $this->post('/admin/imports/runs/'.$recomputed.'/review', ['decision' => 'accept'])->assertRedirect();
    }

    public function test_upload_api_path_and_daily_checks_have_explicit_scope(): void
    {
        $this->admin();
        $id = $this->connector('json');
        DB::table('import_connectors')->where('id', $id)->update(['options' => json_encode(['json_path' => 'records'])]);
        $file = UploadedFile::fake()->createWithContent('export.json', '{"records":[{"code":"007","name":"Example"}]}');
        $this->post('/admin/imports/'.$id.'/upload', ['file' => $file])->assertRedirect();
        $this->assertDatabaseHas('import_runs', ['origin' => 'upload', 'status' => 'needs_review']);
        $next = Carbon::parse(DB::table('import_connectors')->where('id', $id)->value('next_check_at'))->timezone('Asia/Kolkata');
        $this->assertSame('06:00:00', $next->format('H:i:s'));
        $this->artisan('imports:refresh --due')->expectsOutput('No automatic sources are due.')->assertExitCode(0);
        $this->post('/admin/imports/'.$id.'/schedule', ['automatic' => 0])->assertRedirect();
        $this->assertDatabaseHas('import_connectors', ['id' => $id, 'automatic' => false]);
    }

    public function test_admin_access_and_official_url_restrictions(): void
    {
        $this->get('/admin/imports')->assertRedirect(route('admin.login'));
        $this->post('/admin/imports')->assertRedirect(route('admin.login'));
        $this->admin();
        $this->get('/admin/imports')->assertOk()->assertSee('PDF tables');
        $download = new OfficialDownload;
        foreach (['http://data.gov.in/a', 'https://127.0.0.1/a', 'https://data.gov.in.evil.test/a', 'https://name:pass@data.gov.in/a', 'https://data.gov.in/a?api_key=secret'] as $url) {
            try {
                $download->validateUrl($url);
                $this->fail('Unsafe URL accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('censusindia.gov.in', $download->validateUrl('https://censusindia.gov.in/export.xlsx'));
    }
}
