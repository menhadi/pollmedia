@php
    $stateMapName = \Illuminate\Support\Str::title($place->name);
    $stateMapFilter = $place->name;
    $stateMapFile = 'maps/india-district-fast.json';
    $stateMapPages = collect(app(\App\Services\DistrictDashboardUrls::class)->all())->pluck('path')->values()->all();
@endphp
@if(is_file(public_path($stateMapFile)))
<section class="static-map-panel state-district-map" aria-label="{{ $stateMapName }} district map">
    <h2>{{ $stateMapName }} districts</h2>
    <div class="static-map-layout">
        <div class="static-map"
             data-static-map
             data-mode="district"
             data-state="{{ $stateMapFilter }}"
             data-link="/india/districts/{state}/{district}"
             data-state-link="/india/census/states/{state}"
             data-state-path="{{ url()->current() }}"
             data-home-link="{{ route('home') }}"
             data-src="{{ asset($stateMapFile) }}?v={{ filemtime(public_path($stateMapFile)) }}">
            <svg role="group" aria-label="{{ $stateMapName }} district map"></svg>
            <div class="static-map-tooltip" data-map-tooltip hidden></div>
            <script type="application/json" data-map-pages>@json($stateMapPages)</script>
        </div>
        <aside class="static-map-filters" aria-label="District filters">
            <label for="state-map-state">State / Union territory</label>
            <select id="state-map-state" data-map-state-select>
                <option value="">Choose a state</option>
            </select>
            <label for="state-map-district">District</label>
            <select id="state-map-district" data-map-district-select disabled>
                <option value="">Choose a state first</option>
            </select>
            <p class="static-map-status" data-map-status role="status">Loading district map...</p>
            <p class="static-map-hint">Pick a state or district, or click a district on the map.</p>
            <nav class="static-map-shortcuts" data-map-shortcuts aria-label="Linked place pages"></nav>
        </aside>
    </div>
</section>
@endif
