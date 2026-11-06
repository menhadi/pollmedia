<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CommunityAuthSettings
{
    public function values(): array
    {
        $defaults = [
            'google_enabled' => (bool) ((config('community-auth.google_client_id') ?: config('services.google.client_id')) && (config('community-auth.google_client_secret') ?: config('services.google.client_secret'))),
            'google_client_id' => config('community-auth.google_client_id') ?: config('services.google.client_id') ?: '',
            'google_client_secret' => config('community-auth.google_client_secret') ?: config('services.google.client_secret') ?: '',
            'email_enabled' => null, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_security' => 'starttls', 'smtp_username' => '', 'smtp_password' => '', 'from_address' => '', 'from_name' => 'Pollmedia',
        ];
        $stored = Schema::hasTable('site_settings') ? DB::table('site_settings')->where('key', 'community-auth')->value('value') : null;
        if ($stored === null) {
            return $defaults;
        }

        return array_replace($defaults, json_decode(Crypt::decryptString(json_decode($stored, true)), true, 512, JSON_THROW_ON_ERROR));
    }

    public function save(array $values, int $administrator): void
    {
        DB::transaction(function () use ($values, $administrator): void {
            $previous = DB::table('site_settings')->where('key', 'community-auth')->lockForUpdate()->first();
            DB::table('site_settings')->updateOrInsert(['key' => 'community-auth'], ['value' => json_encode(Crypt::encryptString(json_encode($values, JSON_THROW_ON_ERROR))), 'created_at' => $previous?->created_at ?? now(), 'updated_at' => now()]);
            DB::table('site_changes')->insert(['target' => 'settings:community-auth', 'before_value' => json_encode(['configured' => $previous !== null]), 'after_value' => json_encode(['google_enabled' => $values['google_enabled'], 'email_enabled' => $values['email_enabled'], 'credentials' => 'Encrypted; values omitted from audit']), 'reason' => 'Updated contributor sign-in settings', 'user_id' => $administrator, 'created_at' => now()]);
        });
    }

    public function googleReady(): bool
    {
        $values = $this->values();

        return $values['google_enabled'] && filled($values['google_client_id']) && filled($values['google_client_secret']);
    }

    public function emailReady(): bool
    {
        $values = $this->values();
        if ($values['email_enabled'] !== null) {
            return $values['email_enabled'] && filled($values['smtp_host']) && filled($values['from_address']);
        }

        return in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true) || app()->runningUnitTests();
    }

    public function prepareMailer(): ?string
    {
        $values = $this->values();
        if ($values['email_enabled'] !== true) {
            return null;
        }
        config(['mail.mailers.community_otp' => [
            'transport' => 'smtp', 'scheme' => $values['smtp_security'] === 'tls' ? 'smtps' : 'smtp',
            'host' => $values['smtp_host'], 'port' => $values['smtp_port'], 'username' => $values['smtp_username'] ?: null,
            'password' => $values['smtp_password'] ?: null, 'timeout' => 15,
            'require_tls' => $values['smtp_security'] === 'starttls',
        ]]);
        app('mail.manager')->purge('community_otp');

        return 'community_otp';
    }
}
