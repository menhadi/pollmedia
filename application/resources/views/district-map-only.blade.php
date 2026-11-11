<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $district['name'] }} district · map, services and elections | Pollmedia</title>
<meta name="description" content="Map, community issues, public authorities and constituency links for {{ $district['name'] }}, {{ $district['state_name'] }}. Census 2011 has no district-level record for this name, so census totals are not shown.">
<link rel="canonical" href="{{ url($district['path']) }}">
<link rel="stylesheet" href="{{ asset('css/pilibhit-ac-pilot.css') }}">
<link rel="stylesheet" href="{{ asset('css/rampur-district-pilot.css') }}">
@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">
@include('place-layout-assets')
</head>
<body class="place-layout election-ui rampur-dashboard">
@include('public-header')
<main>
<section class="district-title pm-district-heading"><div>
<p class="eyebrow">District dashboard · available records</p>
<h1>{{ $district['name'] }} district</h1>
<p>{{ $district['state_name'] }}</p>
</div></section>
<p class="notice">Census 2011 has no district record for this name, so census totals, village census tables and water/soil census panels are not shown. Everything below comes from the records that do exist: Survey of India village boundaries with official place types, constituency links, public authorities and community issues.</p>
@include('place-topic-nav', ['topicGroup' => 'district', 'topicOptions' => $options])
<div data-topic-body>
@if($topic === 'map')
<section class="panel"><p class="eyebrow">Available records</p><h2>Place coverage</h2>
<p>{{ array_sum($counts) }} source polygons: {{ $counts['VILLAGE'] }} villages, {{ $counts['TOWN'] }} towns, {{ $counts['FOREST'] }} forest areas, {{ $counts['OTHER'] }} other. Types come from the LGD village directory, the Census 2011 town directory and the source attributes; an unpublished type stays Other.</p>
</section>
@if($satellite)
<section class="panel" id="satellite-index"><p class="eyebrow">Satellite</p><h2>Vegetation index (NDVI)</h2>
<p>Dates {{ implode(' → ', $satellite['dates'] ?? []) }}@if(isset($satellite['summary']['paired_coverage_pct'])) · paired coverage {{ $satellite['summary']['paired_coverage_pct'] }}%@endif.</p>
@if(isset($satellite['summary']))
<p>Before {{ $satellite['summary']['before_ndvi'] ?? 'Unavailable' }} · after {{ $satellite['summary']['after_ndvi'] ?? 'Unavailable' }} · change {{ $satellite['summary']['ndvi_change'] ?? 'Unavailable' }}.</p>
@endif
<p><a href="{{ route('district-dashboard-directory', ['satellite' => $satellite['layer_id'] ?? null]) }}#satellite-index">Compare satellite images →</a></p>
</section>
@endif
@php($sourceMap = null)
@include('district-polygon-map')
@elseif($topic === 'issues')
@include('community-area-issues', ['deferDistrictAuthorities' => true])
@elseif($topic === 'authorities')
@include('district-authority-directory')
@elseif($topic === 'elections')
@if($constituencyLinks['available'])
@include('district-dashboard-elections')
@else
<section class="panel"><p class="eyebrow">District → constituencies</p><h2>Constituency links unavailable</h2>
<p>The published constituency geography used by this platform does not list this district name, so no Assembly or Parliamentary seats are assigned here. No parent district is substituted.</p>
</section>
@endif
@endif
</div>
</main>
@include('public-footer')
</body></html>
