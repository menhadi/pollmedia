@extends('seo-layout')
@section('content')
<div style="max-width:520px;margin:auto"><p class="muted">POLLMEDIA ADMINISTRATION</p><h1>Sign in</h1><p>Use your administrator account to manage SEO drafts and page metadata.</p>
<form method="post" action="{{ route('admin.authenticate') }}" class="card">@csrf
<div class="field"><label for="email">Email</label><input style="width:100%" id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required maxlength="255" autofocus></div>
<div class="field"><label for="password">Password</label><input style="width:100%" id="password" type="password" name="password" autocomplete="current-password" required maxlength="128"></div><button>Sign in</button></form>
@if($canSetup)
<p class="notice">No administrator account exists yet. <a href="{{ route('admin.setup') }}">Create the first administrator</a> on this computer.</p>
@endif
<p class="muted">Accounts are created by the site owner. Contact the site owner if you need access.</p></div>
@endsection
