<!doctype html>
<html lang="{{ $hi ? 'hi' : 'en' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }} | Pollmedia</title>
    <link rel="stylesheet" href="{{ asset('css/electoral-village-map.css') }}">
    <link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/census-area-dashboard.css') }}">
    @include('site-theme')
    <link rel="stylesheet" href="{{ asset('css/place-layout.css') }}">
    @if(isset($record))
        @if(\App\Services\PlaceTopics::selected('gp')==='village-data' && \App\Services\PlaceTopics::selected('member')==='map') @include('india-map-assets') @endif
        <link rel="stylesheet" href="{{ asset('css/census-profile.css') }}">
        @if(\App\Services\PlaceTopics::selected('gp')==='village-data' && \App\Services\PlaceTopics::selected('member')==='history') <script defer src="{{ asset('js/history-lines.js') }}"></script> @endif
    @endif
    <link rel="stylesheet" href="{{ asset('css/gram-panchayat-directory.css') }}?v={{ substr(hash_file('sha256',public_path('css/gram-panchayat-directory.css')),0,12) }}">
</head>
<body class="place-layout election-ui census-area-ui">
@include('public-header')
<main class="place-page area-dashboard {{ isset($record)?'gp-dashboard':'gp-page' }}" @if(isset($record)) data-local-dashboard @endif>
    @yield('directory-content')
</main>
@include('public-footer')
</body></html>
