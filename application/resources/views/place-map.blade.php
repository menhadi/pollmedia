<link rel="stylesheet" href="/vendor/maplibre/maplibre-gl.css">
<link rel="stylesheet" href="/css/place-map.css">
<section id="geography" aria-labelledby="map-heading" data-scope="{{ $type }}">
    <div class="kicker">Explore the area</div>
    <h2 id="map-heading">Pilibhit on the map</h2>
    <p class="lead">{{ $type === 'district' ? 'Select a source village to explore its location and geographic details.' : 'Regional context only. A verified parliamentary constituency boundary has not been imported yet.' }}</p>
    <div class="map-toolbar">
        <label>Map layer <select id="map-layer">
            <option value="villages" @selected($type === 'district')>SOI source villages</option>
            <option value="base" @selected($type === 'pc')>Street map</option>
            <option disabled>PC / AC boundaries — pending</option>
            <option disabled>Election results — pending</option>
            <option disabled>SIR / citizen issues — pending mapping</option>
        </select></label>
        <button type="button" id="map-reset">Reset view</button>
        <button type="button" id="map-expand" aria-expanded="false" aria-controls="place-map">Expand map</button>
    </div>
    <div class="map-layout">
        <div class="map-surface"><div id="place-map" role="region" aria-label="Interactive Pilibhit map"></div><p id="map-status" role="status">Loading map…</p></div>
        <aside class="map-details" aria-label="Selected place details">
            <label for="map-search">Find a source village</label>
            <input type="search" id="map-search" placeholder="Village name or source code" autocomplete="off" disabled>
            <div id="map-results" aria-label="Matching villages"></div>
            <div id="map-selection" aria-live="polite"><span class="kicker">Place details</span><h3>Select a village</h3><p>Click a shape or search by name. Village details are also accessible through the search list.</p></div>
            <p class="small">Green shapes: source villages. Source edition and current LGD links await verification. Gaps are not evidence of missing settlements.</p>
            <a href="https://surveyofindia.gov.in/pages/village-boundary-data-base-of-entire-india" target="_blank" rel="noreferrer">Official boundary source ↗</a>
        </aside>
    </div>
    <p class="small">Village shapes do not define an official district or constituency outline. Development figures and representatives are not assigned to individual villages until geographic links are verified. <a href="https://www.openstreetmap.org/fixthemap" target="_blank" rel="noreferrer">Report a street-map issue ↗</a></p>
    <noscript><p class="notice">Enable JavaScript for the interactive map. Official source links and district information remain available below.</p></noscript>
</section>
<script src="/vendor/maplibre/maplibre-gl.js" defer></script>
<script src="/js/place-map.js?v={{ substr(hash_file('sha256',public_path('js/place-map.js')),0,12) }}" defer></script>
