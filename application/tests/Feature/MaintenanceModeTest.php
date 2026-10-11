<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private function maintenance(): MaintenanceMode
    {
        $maintenance = new class implements MaintenanceMode
        {
            private ?array $payload = null;

            public function activate(array $payload): void
            {
                $this->payload = $payload;
            }

            public function deactivate(): void
            {
                $this->payload = null;
            }

            public function active(): bool
            {
                return $this->payload !== null;
            }

            public function data(): array
            {
                return $this->payload ?? [];
            }
        };
        $this->app->instance(MaintenanceMode::class, $maintenance);

        return $maintenance;
    }

    public function test_admin_can_pause_public_access_and_restore_it_without_lockout(): void
    {
        $maintenance = $this->maintenance();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/maintenance')->assertOk()->assertSee('Website is live');
        $this->post('/admin/maintenance', ['enabled' => 1])->assertRedirect(route('maintenance.index'));
        $this->assertTrue($maintenance->active());
        $this->get('/')->assertStatus(503)->assertHeader('Retry-After', '300')->assertSee('We’ll be back shortly');
        $this->getJson('/api/election-maps/results')->assertStatus(503);
        $this->get('/admin/maintenance')->assertOk()->assertSee('Public access is paused');
        $this->post('/admin/maintenance', ['enabled' => 0])->assertRedirect(route('maintenance.index'));
        $this->assertFalse($maintenance->active());
        $this->get('/report-problem')->assertOk();
        $this->assertDatabaseHas('site_changes', ['target' => 'settings:maintenance', 'user_id' => $admin->id]);
    }

    public function test_guests_can_sign_in_during_maintenance_but_cannot_change_it(): void
    {
        $maintenance = $this->maintenance();
        $maintenance->activate(['status' => 503, 'retry' => 300]);
        $this->get('/admin/login')->assertOk();
        $this->post('/admin/maintenance', ['enabled' => 0])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->post('/admin/maintenance', ['enabled' => 0])->assertForbidden();
        $this->assertTrue($maintenance->active());
    }

    public function test_toggle_requires_an_explicit_valid_state(): void
    {
        $maintenance = $this->maintenance();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/maintenance', [])->assertSessionHasErrors('enabled');
        $this->post('/admin/maintenance', ['enabled' => 'invalid'])->assertSessionHasErrors('enabled');
        $this->assertFalse($maintenance->active());
    }

    public function test_admin_preview_bypasses_maintenance_only_with_a_valid_cookie(): void
    {
        $maintenance = $this->maintenance();
        $maintenance->activate(['status' => 503, 'retry' => 300]);
        $this->post('/admin/maintenance/preview')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->post('/admin/maintenance/preview')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/maintenance/preview')->assertRedirect('/'.$maintenance->data()['secret']);
        $this->assertTrue($maintenance->active());
        $this->assertSame(300, $maintenance->data()['retry']);
        $this->get('/report-problem')->assertStatus(503);
        $response = $this->get('/'.$maintenance->data()['secret'])->assertRedirect('/');
        $cookie = $response->getCookie('laravel_maintenance', false);
        $this->assertNotNull($cookie);
        $this->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())->get('/report-problem')->assertOk();
        $maintenance->activate(['status' => 503, 'retry' => 300, 'secret' => 'new-secret']);
        $this->get('/report-problem')->assertStatus(503);
    }
}
