<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $district['name'] }} district map | Pollmedia</title>
<meta name="description" content="Village and town boundary map for {{ $district['name'] }}, {{ $district['state_name'] }}. Census 2011 has no district-level record for this name, so the map is shown without district census totals.">
<link rel="canonical" href="{{ url($district['path']) }}">
<link rel="stylesheet" href="{{ asset('css/pilibhit-ac-pilot.css') }}">
@include('site-theme')
@include('place-layout-assets')
</head>
<body class="place-layout election-ui rampur-dashboard">
@include('public-header')
<main>
<section class="district-title pm-district-heading"><div>
<p class="eyebrow">District map · villages, towns and forest land</p>
<h1>{{ $district['name'] }} district</h1>
<p>{{ $district['state_name'] }}</p>
</div></section>
<p class="notice">Map-only district page. Census 2011 has no district record for this name, so no district census totals are shown. Village, town and forest polygons come from the Survey of India Village Boundary edition and are linked to LGD codes only where the record is unambiguous; a type that is not published is shown as Other rather than inferred.</p>
@php($data = ['name' => $district['name'], 'state_name' => $district['state_name'], 'census_district_code' => null, 'subdistricts' => []])
@php($sourceMap = null)
@include('district-polygon-map')
</main>
@include('public-footer')
</body></html>
