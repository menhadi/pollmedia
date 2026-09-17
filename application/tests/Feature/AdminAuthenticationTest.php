<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'password' => 'a-long-test-passphrase']);
        $user->is_admin = true;
        $user->save();

        return $user;
    }

    public function test_account_settings_require_an_administrator(): void
    {
        $this->get('/admin/account')->assertRedirect(route('admin.login'));
        $this->post('/admin/account/password')->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->get('/admin/account')->assertForbidden();
        $this->post('/admin/account/password')->assertForbidden();
    }

    public function test_password_change_rejects_incorrect_current_password_and_confirmation(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin)->get('/admin/account')->assertOk()->assertSee($admin->email);
        $this->post('/admin/account/password', [
            'current_password' => 'incorrect', 'password' => 'a-new-long-passphrase',
            'password_confirmation' => 'a-new-long-passphrase',
        ])->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input.current_password');
        $this->post('/admin/account/password', [
            'current_password' => 'a-long-test-passphrase', 'password' => 'a-new-long-passphrase',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
        $this->assertTrue(Hash::check('a-long-test-passphrase', $admin->fresh()->password));
    }

    public function test_password_change_revokes_database_sessions_and_requires_new_credentials(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->administrator();
        $other = User::factory()->create();
        foreach ([$admin->id, $other->id] as $id) {
            DB::table('sessions')->insert([
                'id' => 'other-device-'.$id, 'user_id' => $id,
                'payload' => base64_encode(serialize([])), 'last_activity' => time(),
            ]);
        }
        $this->actingAs($admin)->post('/admin/account/password', [
            'current_password' => 'a-long-test-passphrase', 'password' => 'a-new-long-passphrase',
            'password_confirmation' => 'a-new-long-passphrase',
        ])->assertRedirect(route('admin.login'))->assertSessionHas('status');
        $this->assertGuest();
        $this->assertDatabaseMissing('sessions', ['user_id' => $admin->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-device-'.$other->id]);
        $this->assertTrue(Hash::check('a-new-long-passphrase', $admin->fresh()->password));
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'a-long-test-passphrase'])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'a-new-long-passphrase'])->assertRedirect(route('seo.index'));
    }

    public function test_guests_and_regular_users_cannot_read_or_mutate_seo(): void
    {
        $this->get('/admin/seo')->assertRedirect(route('admin.login'));
        $this->post('/admin/seo/drafts', [])->assertRedirect(route('admin.login'));
        $this->get('/admin/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('Sign in');
        $user = User::factory()->create(['email' => 'reader@example.test', 'password' => 'a-long-test-passphrase']);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'a-long-test-passphrase'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($user)->get('/admin/seo')->assertForbidden();
        $this->post('/admin/seo/drafts', [])->assertForbidden();
        $this->assertDatabaseCount('seo_batches', 0);
    }

    public function test_administrator_sign_in_sign_out_and_permission_revocation(): void
    {
        $admin = $this->administrator();
        $this->post('/admin/login', ['email' => 'ADMIN@EXAMPLE.TEST', 'password' => 'a-long-test-passphrase'])->assertRedirect(route('seo.index'));
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin/seo')->assertOk()->assertSee('Sign out');
        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->get('/admin/seo')->assertRedirect(route('admin.login'));
        $admin->is_admin = false;
        $admin->save();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'a-long-test-passphrase'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_attempts_are_limited_and_errors_do_not_identify_accounts(): void
    {
        $this->administrator();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => 'admin@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors(['email' => 'Unable to sign in with these details.']);
        }
        $this->post('/admin/login', ['email' => 'admin@example.test', 'password' => 'a-long-test-passphrase'])->assertSessionHasErrors(['email' => 'Too many attempts. Please wait one minute before trying again.']);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/admin/login', ['email' => 'admin@example.test', 'password' => 'a-long-test-passphrase'])->assertRedirect(route('seo.index'));
        $this->assertAuthenticated();
        $this->travelBack();
    }

    public function test_first_account_setup_is_local_single_use_and_hashes_password(): void
    {
        $this->get('/admin/setup')->assertOk()->assertSee('Create administrator');
        $this->post('/admin/setup', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/admin/setup', ['name' => 'Owner', 'email' => 'OWNER@EXAMPLE.TEST', 'password' => 'a-private-test-passphrase', 'password_confirmation' => 'a-private-test-passphrase'])->assertRedirect(route('seo.index'));
        $admin = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('a-private-test-passphrase', $admin->password));
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin/setup')->assertNotFound();
        $this->post('/admin/setup', [])->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_remote_setup_is_blocked_and_production_admin_requires_https(): void
    {
        $admin = $this->administrator();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])->get('https://example.test/admin/setup')->assertForbidden();
        $this->post('https://example.test/admin/setup', [])->assertForbidden();
        $this->app->instance('env', 'production');
        $this->actingAs($admin)->get('http://example.test/admin/seo')->assertForbidden();
        $this->get('https://example.test/admin/seo')->assertOk();
        Auth::logout();
        $this->get('https://example.test/admin/seo')->assertRedirect(route('admin.login'));
    }

    public function test_console_creation_uses_validation_and_does_not_promote_existing_users(): void
    {
        $this->artisan('admin:create')->expectsQuestion('Name', 'Owner')->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password (at least 12 characters)', 'a-long-test-passphrase')->expectsQuestion('Confirm password', 'a-long-test-passphrase')->assertExitCode(0);
        $this->assertTrue(User::where('email', 'owner@example.test')->firstOrFail()->is_admin);
        $this->artisan('admin:create')->expectsQuestion('Name', 'Another')->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password (at least 12 characters)', 'a-different-passphrase')->expectsQuestion('Confirm password', 'a-different-passphrase')->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }
}
