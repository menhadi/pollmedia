@php
$catalogueLayers = collect(app(\App\Services\IndiaVillageMap::class)->catalogue()['layers']);
$districtIdentity = request()->attributes->get('district_identity');
$normaliseName = fn ($value): string => preg_replace('/[^a-z0-9]+/', '', strtolower(str_replace('&', 'and', (string) $value)));
$sameState = fn ($left, $right): bool => $normaliseName($left) === $normaliseName($right)
    || (str_contains($normaliseName($left), 'dadra') && str_contains($normaliseName($right), 'dadra'));
$isPilibhitDistrict = ($districtIdentity['state'] ?? request()->route('state')) === '09' && ($data['census_district_code'] ?? '') === '151';
$pilibhitLayer = $isPilibhitDistrict ? $catalogueLayers->first(fn ($layer) => $layer['file'] === 'maps/pilibhit-villages.geojson') : null;
$catalogueDistrictLayer = $catalogueLayers->first(fn ($layer) => $sameState($layer['state'], $districtIdentity['state_name'] ?? $data['state_name'] ?? '') && $normaliseName($layer['district']) === $normaliseName($data['name'] ?? ''));
$polygonFile = $pilibhitLayer['file'] ?? ($isPilibhitDistrict ? 'maps/pilibhit-district-pilot.json' : ($sourceMap ?? $catalogueDistrictLayer['file'] ?? null));
$polygonLayer = $pilibhitLayer ?: (($catalogueDistrictLayer && $catalogueDistrictLayer['file'] === $polygonFile) ? $catalogueDistrictLayer : ($polygonFile ? $catalogueLayers->first(fn ($layer) => $layer['file'] === $polygonFile) : null));
@endphp
@include('map-mobile-assets')
@if($polygonFile)
<section id="map" class="district-polygons" data-polygon-map="{{ $polygonLayer ? route('india-map.geometry', ['layer' => $polygonLayer['id']]) : asset($polygonFile) }}" data-catalogue="{{ route('india-map.catalogue') }}" data-metrics="{{ url('/api/india-map/metrics') }}" data-layer-id="{{ $polygonLayer['id'] ?? '' }}" data-layer-sha="{{ $polygonLayer['sha256'] ?? '' }}" data-file="{{ $polygonFile }}">
@php($subdistrictPageLinks=collect($data['subdistricts'])->map(fn ($place) => ['name'=>$place['name'],'code'=>$place['code'],'url'=>app(\App\Services\NamedPlaceUrls::class)->census($place)])->values())
<script type="application/json" id="polygon-subdistrict-pages">@json($subdistrictPageLinks)</script>
<aside class="polygon-finder"><h2>Explore {{ $data['name'] }}</h2><label>State / Union territory<select id="polygon-state"></select></label><label>District<select id="polygon-district"></select></label><script type="application/json" id="polygon-district-directory">@json(array_values(app(\App\Services\DistrictDashboardUrls::class)->all()))</script><script type="application/json" id="polygon-current-district">@json(request()->attributes->get('district_identity'))</script><label>Subdistrict<select id="polygon-subdistrict"><option value="">All subdistricts</option></select></label><label id="polygon-place-type-label" hidden>Place type<select id="polygon-place-type"><option value="">All place types</option></select></label><label>Place<select id="polygon-village"><option value="">All places</option></select></label><label>Search place<input id="polygon-search" type="search" placeholder="Place name, type or source code"></label><a href="{{ route('district-dashboard-directory') }}">Choose another district →</a><p>Hover over a polygon for its values. On touch screens, tap a polygon.</p></aside>
<div class="polygon-panel"><div class="polygon-filters"><label>Map data<select id="polygon-metric"><option value="literacy" selected>Education · Literacy (%)</option><option value="population">Population</option><option value="households">Households</option><option value="">Subdistrict colours</option></select></label><label>Minimum<input id="polygon-min" type="number" min="0" placeholder="Any"></label><label>Maximum<input id="polygon-max" type="number" min="0" placeholder="Any"></label><label><input id="polygon-missing" type="checkbox" checked> Show unavailable</label></div><p id="polygon-legend"></p><div class="polygon-canvas"><div class="polygon-tools"><button id="polygon-in" aria-label="Zoom in">+</button><button id="polygon-out" aria-label="Zoom out">−</button><button id="polygon-fit">Fit polygons</button></div><svg id="district-polygons" viewBox="0 0 760 540" aria-label="{{ $data['name'] }} place polygons"></svg><div id="polygon-tooltip" role="status" hidden></div></div><p id="polygon-status">Loading district polygons…</p></div></section>
<link rel="stylesheet" href="{{ asset('css/district-polygons-20261017-filters.css').'?v='.filemtime(public_path('css/district-polygons-20261017-filters.css')) }}">
<script type="module" src="{{ asset('js/district-polygons-20261018-shortcuts.js').'?v='.filemtime(public_path('js/district-polygons-20261018-shortcuts.js')) }}"></script>
@endif

@include('jjm-water-disclaimer')
<script>document.addEventListener('DOMContentLoaded',()=>{const note=document.getElementById('water-disclaimer'),main=document.querySelector('main');if(note&&main)main.append(note);});</script>
