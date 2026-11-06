<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CommunityAuthSettings;
use App\Services\CommunityData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommunityAuthSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(array $extra = []): array
    {
        return array_replace(['google_enabled' => 1, 'google_client_id' => 'test-client.apps.googleusercontent.com', 'google_client_secret' => 'google-test-secret', 'email_enabled' => 1, 'smtp_host' => 'smtp.example.org', 'smtp_port' => 587, 'smtp_security' => 'starttls', 'smtp_username' => 'sender@example.org', 'smtp_password' => 'smtp-test-secret', 'from_address' => 'sender@example.org', 'from_name' => 'Pollmedia'], $extra);
    }

    public function test_only_admin_can_manage_settings_and_secrets_never_render_or_enter_the_audit(): void
    {
        $this->get(route('community.auth.settings'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->post(route('community.auth.save'), $this->settings())->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('community.auth.save'), $this->settings())->assertRedirect(route('community.auth.settings'));
        $this->get(route('community.auth.settings'))->assertOk()->assertSee('Saved — leave blank to keep')->assertDontSee('google-test-secret')->assertDontSee('smtp-test-secret')->assertSee('/sign-in/google/callback');
        $stored = DB::table('site_settings')->where('key', 'community-auth')->value('value');
        $this->assertStringNotContainsString('smtp-test-secret', $stored);
        $this->assertStringNotContainsString('google-test-secret', $stored);
        $this->assertStringNotContainsString('smtp-test-secret', DB::table('site_changes')->latest('id')->value('after_value'));
        $this->assertSame('smtp-test-secret', app(CommunityAuthSettings::class)->values()['smtp_password']);
        $this->post(route('community.auth.save'), $this->settings(['smtp_password' => '  exact-secret-with-spaces  ']))->assertRedirect();
        $this->assertSame('  exact-secret-with-spaces  ', app(CommunityAuthSettings::class)->values()['smtp_password']);
        $this->post(route('community.auth.save'), $this->settings(['google_client_secret' => '', 'smtp_password' => '']))->assertRedirect();
        $this->assertSame('google-test-secret', app(CommunityAuthSettings::class)->values()['google_client_secret']);
        $this->assertSame('  exact-secret-with-spaces  ', app(CommunityAuthSettings::class)->values()['smtp_password']);
        $this->post(route('community.auth.save'), $this->settings(['smtp_port' => 99999]))->assertSessionHasErrors('smtp_port')->assertSessionMissing('_old_input.smtp_password')->assertSessionMissing('_old_input.google_client_secret');
        $this->post(route('community.auth.save'), $this->settings(['google_enabled' => 0, 'email_enabled' => 0, 'clear_google_secret' => 1, 'clear_smtp_password' => 1]))->assertRedirect();
        $this->assertFalse(app(CommunityAuthSettings::class)->googleReady());
        $this->assertFalse(app(CommunityAuthSettings::class)->emailReady());
        $this->post(route('community.verify'), ['code' => '123456'])->assertStatus(503);
    }

    private function record(): int
    {
        $connector = DB::table('import_connectors')->insertGetId(['name' => 'Test source', 'url' => 'https://censusindia.gov.in/test.xls', 'format' => 'xls', 'record_key' => 'code', 'options' => '{}']);
        $run = DB::table('import_runs')->insertGetId(['import_connector_id' => $connector, 'source_url' => 'https://censusindia.gov.in/test.xls', 'origin' => 'url', 'status' => 'accepted', 'sha256' => str_repeat('a', 64), 'extracted' => '{}', 'created_at' => now()]);
        $edition = DB::table('census_editions')->insertGetId(['import_run_id' => $run, 'source_key' => 'workflow-test', 'name' => 'Census fixture', 'year' => 2011, 'status' => 'published', 'sha256' => str_repeat('a', 64), 'source_url' => 'https://censusindia.gov.in/test.xls', 'landing_url' => 'https://censusindia.gov.in', 'scope' => 'Isolated test record', 'fields' => '["TOT_P"]', 'row_count' => 1, 'retrieved_at' => now()]);
        DB::table('census_publications')->insert(['source_key' => 'workflow-test', 'edition_id' => $edition]);

        return DB::table('census_catalogue_rows')->insertGetId(['edition_id' => $edition, 'record_key' => str_repeat('b', 64), 'state_code' => '09', 'district_code' => '151', 'level' => 'VILLAGE', 'residence' => 'Rural', 'name' => 'Workflow village', 'geography' => '{}', 'values' => '{"TOT_P":100}', 'flags' => '[]', 'source_row' => 2]);
    }

    private function correctAndApprove(int $record, User $admin): void
    {
        $this->assertFalse((bool) auth()->user()->is_admin);
        $this->get(route('community.record', $record))->assertOk()->assertSee('Submit for admin review');
        $this->post(route('community.propose', $record), ['field' => 'TOT_P', 'value_type' => 'number', 'value' => '101', 'reason' => 'The original source contains 101 persons.', 'expected' => app(CommunityData::class)->fingerprint(app(CommunityData::class)->record($record))])->assertRedirect();
        $this->assertSame(100, json_decode(DB::table('census_catalogue_rows')->find($record)->values, true)['TOT_P']);
        $proposal = DB::table('community_data_proposals')->first();
        $this->post(route('community.decide', $proposal->id), ['decision' => 'approved', 'review_note' => 'Checked source and confirmed this correction.'])->assertForbidden();
        $this->post(route('community.logout'));
        $this->actingAs($admin)->get(route('community.review'))->assertOk()->assertSee('The original source contains 101 persons.');
        $this->post(route('community.decide', $proposal->id), ['decision' => 'approved', 'review_note' => 'Checked source and confirmed this correction.'])->assertRedirect();
        $this->post(route('community.logout'));
        $this->get(route('community.record', $record))->assertOk()->assertSee('100 → 101')->assertSee('Checked source and confirmed this correction.')->assertSee('Community correction');
        $this->assertDatabaseHas('community_data_proposals', ['id' => $proposal->id, 'status' => 'approved']);
    }

    public function test_configured_email_otp_through_suggestion_admin_approval_and_public_history(): void
    {
        $record = $this->record();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('community.auth.save'), $this->settings())->assertRedirect();
        $this->post(route('community.logout'));
        $code = null;
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$code): bool {
            $this->assertSame('sender@example.org', $event->message->getFrom()[0]->getAddress());
            preg_match('/code is ([0-9]{6})/', $event->message->getTextBody(), $matches);
            $code = $matches[1];

            return false;
        });
        $this->get(route('community.login', ['next' => route('community.record', $record, false)]))->assertOk();
        $this->post(route('community.email'), ['email' => 'contributor@example.org'])->assertRedirect();
        $this->assertNotNull($code);
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('smtp', config('mail.mailers.community_otp.transport'));
        $this->assertTrue(config('mail.mailers.community_otp.require_tls'));
        $this->post(route('community.verify'), ['code' => $code])->assertRedirect(route('community.record', $record));
        $this->correctAndApprove($record, $admin);
    }

    public function test_configured_google_code_exchange_verified_identity_suggestion_and_admin_approval(): void
    {
        $record = $this->record();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('community.auth.save'), $this->settings())->assertRedirect();
        $this->post(route('community.logout'));
        $this->get(route('community.google'))->assertRedirectContains('test-client.apps.googleusercontent.com');
        $state = session('community.google');
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $configuration = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($configuration)) {
            $options['config'] = $configuration;
        }
        $key = openssl_pkey_new($options);
        Cache::put('community.google.certificates', ['test-key' => openssl_pkey_get_details($key)['key']], 300);
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'test-client.apps.googleusercontent.com', 'sub' => 'test-subject', 'exp' => time() + 600, 'iat' => time(), 'nonce' => $state['nonce'], 'email' => 'google-contributor@example.org', 'email_verified' => true];
        $token = $encode(json_encode(['alg' => 'RS256', 'kid' => 'test-key'])).'.'.$encode(json_encode($claims));
        openssl_sign($token, $signature, $key, OPENSSL_ALGO_SHA256);
        $token .= '.'.$encode($signature);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['id_token' => $token])]);
        $this->get(route('community.google.callback', ['code' => 'isolated-test-code', 'state' => $state['state']]))->assertRedirect();
        $this->assertAuthenticated();
        Http::assertSent(fn ($request): bool => $request['client_id'] === 'test-client.apps.googleusercontent.com' && $request['client_secret'] === 'google-test-secret');
        $this->correctAndApprove($record, $admin);
        $this->assertDatabaseHas('community_google_accounts', ['subject' => 'test-subject']);
    }
}
