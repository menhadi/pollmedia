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
<script defer src="{{ asset('js/constituency-map-colours.js').'?v='.substr(hash_file('sha256',public_path('js/constituency-map-colours.js')),0,12) }}"></script>
<script defer src="{{ asset('js/election-map.js') }}?v={{ substr(hash_file('sha256', public_path('js/election-map.js')),0,12) }}"></script>
@endonce
<section class="election-map" data-election-map data-mode="{{ $mapMode ?? 'results' }}" data-kind="{{ $mapKind }}" data-labels="{{ ($mapLabels ?? false) ? 'true' : 'false' }}" data-year="{{ $isLocator ? '' : ($mapYear ?? '') }}" data-source="{{ asset('maps/electoral/'.$mapFile) }}?v={{ substr($mapHash,0,12) }}" data-results="{{ route('elections.maps.results',$mapContext) }}" data-state="{{ $mapState['name'] ?? '' }}" data-selected="{{ $mapSelectedName ?? '' }}" data-selected-code="{{ $mapSelectedCode ?? '' }}" data-finder="{{ route('elections.constituencies') }}">
<div class="election-map-heading"><h3>{{ $mapState['name'] ?? 'India' }} · {{ $mapKind==='pc'?'Lok Sabha':'Assembly' }} map</h3>@unless($isLocator)<span>{{ $mapYear ?? 'Latest available results by state' }}</span>@endunless</div>
@if($mapLabels ?? false)<p class="election-map-guide">Explore {{ $mapKind==='pc'?'Lok Sabha':'Assembly' }} constituencies · Hover for a name, click to see results.</p>@endif
@if(!empty($atlasExplorer))
<script type="application/json" data-linked-seats>{!! json_encode($atlasLinkedSeats??[],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<div class="atlas-map-filter" data-map-filter hidden>
<label for="atlas-search-{{ $mapKind }}">{{ $mapKind==='pc'?$tr('Find a Lok Sabha seat','लोक सभा क्षेत्र खोजें'):$tr('Find an Assembly seat','विधान सभा क्षेत्र खोजें') }}</label>
<div class="atlas-search-field"><svg aria-hidden="true" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="10" r="6.3"/><path d="m15 15 5 5"/></svg>
<input type="search" id="atlas-search-{{ $mapKind }}" data-seat-query role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="atlas-options-{{ $mapKind }}" autocomplete="off" placeholder="{{ $tr('Type a constituency name','निर्वाचन क्षेत्र का नाम लिखें') }}"></div>
<ul id="atlas-options-{{ $mapKind }}" role="listbox" aria-label="{{ $mapKind==='pc'?$tr('Lok Sabha seats','लोक सभा क्षेत्र'):$tr('Assembly seats','विधान सभा क्षेत्र') }}" data-seat-options hidden></ul>
<p data-seat-no-match role="status" hidden>{{ $tr('No matching constituency','कोई मिलान नहीं मिला') }}</p></div>
@endif
<label class="election-map-search" @if($isLocator && empty($atlasExplorer)) hidden @endif>Open a constituency<select data-map-seats disabled><option>Loading constituencies…</option></select></label>
<div class="election-map-tools" aria-label="Map controls"><button type="button" data-map-zoom="in" aria-label="Zoom in">+</button><button type="button" data-map-zoom="out" aria-label="Zoom out">−</button><button type="button" data-map-zoom="reset">Reset map</button></div>
<div class="election-map-surface"><svg role="group" aria-label="{{ $mapState['name'] ?? 'India' }} {{ strtoupper($mapKind) }} constituency map" viewBox="0 0 600 600"></svg></div>
<div class="election-map-tooltip" data-map-tooltip role="tooltip" hidden></div>
<p data-map-status role="status">Loading the map…</p>
<div data-map-legend @if($isLocator) hidden @endif class="election-map-legend" aria-label="Map colours"></div>
<div data-map-selection @if($isLocator) hidden @endif class="election-map-selection" aria-live="polite">Select a constituency to open its election history.</div>
@if(!empty($atlasExplorer))
<a data-map-village-link href="{{ route('electoral-villages.index',array_filter(['state'=>$mapState['name']??null,'kind'=>$mapKind,'name'=>$mapSelectedName?:null,'lang'=>app()->getLocale()])) }}">{{ $mapSelectedName?$tr('Explore villages in ','गाँव देखें · ').$mapSelectedName:$tr('Explore village polygons','गाँवों का नक्शा देखें') }} →</a>
@else<a data-map-village-link href="{{ route('electoral-villages.index') }}">Explore constituency village polygons across India →</a>@endif
<noscript><a href="{{ route('elections.constituencies',['kind'=>$mapKind,'state'=>$mapState['name']??null]) }}">Browse constituency records →</a></noscript>
</section>
