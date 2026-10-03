@php
    $mapState = app(\App\Services\ElectoralMapCatalogue::class)->stateFromQuery($mapStateName ?? '');
    $mapSlug = $mapState['slug'] ?? null;
    $mapKind = $mapKind ?? 'pc';
    $mapFile = $mapState ? ($mapKind === 'ac' && $mapSlug === 'telangana' ? 'andhra-pradesh.json' : $mapState['file']) : 'india-'.$mapKind.'.json';
    $isLocator = ($mapMode ?? 'results') === 'focus';
    $mapContext = array_filter(['kind'=>$mapKind, 'state'=>$mapSlug, 'edition'=>$isLocator ? null : ($mapEdition ?? null)]);
    $mapCatalogue = app(\App\Services\ElectoralMapCatalogue::class)->all();
    $mapHash = $mapState ? ($mapCatalogue['states'][$mapKind==='ac' && $mapSlug==='telangana'?'andhra-pradesh':$mapSlug]['sha256']) : $mapCatalogue['national_layers'][$mapKind]['sha256'];
@endphp
@once
<link rel="stylesheet" href="{{ asset('css/election-map.css') }}?v={{ substr(hash_file('sha256', public_path('css/election-map.css')),0,12) }}">
<script defer src="{{ asset('js/election-map.js') }}?v={{ substr(hash_file('sha256', public_path('js/election-map.js')),0,12) }}"></script>
@endonce
<section class="election-map" data-election-map data-mode="{{ $mapMode ?? 'results' }}" data-kind="{{ $mapKind }}" data-year="{{ $isLocator ? '' : ($mapYear ?? '') }}" data-source="{{ asset('maps/electoral/'.$mapFile) }}?v={{ substr($mapHash,0,12) }}" data-results="{{ route('elections.maps.results',$mapContext) }}" data-state="{{ $mapState['name'] ?? '' }}" data-selected="{{ $mapSelectedName ?? '' }}" data-selected-code="{{ $mapSelectedCode ?? '' }}" data-finder="{{ route('elections.constituencies') }}">
<div class="election-map-heading"><h3>{{ $mapState['name'] ?? 'India' }} · {{ $mapKind==='pc'?'Lok Sabha':'Assembly' }} map</h3>@unless($isLocator)<span>{{ $mapYear ?? 'Latest available results by state' }}</span>@endunless</div>
<label class="election-map-search" @if($isLocator) hidden @endif>Open a constituency<select data-map-seats disabled><option>Loading constituencies…</option></select></label>
<div class="election-map-tools" aria-label="Map controls"><button type="button" data-map-zoom="in" aria-label="Zoom in">+</button><button type="button" data-map-zoom="out" aria-label="Zoom out">−</button><button type="button" data-map-zoom="reset">Reset map</button></div>
<div class="election-map-surface"><svg role="group" aria-label="{{ $mapState['name'] ?? 'India' }} {{ strtoupper($mapKind) }} constituency map" viewBox="0 0 600 600"></svg></div>
<div class="election-map-tooltip" data-map-tooltip role="tooltip" hidden></div>
<p data-map-status role="status">Loading the map…</p>
<div data-map-legend @if($isLocator) hidden @endif class="election-map-legend" aria-label="Map colours"></div>
<div data-map-selection @if($isLocator) hidden @endif class="election-map-selection" aria-live="polite">Select a constituency to open its election history.</div>
<details data-map-unplaced @if($isLocator) data-locator-list @endif hidden><summary>Additional historical constituencies · schematic list</summary><div class="election-map-unplaced"></div></details>
<noscript><a href="{{ route('elections.constituencies',['kind'=>$mapKind,'state'=>$mapState['name']??null]) }}">Browse constituency records →</a></noscript>
</section>
