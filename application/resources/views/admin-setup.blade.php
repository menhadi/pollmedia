@extends('seo-layout')
@section('content')
<div style="max-width:560px;margin:auto"><p class="muted">FIRST ACCOUNT / LOCAL SETUP</p><h1>Create administrator</h1><p>This setup is available only on this computer and closes after the first administrator is created.</p>
<form method="post" action="{{ route('admin.setup.store') }}" class="card">@csrf
<div class="field"><label for="name">Name</label><input id="name" type="text" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="100"></div>
<div class="field"><label for="email">Email</label><input style="width:100%" id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required maxlength="255"></div>
<div class="field"><label for="password">Password</label><input style="width:100%" id="password" type="password" name="password" autocomplete="new-password" required></div>
<div class="field"><label for="password_confirmation">Confirm password</label><input style="width:100%" id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required></div><button>Create administrator and sign in</button></form></div>
@endsection
