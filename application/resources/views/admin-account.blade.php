@extends('seo-layout')
@section('content')
<div style="max-width:600px;margin:auto">
<p class="muted">POLLMEDIA ADMINISTRATION</p><h1>Your account</h1>
<p>Signed in as <strong>{{ auth()->user()->email }}</strong>.</p>
<form method="post" action="{{ route('admin.password') }}" class="card">@csrf
<h2>Change password</h2>
<p>You will need to sign in again after changing your password.</p>
<div class="field"><label for="current-password">Current password</label><input id="current-password" type="password" name="current_password" autocomplete="current-password" required></div>
<div class="field"><label for="new-password">New password</label><input id="new-password" type="password" name="password" autocomplete="new-password" required></div>
<div class="field"><label for="confirm-password">Confirm new password</label><input id="confirm-password" type="password" name="password_confirmation" autocomplete="new-password" required></div>
<button type="submit">Change password</button>
</form></div>
@endsection
