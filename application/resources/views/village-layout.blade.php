<!doctype html>

<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">@php

    $canonical = url()->current().($year === '2001' ? '?year=2001' : '');

    $breadcrumbs = ['India' => route('home'), 'Uttar Pradesh' => route('states.show', ['state' => 'uttar-pradesh']), 'Pilibhit district' => route('places.show', ['type' => 'district', 'slug' => 'pilibhit']), 'Census '.$year.' villages' => route('villages.index', $year === '2001' ? ['year' => $year] : [])];

    if (isset($village)) {

        $breadcrumbs[$village['name'].' village'] = $canonical;

    }

@endphp

@include('seo-metadata', ['seoTitle' => $__env->yieldContent('title').' · Pollmedia', 'seoDescription' => $__env->yieldContent('description')])

<link rel="stylesheet" href="/css/villages.css"><link rel="stylesheet" href="/css/election-dashboard.css?v={{ substr(hash_file('sha256',public_path('css/election-dashboard.css')),0,12) }}"><script src="/js/instant-filters.js?v={{ substr(hash_file('sha256',public_path('js/instant-filters.js')),0,12) }}" defer></script></head>

<body>@include('public-header')<main>

<nav class="crumb" aria-label="Breadcrumb"><a href="{{ route('india') }}">India</a> / <a href="{{ route('states.show', ['state'=>'uttar-pradesh']) }}">Uttar Pradesh</a> / <a href="{{ route('places.show',['type'=>'district','slug'=>'pilibhit']) }}">Pilibhit district</a> / <a href="{{ route('villages.index',['year'=>$census['year']]) }}">Census villages</a></nav>

@yield('content')

<section id="sources" class="card"><div class="kicker">Evidence & dates</div><h2>Sources & references</h2><h3>Census population and households</h3><p>Census of India · Primary Census Abstract · {{ $census['year'] }} · Worksheet {{ $census['sheet'] }}</p><div class="links"><a href="{{ $census['landing'] }}" target="_blank" rel="noreferrer">Official catalogue ↗</a><a href="{{ $census['source_url'] }}" target="_blank" rel="noreferrer">Original Census workbook ↗</a></div><p class="small">Source retrieved {{ substr($release->retrieved_at,0,10) }}. This is a historical Census baseline, not a current population estimate.</p><details><summary>Source edition</summary><p class="hash">SHA-256: {{ $release->sha256 }}</p><p class="small">The source fingerprint identifies the imported workbook edition. Coverage: {{ count($census['villages']) }} villages out of {{ number_format($census['levels']['VILLAGE']) }} village records in this district workbook.</p></details>

<h3>Historical village connections</h3><p><a href="{{ $mapping['url'] }}" target="_blank" rel="noreferrer">Census 2011 district handbook · block and location-code tables ↗</a></p><p class="small">Checked {{ $mapping['checked_on'] }}. These connections do not establish unchanged boundaries between Census editions.</p>

<h3>Current administration</h3><p><a href="{{ $lgd['url'] }}" target="_blank" rel="noreferrer">Local Government Directory · village, block and gram panchayat exports ↗</a></p><p class="small">Snapshot downloaded {{ $lgd['checked_on'] }}. Connections use the official Census identifiers recorded by LGD.</p>

<h3>Electoral connections</h3><p><a href="{{ $electoral['url'] }}" target="_blank" rel="noreferrer">LGD · Pilibhit PC/AC mapping report ↗</a></p><p class="small">Checked {{ $electoral['checked_on'] }}. Unmatched villages remain unassigned. Constituency mappings do not provide village voting totals.</p>

<p><a href="{{ route('sources.index') }}">All sources & update status →</a></p>

</section>

<footer>Pollmedia · Local research pilot · Official sources, dated measurements and explicit coverage.</footer></main></body></html>
