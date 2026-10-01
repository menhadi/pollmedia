<?php

namespace Tests\Feature;

use App\Services\ElectionCatalogueMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ElectionCatalogueMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function catalogue(bool $new): array
    {
        $pc = $new ? 'https://www.eci.gov.in/general-election-to-loksabha-2029-statistical-reports'
            : 'https://www.eci.gov.in/general-election-to-loksabha-2024-statistical-reports';
        $ac = $new ? 'https://www.eci.gov.in/statistical-report/ae/2029/99'
            : 'https://www.eci.gov.in/statistical-report/ae/2024/2';
        $be = $new ? 'https://www.eci.gov.in/statistical-report/be/2029/99'
            : 'https://www.eci.gov.in/statistical-report/be/2024/2';
        $html = '<a href="'.$pc.'">'.($new ? '2029' : '2024').'</a>'
            .'<table><tr><td>Andhra Pradesh</td><td><a href="'.$ac.'">'.($new ? '2029' : '2024').'</a></td></tr></table>'
            .'<table><tr><td>By-elections</td><td><a href="'.$be.'">'.($new ? '2029' : '2024').' by-election</a>'
            .'<a href="https://old.eci.gov.in/files/file/15622-bye-elections-july-to-dec-2024/">July-December 2024</a></td></tr></table>';

        return ['cmsPagesData' => ['page_content' => $html]];
    }

    public function test_daily_check_keeps_raw_evidence_and_lists_only_new_official_links(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([ElectionCatalogueMonitor::URL => Http::sequence()
            ->push($this->catalogue(false))
            ->push($this->catalogue(true))]);

        $this->artisan('elections:check-catalogue')->assertSuccessful();
        $first = app(ElectionCatalogueMonitor::class)->latest();
        $this->assertSame(0, $first['pending_count']);
        $this->assertSame(2, $first['counts']['be']);
        $this->artisan('elections:check-catalogue')->assertSuccessful();
        $latest = app(ElectionCatalogueMonitor::class)->latest();
        $this->assertSame(3, $latest['pending_count']);
        $this->assertSame('attention', DB::table('task_health')->where('key', 'election_catalogue')->value('status'));
        $this->assertSame(['pc', 'ac', 'be'], array_column($latest['pending'], 'kind'));
        $this->assertSame(2, count(Storage::disk('local')->files('election-catalogue-monitor/raw')));
        Storage::disk('local')->assertExists($first['raw_path']);
        Storage::disk('local')->assertExists($latest['raw_path']);

        $this->artisan('elections:check-catalogue --status')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_invalid_catalogue_fails_without_replacing_last_good_check(): void
    {
        Storage::fake('local');
        Http::fake([ElectionCatalogueMonitor::URL => Http::sequence()
            ->push($this->catalogue(false))
            ->push(['cmsPagesData' => ['page_content' => '<p>No tables</p>']])]);
        $this->artisan('elections:check-catalogue')->assertSuccessful();
        $first = app(ElectionCatalogueMonitor::class)->latest();
        $this->artisan('elections:check-catalogue')->assertFailed();
        $this->assertSame($first, app(ElectionCatalogueMonitor::class)->latest());
        $this->assertSame('failed', DB::table('task_health')->where('key', 'election_catalogue')->value('status'));
    }

    public function test_older_by_election_links_are_added_to_the_prior_baseline_during_upgrade(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $previousBody = json_encode($this->catalogue(false), JSON_THROW_ON_ERROR);
        $previousHash = hash('sha256', $previousBody);
        $rawPath = 'election-catalogue-monitor/raw/'.$previousHash.'.json';
        $disk->put($rawPath, $previousBody);
        $disk->put('election-catalogue-monitor/latest.json', json_encode([
            'source_sha256' => $previousHash, 'raw_path' => $rawPath,
        ], JSON_THROW_ON_ERROR));
        $disk->put('election-catalogue-monitor/baseline.json', json_encode([
            'by_election_urls' => ['https://www.eci.gov.in/statistical-report/be/2024/2'],
        ], JSON_THROW_ON_ERROR));
        Http::fake([ElectionCatalogueMonitor::URL => Http::response($this->catalogue(true))]);

        $this->artisan('elections:check-catalogue')->assertSuccessful();
        $this->assertSame(3, app(ElectionCatalogueMonitor::class)->latest()['pending_count']);
        $baseline = json_decode($disk->get('election-catalogue-monitor/baseline.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($previousHash, $baseline['source_sha256']);
        $this->assertCount(2, $baseline['by_election_urls']);
    }

    public function test_scheduler_checks_elections_daily_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'elections:check-catalogue'));
        $this->assertNotNull($event);
        $this->assertSame('0 6 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
    }
}
