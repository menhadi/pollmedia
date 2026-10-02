<section id="{{ $mapId ?? 'location' }}" class="panel place-location-map" aria-label="Location map for {{ $mapName }}">
    <h2>Map of {{ $mapName }}</h2>
    <iframe title="Location map for {{ $mapName }}" src="https://www.google.com/maps?q={{ rawurlencode($mapQuery) }}&amp;z=7&amp;output=embed" loading="lazy" referrerpolicy="no-referrer" allowfullscreen></iframe>
    <p class="small">Map location is approximate; it does not show historical boundaries.</p>
    @if(str_contains(mb_strtolower($mapQuery), 'uttar pradesh'))
        <a class="button" href="{{ route('elections.map-pilot') }}">Explore UP constituency map · pilot →</a>
    @endif
</section>
