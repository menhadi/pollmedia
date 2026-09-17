<?php

namespace Tests\Feature;

use App\Services\SourceChangeMonitor;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourceChangeMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_stay_pending_and_never_replace_published_evidence(): void
    {
        $this->seed(PilibhitSeeder::class);
        Http::preventStrayRequests();
        $key = 'pilibhit-officers';
        $url = config('source-monitor.sources')[$key];
        $releases = DB::table('source_releases')->count();
        $assignments = DB::table('office_assignments')->get()->toJson();
        $monitor = app(SourceChangeMonitor::class);
        Http::fake([$url => Http::sequence()
            ->push('<nav>old</nav><table><tr><td>Officer A</td></tr></table>')
            ->push('<nav>new</nav><table><tr><td> Officer   A </td></tr></table>')
            ->push('<table><tr><td>Officer B</td></tr></table>')
            ->push('<table><tr><td>Officer B</td></tr></table>')
            ->push('Unavailable', 503)
            ->push('<html>Access challenge</html>')]);
        $this->assertSame('baseline', $monitor->check($key, $url));
        $this->assertSame('unchanged', $monitor->check($key, $url));
        $this->assertSame('changed', $monitor->check($key, $url));
        $this->assertSame('changed', $monitor->check($key, $url));
        $this->get('/sources')->assertOk()->assertSee('review needed');
        $this->assertSame('failed', $monitor->check($key, $url));
        $this->assertSame('failed', $monitor->check($key, $url));
        $this->assertSame($releases, DB::table('source_releases')->count());
        $this->assertSame($assignments, DB::table('office_assignments')->get()->toJson());
        $this->assertDatabaseCount('source_checks', 6);
        $this->get('/sources')->assertOk()->assertSee('Latest source check failed')->assertSee('An earlier directory change still needs review.');
    }

    public function test_command_rejects_unknown_source_and_reports_failure(): void
    {
        $this->seed(PilibhitSeeder::class);
        Http::fake(['*' => Http::response('', 500)]);
        $this->artisan('sources:check --source=unknown')->assertFailed();
        Http::assertNothingSent();
        $this->artisan('sources:check --source=pilibhit-officers')->assertFailed();
    }
}
