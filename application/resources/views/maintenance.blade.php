@extends('seo-layout')
@section('content')
<h1>Maintenance mode</h1>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<section class="card">
    <h2>{{ $enabled ? 'Public access is paused' : 'Website is live' }}</h2>
    @if($enabled)
    <p>You can browse public pages in this browser while maintenance remains enabled for visitors. Access lasts 12 hours and ends when maintenance is disabled or enabled again.</p>
    <form method="post" action="{{ route('maintenance.preview') }}">
        @csrf
        <button type="submit">Open website for me — keep public maintenance on</button>
    </form>
    @endif
    <p>Temporarily pause public pages and APIs during maintenance or unwanted bot traffic. Visitors receive a temporary-unavailable response. Admin sign-in and administration remain accessible.</p>
    <p>Maintenance also pauses normal queue workers and scheduled tasks unless they are configured to run during maintenance. This reduces application work; large attacks still need protection at the firewall or hosting provider.</p>
    <form method="post" action="{{ route('maintenance.update') }}">
        @csrf
        <input type="hidden" name="enabled" value="{{ $enabled ? 0 : 1 }}">
        <button type="submit">{{ $enabled ? 'Disable maintenance — restore website' : 'Enable maintenance — pause public access' }}</button>
    </form>
</section>
@endsection
