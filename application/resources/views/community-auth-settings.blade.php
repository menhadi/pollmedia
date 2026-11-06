@extends('seo-layout')
@section('content')
@include('community-style')
<h1>Contributor login settings</h1><p>Configure Google sign-in and email codes. Administrator sign-in stays separate. Configuration status does not prove a successful login or delivery.</p>
<p><strong>Google:</strong> {{ $googleReady ? 'Configured' : 'Not configured' }} · <strong>Email OTP:</strong> {{ $emailReady ? 'Configured' : 'Not configured' }}</p>
<form method="post" action="{{ route('community.auth.save') }}" autocomplete="off" class="card data-edit">@csrf
<h2>Google sign-in</h2><input type="hidden" name="google_enabled" value="0">
<label><input type="checkbox" name="google_enabled" value="1" @checked(old('google_enabled',$settings['google_enabled'])) style="width:auto"> Enable Google sign-in</label>
<label>OAuth client ID<input name="google_client_id" value="{{ old('google_client_id',$settings['google_client_id']) }}" maxlength="500" spellcheck="false"></label>
<label>OAuth client secret<input name="google_client_secret" type="password" autocomplete="new-password" maxlength="4096" placeholder="{{ $settings['google_secret_set'] ? 'Saved — leave blank to keep' : 'Not configured' }}"></label>
<label><input name="clear_google_secret" type="checkbox" value="1" style="width:auto"> Remove saved Google secret (disable Google first)</label>
<p class="data-note">Create a Web application OAuth client in Google Cloud. Register this exact authorized redirect URI:<br><code>{{ route('community.google.callback') }}</code><br>In Google testing mode, add your test accounts to the consent screen’s test users.</p>
<h2>Email verification codes</h2><input type="hidden" name="email_enabled" value="0">
<label><input type="checkbox" name="email_enabled" value="1" @checked(old('email_enabled',$settings['email_enabled'] ?? $emailReady)) style="width:auto"> Enable email OTP</label>
<label>SMTP host<input name="smtp_host" value="{{ old('smtp_host',$settings['smtp_host']) }}" maxlength="253" placeholder="smtp.your-provider.com"></label>
<label>SMTP port<input name="smtp_port" type="number" min="1" max="65535" value="{{ old('smtp_port',$settings['smtp_port']) }}" required></label>
<label>Connection security<select name="smtp_security"><option value="starttls" @selected(old('smtp_security',$settings['smtp_security'])==='starttls')>STARTTLS (usually port 587)</option><option value="tls" @selected(old('smtp_security',$settings['smtp_security'])==='tls')>TLS (usually port 465)</option></select></label>
<label>SMTP username<input name="smtp_username" value="{{ old('smtp_username',$settings['smtp_username']) }}" maxlength="254" autocomplete="off"></label>
<label>SMTP password / app password<input name="smtp_password" type="password" autocomplete="new-password" maxlength="4096" placeholder="{{ $settings['smtp_password_set'] ? 'Saved — leave blank to keep' : 'Not configured' }}"></label>
<label><input type="checkbox" name="clear_smtp_password" value="1" style="width:auto"> Remove saved SMTP password</label>
<label>Sender email address<input name="from_address" type="email" value="{{ old('from_address',$settings['from_address']) }}" maxlength="254" placeholder="A sender verified with your provider"></label>
<label>Sender name<input name="from_name" value="{{ old('from_name',$settings['from_name']) }}" maxlength="100" required></label>
<p class="data-note">Secrets are encrypted and never returned in this form or written to the settings audit. Blank secret fields retain saved values. Saving does not send an email or contact Google. Email settings apply only to contributor OTP.</p><button class="data-button primary">Save login settings</button></form>
<p class="data-note">To test login, use a private browser window and a contributor email different from your administrator account. After submitting a correction there, return to admin to approve it.</p><p><a href="{{ route('community.login') }}" target="_blank" rel="noopener">Open contributor sign-in ↗</a> · <a href="{{ route('community.review') }}">Review corrections →</a></p>
@endsection
