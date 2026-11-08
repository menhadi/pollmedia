<section id="{{ $mapId ?? 'location' }}" class="panel place-location-map" aria-label="Location map for {{ $mapName }}">
    <h2>Map of {{ $mapName }}</h2>
    <iframe title="Location map for {{ $mapName }}" src="https://www.google.com/maps?q={{ rawurlencode($mapQuery) }}&amp;z=7&amp;output=embed" loading="lazy" referrerpolicy="no-referrer" allowfullscreen></iframe>
    <p class="small">Map location is approximate; it does not show historical boundaries.</p>
    @php($boundaryState = app(\App\Services\ElectoralMapCatalogue::class)->stateFromQuery($mapQuery))
    @if($boundaryState)
        <nav class="sidebar-links constituency-state-links" aria-label="Explore state constituencies">
            <a href="{{ route('states.show',['state'=>$boundaryState['slug'],'lang'=>app()->getLocale()==='hi'?'hi':null]) }}#pc-history">Explore Lok Sabha constituencies →</a>
            <a href="{{ route('states.show',['state'=>$boundaryState['slug'],'election'=>'ac','lang'=>app()->getLocale()==='hi'?'hi':null]) }}#ac-history">Explore State Assembly constituencies →</a>
            <a href="{{ route('elections.maps.show',['state'=>$boundaryState['slug'],'lang'=>app()->getLocale()==='hi'?'hi':null]) }}">{{ app()->getLocale()==='hi'?'निर्वाचन क्षेत्र मानचित्र':'State constituency map' }} →</a>
        </nav>
    @endif
</section>
