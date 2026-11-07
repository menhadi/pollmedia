<!doctype html>
<html lang="{{ $hi ? 'hi' : 'en' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }} | Pollmedia</title>
    <link rel="stylesheet" href="{{ asset('css/gram-panchayat-directory.css') }}">
</head>
<body>
@include('public-header')
<main class="gp-page">
    @yield('directory-content')
</main>
</body></html>
