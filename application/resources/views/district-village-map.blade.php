@php
    $villageDistrictName = $data['name'] ?? ($identity['district_name'] ?? ($identity['name'] ?? ''));
    $villageStateName = $data['state_name'] ?? ($identity['state_name'] ?? '');
    $districtSlug = \Illuminate\Support\Str::slug($villageDistrictName);
    $stateSlug = \Illuminate\Support\Str::slug($villageStateName);
    $candidates = [
        'maps/'.$districtSlug.'-villages.geojson',
        'maps/'.$stateSlug.'-'.$districtSlug.'-villages.geojson',
        'maps/bihar-'.$districtSlug.'-villages.geojson',
        'maps/west-bengal-'.$districtSlug.'-villages.geojson',
    ];
    $villageMapFile = collect($candidates)->first(fn (string $path): bool => is_file(public_path($path)));
@endphp
@if($villageMapFile)
<section class="static-map-panel district-village-map" aria-label="{{ $data['name'] }} village map">
    <h2>{{ $data['name'] }} villages</h2>
    <p class="static-map-intro">Select a village polygon to open its record search.</p>
    <div class="static-map"
         data-static-map
         data-mode="village"
         data-src="{{ asset($villageMapFile) }}?v={{ filemtime(public_path($villageMapFile)) }}">
        <svg role="group" aria-label="{{ $data['name'] }} village map"></svg>
        <div class="static-map-tooltip" data-map-tooltip hidden></div>
        <p class="static-map-status" data-map-status role="status">Loading village map…</p>
    </div>
</section>
@endif
