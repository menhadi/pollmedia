<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $district['source_name'] }} source district map | Pollmedia</title><link rel="stylesheet" href="{{ asset('css/pilibhit-ac-pilot.css') }}"><link rel="stylesheet" href="{{ asset('css/rampur-district-pilot.css').'?v='.filemtime(public_path('css/rampur-district-pilot.css')) }}"><script src="{{ asset('js/district-dashboard-controls.js') }}" defer></script></head><body class="rampur-dashboard"><header><a class="brand" href="https://pollmedia.org">Pollmedia<span>Odisha district dashboards</span></a><a href="{{ route('district-dashboard-directory') }}">All districts</a></header><main>
<section class="district-title"><div><p class="eyebrow">Odisha · Survey of India source edition 2022–2025</p><h1>{{ $district['source_name'] }} district outline</h1></div><label>Change source district<select id="district-picker">@foreach($districts as $item)<option value="{{ route('odisha-source-district-map', ['slug' => $item['slug']]) }}" @selected($district['slug'] === $item['slug'])>{{ $item['source_name'] }}</option>@endforeach</select></label></section>
@php
    $villageSources = json_decode(file_get_contents(database_path('fixtures/odisha-source-villages.json')), true, 512, JSON_THROW_ON_ERROR)['districts'];
    $villageSlug = $district['slug'];
    $matchingVillageSource = collect($villageSources)->firstWhere('slug', $villageSlug);
@endphp

<section id="map" class="panel" data-assam-outline="{{ asset($district['file']) }}"><div class="section-heading"><h2>Physical district map</h2><a href="{{ asset($district['file']) }}">Download source geometry</a></div><div class="map-wrap"><div class="map-controls"><button id="zoom-in" type="button" aria-label="Zoom in">+</button><button id="zoom-out" type="button" aria-label="Zoom out">−</button><button id="fit-map" type="button">Fit map</button></div><svg id="district-outline" viewBox="0 0 760 540" role="img" aria-label="{{ $district['source_name'] }} SOI district outline"></svg><p id="map-message" role="status">Loading district outline…</p></div><p>This is the district boundary recorded in the SOI source. Village and subdistrict shapes are not included. Original source names and codes are preserved in the download.</p></section>
<nav class="section-nav" aria-label="Dashboard sections"><a href="#map">District map</a><a href="#selected-census-context">District overview</a><a href="#census-subdistricts">Subdistricts</a><a href="#census-towns">Towns</a><a href="#villages">Village directory</a><a href="#history">History & graphs</a><a href="#odisha-electoral-context">AC / PC details</a><a href="#sources">Sources & coverage</a><a href="{{ route('district-dashboard-directory') }}">All districts</a></nav>
<section class="panel" id="village-source-links"><h2>Village source maps</h2>
@if($matchingVillageSource)
<p><a data-district-village-link href="{{ route('odisha-source-village-map', ['slug' => $matchingVillageSource['slug']]) }}">Open {{ $matchingVillageSource['source_name'] }} village source map</a></p>
@else
<p>A corresponding village source group has not been verified for {{ $district['source_name'] }}. Choose a named source group below to inspect its coverage.</p>
@endif
<p>Village source groups and newer district outlines use different editions; this link does not certify matching boundaries.</p>
<details><summary>Browse all 30 named village source groups</summary><ul>
@foreach($villageSources as $villageSource)
<li><a href="{{ route('odisha-source-village-map', ['slug' => $villageSource['slug']]) }}">{{ $villageSource['source_name'] }}</a></li>
@endforeach
</ul></details></section>

@include('odisha-census-context-links')
@include('odisha-electoral-context')
<section id="sources" class="panel"><h2>Sources & edition</h2><p>SOI dataset SOI/ABDB/VECTOR/50000/2025/DISTRICT/INDIA, published 6 May 2026; temporal scope 2022–2025. Geometry is reprojected without simplification or repair.</p><p class="notice">Source district identity and correspondence to Census 2011 / LGD remain unverified. Source spellings are retained. Published Census figures and historical charts are available below through an explicit Census 2011 district selection. Dated PC and AC archives are linked separately; district membership and electoral boundary correspondence remain under review. No population is allocated by polygon area.</p><a href="https://surveyofindia.gov.in/pages/administrative-boundary-data-base-abdb-">Official source & metadata</a> · <a href="https://surveyofindia.gov.in/documents/State_District_Subdistrict_PAN%20INDIA.rar">Preserved original archive</a><p>Archive SHA256: b8325e5d9dd0f04a6663d775363fe38cd2f23bd9dbae3fb7118b4e6e0ce0bcb7</p></section>
</main><footer>Pollmedia · Odisha district dashboard · Dated Census and electoral sources</footer><script type="module" src="{{ asset('js/assam-district-outline.js') }}"></script></body></html>
