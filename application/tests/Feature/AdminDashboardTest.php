<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_an_administrator_and_https_in_production(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $user->is_admin = true;
        $user->save();
        $this->app->instance('env', 'production');
        $this->get('http://example.test/admin')->assertForbidden();
        $this->get('https://example.test/admin')->assertOk()->assertSee('No import runs yet')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_dashboard_uses_latest_checks_and_counts_pending_imports(): void
    {
        $this->seed(PilibhitSeeder::class);
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $source = DB::table('data_sources')->where('key', 'pilibhit-officers')->value('id');
        foreach (['changed', 'failed'] as $status) {
            DB::table('source_checks')->insert(['data_source_id' => $source, 'status' => $status, 'checked_at' => now()]);
        }
        $connector = DB::table('import_connectors')->insertGetId([
            'name' => 'Test official data', 'url' => 'https://example.gov.in/data.csv',
            'format' => 'csv', 'record_key' => 'code', 'options' => '{}',
        ]);
        foreach (['needs_review', 'accepted', 'failed'] as $status) {
            DB::table('import_runs')->insert([
                'import_connector_id' => $connector, 'status' => $status, 'origin' => 'download',
                'source_url' => 'https://example.gov.in/data.csv', 'created_at' => now(),
            ]);
        }
        $this->actingAs($user)->get('/admin')->assertOk()->assertSee('Check failed — retry needed')
            ->assertSee('An earlier detected change still needs review')
            ->assertDontSee('Changed — review needed')->assertSee('Not checked yet')
            ->assertSee('Test official data')->assertSee(route('ai.settings'))
            ->assertViewHas('counts', fn ($counts) => $counts['Imports awaiting review'] === 1)
            ->assertViewHas('failedImports', fn ($runs) => $runs->count() === 1)
            ->assertViewHas('checks', fn ($checks) => $checks->count() === 2);

        DB::table('import_runs')->insert([
            'import_connector_id' => $connector, 'status' => 'unchanged', 'origin' => 'download',
            'source_url' => 'https://example.gov.in/data.csv', 'created_at' => now(),
        ]);
        $this->get('/admin')->assertOk()->assertSee('No sources have a failed latest import.')
            ->assertViewHas('failedImports', fn ($runs) => $runs->isEmpty());
    }

    public function test_election_batch_states_are_separate_from_source_import_review(): void
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        foreach (['queued', 'processing', 'failed', 'needs_attention', 'ready'] as $status) {
            DB::table('election_import_batches')->insert([
                'id' => (string) Str::ulid(), 'fingerprint' => hash('sha256', $status),
                'state' => 'Uttar Pradesh', 'year' => 2022, 'status' => $status,
                'detail_path' => 'test-detail.pdf', 'summary_path' => 'test-summary.pdf',
                'detail_sha256' => str_repeat('a', 64), 'summary_sha256' => str_repeat('b', 64),
                'source_url' => 'https://www.eci.gov.in/', 'created_by' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAs($user)->get('/admin')->assertOk()->assertSee('Recent election batches')
            ->assertSee(route('election-batches.index'))
            ->assertViewHas('counts', fn ($counts) => $counts['Election batches queued or processing'] === 2
                && $counts['Election batches needing attention'] === 2 && $counts['Imports awaiting review'] === 0)
            ->assertViewHas('batches', fn ($batches) => $batches->count() === 5);
    }
}
