@php
    $villageDistrictName = $villageDistrictName ?? ($data['name'] ?? ($identity['district_name'] ?? ($identity['name'] ?? '')));
    $villageStateName = $villageStateName ?? ($data['state_name'] ?? ($identity['state_name'] ?? ''));
    $villageSubdistrictName = $villageSubdistrictName ?? null;
    $villageIds = $villageIds ?? [];
    $villageHeading = $villageHeading ?? ($villageDistrictName.' villages');
    $villageColour = ($villageSubdistrictName || $villageIds) ? 'adjacent' : 'subdistrict';
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
    $currentDistrictPath = $villageDistrictPath ?? ($identity['path'] ?? (request()->attributes->get('district_identity')['path'] ?? url()->current()));
    $districtDirectory = collect(app(\App\Services\DistrictDashboardUrls::class)->all())
        ->sortBy([['state_name', 'asc'], ['name', 'asc']])
        ->groupBy('state_name');
    $subdistrictPages = $subdistrictPages ?? collect($data['subdistricts'] ?? [])
        ->filter(fn ($place): bool => is_array($place) && isset($place['name'], $place['id']))
        ->map(fn (array $place): array => [
            'name' => $place['name'],
            'url' => app(\App\Services\NamedPlaceUrls::class)->census($place),
        ])
        ->values();
@endphp
@if($villageMapFile)
<section class="static-map-panel district-village-map" aria-label="{{ $villageHeading }}">
    <h2>{{ $villageHeading }}</h2>
    <div class="static-map-layout">
        <div class="static-map"
             data-static-map
             data-mode="village"
             data-colour="{{ $villageColour }}"
             @if($villageSubdistrictName)data-preset-subdistrict="{{ $villageSubdistrictName }}"@endif
             data-village-base="/india/villages/{{ $stateSlug }}/{{ $districtSlug }}/"
             data-district-path="{{ $currentDistrictPath }}"
             data-district-name="{{ $villageDistrictName }}"
             data-src="{{ asset($villageMapFile) }}?v={{ filemtime(public_path($villageMapFile)) }}">
            <svg role="group" aria-label="{{ $villageHeading }}"></svg>
            <div class="static-map-tooltip" data-map-tooltip hidden></div>
            <script type="application/json" data-map-links>@json($villageLinks)</script>
            <script type="application/json" data-map-subdistrict-pages>@json($subdistrictPages)</script>
            <script type="application/json" data-map-village-ids>@json(array_values($villageIds))</script>
        </div>
        <aside class="static-map-filters" aria-label="Village filters">
            <label for="village-district">District</label>
            <select id="village-district" data-map-district>
                <option value="">Choose a district</option>
                @foreach($districtDirectory as $stateName => $districts)
                    <optgroup label="{{ $stateName }}">
                        @foreach($districts as $entry)
                            <option value="{{ $entry['path'] }}" @selected($entry['path'] === $currentDistrictPath)>{{ $entry['name'] }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <label for="village-subdistrict">Sub-district / taluka</label>
            <select id="village-subdistrict" data-map-subdistrict>
                <option value="">All sub-districts</option>
            </select>
            <label for="village-select">Village</label>
            <select id="village-select" data-map-village disabled>
                <option value="">Choose a sub-district first</option>
            </select>
            <p class="static-map-status" data-map-status role="status">Loading village map...</p>
            <p class="static-map-hint">Pick a value here or click a village on the map.</p>
            <nav class="static-map-shortcuts" data-map-shortcuts aria-label="Linked place pages"></nav>
        </aside>
    </div>
</section>
@endif
