<?php

namespace Tests\Feature;

use App\Jobs\SyncElectionReleasesJob;
use App\Models\User;
use App\Services\ElectionArchive;
use App\Services\ElectionCatalogueMonitor;
use App\Services\ElectionReleaseImporter;
use App\Services\ElectionRuntimeCatalogue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ElectionReleaseImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsupported_new_pc_release_is_reported_without_importing_votes(): void
    {
        Storage::fake('local');
        $url = 'https://www.eci.gov.in/general-election-to-loksabha-2029-statistical-reports';
        $monitor = $this->mock(ElectionCatalogueMonitor::class);
        $monitor->shouldReceive('check')->once()->andReturn([
            'source_sha256' => str_repeat('a', 64),
            'pending' => [['kind' => 'pc', 'year' => 2029, 'url' => $url]],
        ]);

        $result = app(ElectionReleaseImporter::class)->run();

        $this->assertSame([], $result['imported']);
        $this->assertSame($url, $result['needs_review'][0]['url']);
        $this->assertSame('attention', DB::table('task_health')->where('key', 'election_sync')->value('status'));
        $this->assertDatabaseCount('archive_json_files', 0);
    }

    public function test_staged_edition_is_hidden_until_its_source_backed_index_is_built(): void
    {
        Storage::fake('local');
        $url = 'https://www.eci.gov.in/statistical-report/ae/2029/99';
        $id = substr(hash('sha256', $url), 0, 24);
        $sourceHash = str_repeat('b', 64);
        $manifest = ['url' => $url, 'files' => [['file' => 'official.xlsx', 'sha256' => $sourceHash]]];
        $extraction = ['kind' => 'ac', 'year' => 2029, 'source_url' => $url, 'source_file' => 'official.xlsx',
            'source_sha256' => $sourceHash, 'records' => [['code' => 1, 'constituency_name' => 'Test seat',
                'state_name' => 'Andhra Pradesh', 'status' => 'needs_review', 'candidates' => []]]];
        foreach (['manifest' => $manifest, 'extraction' => $extraction] as $name => $data) {
            $path = 'election-archive/'.$id.'/'.$name.'.json';
            $body = json_encode($data, JSON_THROW_ON_ERROR);
            DB::table('archive_json_files')->insert(['path_hash' => hash('sha256', $path), 'path' => $path,
                'category' => 'election-archive', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body),
                'source_url' => $url, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        }
        $catalogue = app(ElectionRuntimeCatalogue::class);
        $catalogue->stage(['kind' => 'ac', 'year' => 2029, 'url' => $url], 'Andhra Pradesh');
        $this->assertNotContains($url, array_column(app(ElectionArchive::class)->nationalAssemblyEntries(), 'url'));

        $this->artisan('archive:index-constituencies', ['--edition' => $id])->assertSuccessful();
        $this->assertDatabaseHas('historical_constituency_index', ['edition_id' => $id, 'record_code' => 1]);
        $catalogue->publish($url);
        $this->assertContains($url, array_column(app(ElectionArchive::class)->nationalAssemblyEntries(), 'url'));
    }

    public function test_admin_can_queue_a_manual_sync_and_weekly_schedule_is_registered(): void
    {
        Queue::fake();
        $this->post('/admin/site/elections/sync')->assertRedirect();
        Queue::assertNothingPushed();
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user)->post('/admin/site/elections/sync')->assertSessionHasNoErrors();
        Queue::assertPushedOn('election-sync', SyncElectionReleasesJob::class);
        $this->assertGreaterThan((new SyncElectionReleasesJob)->timeout, config('queue.connections.election_sync.retry_after'));
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'elections:sync-releases'));
        $this->assertNotNull($event);
        $this->assertSame('30 6 * * 0', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
