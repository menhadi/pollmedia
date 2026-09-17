<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertDontSee('Changed — review needed')->assertSee('Not checked yet')
            ->assertSee('Test official data')->assertSee(route('ai.settings'))
            ->assertViewHas('counts', fn ($counts) => $counts['Imports awaiting review'] === 1)
            ->assertViewHas('checks', fn ($checks) => $checks->count() === 2);

    }
}
