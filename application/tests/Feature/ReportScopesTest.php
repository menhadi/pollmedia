<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PilibhitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_area_management_requires_an_administrator(): void
    {
        $this->get('/admin/report-areas')->assertRedirect(route('admin.login'));
        $this->post('/admin/report-areas', [])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->get('/admin/report-areas')->assertForbidden();
        $this->post('/admin/report-areas/india/schedule', ['automatic' => 0])->assertForbidden();
    }

    public function test_non_indian_areas_use_recorded_country_and_accepted_namespace_membership(): void
    {
        $this->seed(PilibhitSeeder::class);
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $this->actingAs($admin);
        $place = DB::table('places')->insertGetId(['slug' => 'test-borough', 'name' => 'Test borough', 'type' => 'borough', 'country_code' => 'GB']);
        DB::table('place_identifiers')->insert(['place_id' => $place, 'namespace' => 'test:GB:borough', 'code' => '001', 'version' => 'test', 'source_release_id' => DB::table('source_releases')->value('id')]);
        $input = ['key' => 'gb-coverage', 'label' => 'UK test coverage', 'country_code' => 'GB', 'timezone' => 'Europe/London', 'selection' => 'country'];
        $this->post('/admin/report-areas', $input)->assertRedirect(route('report-scopes.index'));
        $this->get('/reports/coverage/gb-coverage')->assertOk()->assertSee('UK test coverage')
            ->assertViewHas('coverage', fn ($rows) => $rows->sum('total') === 1 && $rows->first()->type === 'borough');
        $input['key'] = 'test-jurisdiction';
        $input['selection'] = 'identifiers';
        $input['namespaces'] = ['electoral:IN:UP:ac'];
        $this->post('/admin/report-areas', $input)->assertSessionHasErrors('namespaces');
        $input['namespaces'] = ['test:GB:borough'];
        $this->post('/admin/report-areas', $input)->assertRedirect(route('report-scopes.index'));
        $this->get('/reports/coverage/test-jurisdiction')->assertOk()->assertViewHas('coverage', fn ($rows) => $rows->sum('total') === 1);
        $this->get('/reports/archive')->assertOk()->assertSee('UK test coverage');
        $this->get('/admin/report-areas')->assertOk()->assertSee('Europe/London');
        $this->post('/admin/report-areas', $input)->assertSessionHasErrors('key');
    }

    public function test_automatic_generation_uses_each_areas_time_zone_and_pause_setting(): void
    {
        Storage::fake('local');
        DB::table('report_scopes')->update(['automatic' => false]);
        DB::table('places')->insert(['slug' => 'test-county', 'name' => 'Test county', 'type' => 'county', 'country_code' => 'US']);
        DB::table('report_scopes')->insert(['key' => 'us-test', 'label' => 'US test coverage', 'country_code' => 'US', 'timezone' => 'America/New_York', 'selection' => 'country', 'automatic' => true]);
        $this->travelTo(Carbon::parse('2026-09-30 23:40', 'America/New_York'));
        $this->artisan('reports:archive-due')->assertExitCode(0);
        $this->assertDatabaseCount('report_drafts', 0);
        $this->travelTo(Carbon::parse('2026-09-30 23:50', 'America/New_York'));
        $this->artisan('reports:archive-due')->assertExitCode(0);
        $this->artisan('reports:archive-due')->assertExitCode(0);
        $this->assertDatabaseCount('report_drafts', 1);
        $this->assertDatabaseHas('report_drafts', ['scope' => 'us-test', 'period' => 'Q3 2026']);
        $this->travelBack();
    }
}
