<?php

namespace App\Http\Controllers;

use App\Services\CommunityAuthSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CommunityAuthSettingsController extends Controller
{
    public function index(CommunityAuthSettings $settings): View
    {
        $values = $settings->values();
        $values['google_secret_set'] = filled($values['google_client_secret']);
        $values['smtp_password_set'] = filled($values['smtp_password']);
        unset($values['google_client_secret'], $values['smtp_password']);

        return view('community-auth-settings', ['settings' => $values, 'googleReady' => $settings->googleReady(), 'emailReady' => $settings->emailReady()]);
    }

    public function save(Request $request, CommunityAuthSettings $settings): RedirectResponse
    {
        $input = $request->validate([
            'google_enabled' => 'nullable|boolean', 'google_client_id' => 'nullable|string|max:500|regex:/^[A-Za-z0-9._-]+$/', 'google_client_secret' => 'nullable|string|max:4096|regex:/^\S+$/',
            'email_enabled' => 'nullable|boolean', 'smtp_host' => 'nullable|string|max:253|regex:/^[A-Za-z0-9.-]+$/', 'smtp_port' => 'required|integer|min:1|max:65535', 'smtp_security' => 'required|in:starttls,tls',
            'smtp_username' => 'nullable|string|max:254', 'smtp_password' => 'nullable|string|max:4096', 'from_address' => 'nullable|email:rfc|max:254', 'from_name' => 'required|string|max:100',
            'clear_google_secret' => 'nullable|boolean', 'clear_smtp_password' => 'nullable|boolean',
        ]);
        $values = $settings->values();
        foreach (['google_client_id', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'from_address', 'from_name'] as $key) {
            $values[$key] = $input[$key] ?? '';
        }
        foreach (['google_client_secret' => 'clear_google_secret', 'smtp_password' => 'clear_smtp_password'] as $key => $clear) {
            if ($request->boolean($clear)) {
                $values[$key] = '';
            } elseif (filled($input[$key] ?? null)) {
                $values[$key] = $input[$key];
            }
        }
        $values['google_enabled'] = $request->boolean('google_enabled');
        $values['email_enabled'] = $request->boolean('email_enabled');
        if ($values['google_enabled'] && (! filled($values['google_client_id']) || ! filled($values['google_client_secret']))) {
            throw ValidationException::withMessages(['google_client_id' => 'Enter a Google client ID and secret before enabling Google sign-in.']);
        }
        if ($values['email_enabled'] && (! filled($values['smtp_host']) || ! filled($values['from_address']))) {
            throw ValidationException::withMessages(['smtp_host' => 'Enter an SMTP host and sender address before enabling email codes.']);
        }
        $values['smtp_port'] = (int) $values['smtp_port'];
        $settings->save($values, $request->user()->id);

        return redirect()->route('community.auth.settings')->with('status', 'Contributor sign-in settings saved securely. Blank secret fields retain saved credentials. No email or Google request was sent.');
    }
}
