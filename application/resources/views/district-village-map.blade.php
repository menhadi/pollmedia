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
    $villageLinks = [];
    $linksDatabase = storage_path('app/electoral-villages/display-links.sqlite');
    if ($villageMapFile && is_file($linksDatabase)) {
        try {
            $linkDb = new PDO('sqlite:file:'.str_replace('\\', '/', $linksDatabase).'?mode=ro');
            $statement = $linkDb->prepare('SELECT feature_index, lgd FROM shapes WHERE file = ?');
            $statement->execute([$villageMapFile]);
            foreach ($statement->fetchAll(PDO::FETCH_KEY_PAIR) as $index => $lgd) {
                $villageLinks[(int) $index] = (string) $lgd;
            }
        } catch (\Throwable $exception) {
            $villageLinks = [];
        }
    }
@endphp
@if($villageMapFile)
<section class="static-map-panel district-village-map" aria-label="{{ $villageDistrictName }} village map">
    <h2>{{ $villageDistrictName }} villages</h2>
    <p class="static-map-intro">Select a village polygon to open its page.</p>
    <div class="static-map"
         data-static-map
         data-mode="village"
         data-village-base="/india/villages/{{ $stateSlug }}/{{ $districtSlug }}/"
         data-src="{{ asset($villageMapFile) }}?v={{ filemtime(public_path($villageMapFile)) }}">
        <svg role="group" aria-label="{{ $villageDistrictName }} village map"></svg>
        <div class="static-map-tooltip" data-map-tooltip hidden></div>
        <p class="static-map-status" data-map-status role="status">Loading village map…</p>
        <script type="application/json" data-map-links>@json($villageLinks)</script>
    </div>
</section>
@endif
