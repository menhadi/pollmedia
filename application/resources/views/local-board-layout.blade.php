<!doctype html>
<html lang="{{ request('lang') === 'hi' ? 'hi' : 'en' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('title', 'Local jobs & services') · Pollmedia</title>
<meta name="description" content="Find local jobs and services by village, Gram Panchayat, district or city. Post a listing for review with a clear expiry date.">
@if(!request()->routeIs('local-board.index', 'local-board.show') || (isset($active) && !$active))<meta name="robots" content="noindex,nofollow">@endif
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/local-board.css') }}?v={{ substr(hash_file('sha256', public_path('css/local-board.css')), 0, 12) }}">
<script defer src="{{ asset('js/local-board.js') }}?v={{ substr(hash_file('sha256', public_path('js/local-board.js')), 0, 12) }}"></script>
</head><body class="election-ui">
@include('public-header', ['summaryLayout' => true])
<main class="local-board">
<nav class="board-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('home') }}">India</a><span>›</span><a href="{{ route('local-board.index') }}">Jobs & services</a>@if(!empty($area))<span>›</span><a href="{{ $area['url'] }}">{{ $area['name'] }}</a>@endif</nav>
<nav class="board-actions" aria-label="Local board"><a href="{{ route('local-board.index', !empty($area) ? ['area' => $area['key']] : []) }}">Browse</a><a href="{{ route('local-board.create', !empty($area) ? ['area' => $area['key']] : []) }}">Post a job or service</a>@auth<a href="{{ route('local-board.mine') }}">My listings</a>@if(auth()->user()->is_admin)<a href="{{ route('local-board.review') }}">Review listings</a>@endif @endauth</nav>
@if(session('status'))<p class="board-notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="board-errors" role="alert"><p>Please check these fields:</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('board-content')
</main>@include('public-footer')</body></html>
