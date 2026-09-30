<section id="{{ $mapId ?? 'location' }}" class="panel place-location-map" aria-label="Location map for {{ $mapName }}">
    <h2>Map of {{ $mapName }}</h2>
    <iframe title="Location map for {{ $mapName }}" src="https://www.google.com/maps?q={{ rawurlencode($mapQuery) }}&amp;output=embed" loading="lazy" referrerpolicy="no-referrer" allowfullscreen></iframe>
    <p class="small">Map location is approximate; it does not show historical boundaries.</p>
</section>
