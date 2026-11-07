@extends('village-layout')
@section('title', $village['name'].' village · Census '.$census['year'])
@section('description', $village['name'].' village in the Pilibhit Census '.$census['year'].' district dataset: population, households, administrative identifiers and official source links.')
@section('content')
<?php $topic = \App\Services\PlaceTopics::selected('village'); ?>
<div class="kicker">Census village · {{ $village['code'] }}</div><h1>{{ $village['name'] }}</h1><p><a href="{{ !empty($mapVillageCode) ? route('community.village',$mapVillageCode) : route('community.scope',['state'=>'09','district'=>(string)$year==='2001'?'21':'151','year'=>$year,'level'=>'VILLAGE','village'=>$village['code']]) }}">Edit data</a></p><p class="lead">A village profile from the Pilibhit district Census baseline, with official measurements and their original reference year.</p><div class="links"><a href="{{ route('villages.index',['year'=>$census['year'],'subdistrict'=>$village['subdistrict_code']]) }}">← Village dropdowns</a><a href="{{ route('places.show',['type'=>'district','slug'=>'pilibhit']) }}">Pilibhit district →</a></div>
@include('place-topic-nav',['topicGroup'=>'village'])
<div data-topic-body>
<?php if (in_array($topic, ['issues'], true)): ?>
@php $issueService=app(\App\Services\CommunityAreaIssues::class); $issueArea=$issueService->context('village',$village['name'],'',(string)($mapVillageCode ?? '')); @endphp
@include('community-area-issues')
<?php endif; ?>
<?php if (in_array($topic, ['overview'], true)): ?>
<section id="baseline"><div class="kicker">Official baseline / {{ $census['year'] }}</div><h2>Village at a glance</h2><div class="stats">@foreach(['population'=>'Population','households'=>'Households','literate'=>'Literate population','population_0_6'=>'Children aged 0–6'] as $key=>$label)<div class="stat"><span>{{ $label }}</span><strong>{{ number_format($village[$key]) }}</strong><small>Census {{ $census['year'] }}</small></div>@endforeach</div><p class="small"><a href="{{ $census['source_url'] }}" target="_blank" rel="noreferrer">Official Census workbook ↗</a> · Worksheet {{ $census['sheet'] }} · Level VILLAGE · Town/Village code {{ $village['code'] }}. These are counts; no literacy rate is inferred.</p></section>
<?php endif; ?>
<?php if (in_array($topic, ['map'], true)): ?>
<?php $censusMapContext=app(\App\Services\CensusAreaPresentation::class)->lgdMap((string)($mapVillageCode??'')); ?>
@include('census-local-map',['localMapTitle'=>$village['name'].' · map & filters'])
<p class="notice">Historical geography: Census {{ $census['year'] }}. Current directory records matched by official Census code appear separately below. Available electoral connections are shown with their own source date; polling-part relationships remain pending.</p>
<section id="geography" class="card"><div class="kicker">Map & location</div><h2>Locate the village</h2>
@if($mapVillageCode ?? null)
<p>This dated Census record is linked to current LGD village {{ $mapVillageCode }}. The maps show available source boundaries; they do not establish the village's {{ $year }} boundary or comparability across years.</p>
<div class="links"><a class="button" href="{{ route('india-map.index', ['village' => $mapVillageCode]) }}">Show village on India map →</a><a class="button" href="{{ route('district-dashboard.named', ['state' => 'uttar-pradesh', 'district' => 'pilibhit', 'village' => $mapVillageCode]) }}#map">Show village on Pilibhit district map →</a></div>
@else
<p>A unique verified current village link is pending. The district map provides regional context.</p><a class="button" href="{{ route('district-dashboard', ['state' => '09', 'district' => '151']) }}#map">Explore the district map →</a>
@endif
</section>
<?php endif; ?>
<?php if (in_array($topic, ['people', 'soil'], true)): ?>
@include('place-village-facts',['onlyTopic'=>$topic])
<?php endif; ?>
<?php if (in_array($topic, ['rainfall'], true)): ?>
@include('rainfall-village-annual',['factCodes'=>collect($currentRecords)->pluck('code')->map(fn($code)=>(string)$code)->all()])
<?php endif; ?>
<?php if (in_array($topic, ['history'], true)): ?>
@include('census-local-history')
<?php endif; ?>
<?php if (in_array($topic, ['places'], true)): ?>
<section id="connections"><div class="kicker">Administrative & electoral connections</div><h2>Where this record belongs</h2><div class="grid"><article class="card"><span class="tag">Census district</span><h3><a href="{{ route('places.show',['type'=>'district','slug'=>'pilibhit']) }}">Pilibhit →</a></h3><p>District affiliation in the {{ $census['year'] }} source. Explore the district page for its separately dated public-office directory.</p></article><article class="card"><span class="tag">Census subdistrict</span><h3>Code {{ $village['subdistrict_code'] }}</h3><p>The source records this subdistrict code. See the dated LGD section below for available current administrative records.</p></article><article class="card"><span class="tag">Official mapping</span><h3>AC & Lok Sabha connections</h3><p><a href="#electoral-connections">See available code-based electoral links below →</a></p><p>Polling-part assignments remain pending.</p></article></div></section>
<section class="card"><h2>Verified Census connections</h2>
@if($connection)
<p><strong>Development block in the 2011 handbook: {{ $connection['block'] }}.</strong> This is a historical assignment, not a current LGD verification.</p><div class="links"><a href="{{ route('villages.index',['year'=>'2011','block'=>$connection['block']]) }}">Explore this block's 2011 villages →</a><a href="{{ $mapping['url'] }}#page={{ $connection['page'] }}" target="_blank" rel="noreferrer">Official mapping · PDF page {{ $connection['page'] }} ↗</a></div>
@else
<p>No unique village match was verified in the 2011 handbook's block tables.</p>
@endif
@if($counterpart)
<h3>Linked Census record · {{ $counterpart['year'] }}</h3><a href="{{ route('villages.show',['code'=>$counterpart['code'],'slug'=>\Illuminate\Support\Str::slug($counterpart['name']),'year'=>$counterpart['year']]) }}">{{ $counterpart['name'] }} · {{ $counterpart['code'] }} →</a><p class="small">The handbook explicitly connects these location codes. This alone does not establish unchanged boundaries or justify a population-growth calculation.</p>
@else
<p>A unique counterpart in the other imported Census edition is not available.</p>
@endif
<p class="small">Mapping source checked {{ $mapping['checked_on'] }}.</p></section>
@include('village-current')
@include('village-electoral')
<section id="services"><div class="kicker">Local services & participation</div><h2>What comes next for this village</h2><div class="grid"><article class="card"><span class="tag">Data pending</span><h3>Public services</h3><p>Village-specific school, health, water and employment records will appear after identity and source verification.</p></article><article class="card"><span class="tag">Directory pending</span><h3>Local representatives</h3><p>Panchayat and local service authorities have not been imported for this village.</p></article><article class="card"><span class="tag">Workflow pending</span><h3>Citizen issues</h3><p>Issue submission and tracking are not active yet. No issue counts or survey scores are available.</p></article></div></section>
<?php endif; ?>
<?php if (in_array($topic, ['markets'], true)): ?>
@include('place-market-panel', ['marketPageScope'=>$currentRecords->isNotEmpty()?app(\App\Services\PageMarketContext::class)->lgdVillages($currentRecords->pluck('code')->all(),'9'):app(\App\Services\PageMarketContext::class)->censusVillage((int)$year,(string)$village['code'])])
<?php endif; ?>
<?php if (in_array($topic, ['water'], true)): ?>
@include('jjm-water-village',['onlyTopic'=>'water'])
<?php endif; ?>
<?php if (in_array($topic, ['sources'], true)): ?>
<p class="notice">Historical geography: Census {{ $census['year'] }}. Current directory records matched by official Census code appear separately below. Available electoral connections are shown with their own source date; polling-part relationships remain pending.</p>
<?php endif; ?>
</div>
@endsection
