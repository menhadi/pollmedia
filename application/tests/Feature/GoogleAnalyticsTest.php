<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $user = User::factory()->create(['is_admin' => true]);

        return $user;
    }

    public function test_admin_can_enable_disable_and_replace_tag_without_scripts(): void
    {
        $this->get('/report-problem')->assertDontSee('googletagmanager.com/gtag/js', false);
        $this->actingAs($this->administrator())->post('/admin/site/analytics', ['enabled' => 1, 'measurement_id' => 'G-8LY5L8DFSS'])->assertSessionHasNoErrors();
        $this->get('/report-problem?email=private@example.org')->assertSee('id=G-8LY5L8DFSS', false)->assertDontSee('private@example.org');
        $this->get('/admin/site')->assertSee('Google Analytics 4')->assertDontSee('googletagmanager.com/gtag/js', false);
        $this->post('/admin/site/analytics', ['enabled' => 1, 'measurement_id' => 'G-REPLACEMENT'])->assertSessionHasNoErrors();
        $this->get('/report-problem')->assertSee('id=G-REPLACEMENT', false)->assertDontSee('id=G-8LY5L8DFSS', false);
        $this->post('/admin/site/analytics', ['enabled' => 0, 'measurement_id' => 'G-REPLACEMENT'])->assertSessionHasNoErrors();
        $this->get('/report-problem')->assertDontSee('googletagmanager.com/gtag/js', false);
        $this->assertDatabaseHas('site_changes', ['target' => 'settings:google_analytics']);
    }

    public function test_settings_are_protected_and_script_input_is_rejected(): void
    {
        $this->post('/admin/site/analytics', ['enabled' => 1, 'measurement_id' => 'G-TEST1234'])->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->post('/admin/site/analytics', [])->assertForbidden();
        $this->actingAs($this->administrator())->post('/admin/site/analytics', ['enabled' => 1, 'measurement_id' => '<script>alert(1)</script>'])->assertSessionHasErrors('measurement_id');
        $this->post('/admin/site/analytics', ['enabled' => 1, 'measurement_id' => ''])->assertSessionHasErrors('measurement_id');
        $this->assertDatabaseMissing('site_settings', ['key' => 'google_analytics']);
    }

    public function test_malformed_saved_id_is_not_rendered_and_api_is_not_tagged(): void
    {
        DB::table('site_settings')->insert(['key' => 'google_analytics', 'value' => json_encode(['enabled' => true, 'measurement_id' => 'G-TEST"><script>'])]);
        $this->get('/report-problem')->assertDontSee('googletagmanager.com/gtag/js', false);
        $this->getJson('/api/sir')->assertDontSee('googletagmanager.com/gtag/js', false);
    }
}
