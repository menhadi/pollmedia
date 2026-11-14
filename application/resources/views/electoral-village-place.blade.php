<?php $topic = \App\Services\PlaceTopics::selected('village'); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $village['name'] }} · LGD village · Pollmedia</title><meta name="description" content="{{ $village['name'] }} village population, literacy, household tap connections and available soil sample facts, with reference dates and official sources."><link rel="canonical" href="{{ app(\App\Services\NamedPlaceUrls::class)->village($village) }}"><link rel="stylesheet" href="{{ asset('css/electoral-village-map.css') }}"><link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}"><link rel="stylesheet" href="{{ asset('css/census-area-dashboard.css') }}?v={{ substr(hash_file('sha256',public_path('css/census-area-dashboard.css')),0,12) }}">@include('site-theme')<?php if (in_array($topic, ['map'], true)): ?>
@include('india-map-assets')
<?php endif; ?><link rel="stylesheet" href="{{ asset('css/census-profile.css') }}"><link rel="stylesheet" href="{{ asset('css/village-consistent.css') }}?v={{ substr(hash_file('sha256',public_path('css/village-consistent.css')),0,12) }}"><script src="{{ asset('js/history-lines.js') }}" defer></script>@include('place-layout-assets')</head><body class="place-layout election-ui census-area-ui">@include('public-header')
<main class="place-page area-dashboard" data-local-dashboard><div class="village-breadcrumb-bar">@include('location-breadcrumbs',['locationTrail'=>app(\App\Services\PlaceBreadcrumbs::class)->village($village)])</div><h1 class="village-heading">{{ $village['name'] }}</h1><p><a href="{{ route('community.village',$village['lgd']) }}">Edit data</a></p><p>{{ $village['subdistrict'] }} · {{ $village['district'] }} · {{ $village['state_name'] }}</p><p data-methodology-note>Official LGD village code: {{ $village['lgd'] }}</p>
@include('local-board-place-link', ['boardArea' => 'village:'.$village['lgd']])
@include('place-topic-nav',['topicGroup'=>'village'])
<div data-topic-body>
<?php if (in_array($topic, ['issues'], true)): ?>
@php $issueService=app(\App\Services\CommunityAreaIssues::class); $issueArea=$issueService->context('village',$village['name'],'',(string)$village['lgd']); @endphp
@include('community-area-issues')
<?php endif; ?>
@include('lgd-village-dashboard-records')
@if($topic === 'overview')
@include('rural-employment', ['employment' => app(\App\Services\RuralEmployment::class)->forVillage((int)($village['state'] ?? 0), (int)$village['lgd'])])
@endif
</div>
</main>@include('community-edit-tools')<script type="module" src="{{ asset('js/census-local-dashboard.js') }}?v={{ substr(hash_file('sha256',public_path('js/census-local-dashboard.js')),0,12) }}"></script>@include('public-footer')</body></html>
