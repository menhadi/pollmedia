@php
    $mapState = $mapState ?? null;
    $mapHeading = $mapHeading ?? null;
    $mapIntro = $mapIntro ?? null;
@endphp
<section class="static-map-panel" aria-label="{{ $mapHeading ?: 'District map' }}">
    @if($mapHeading)<h2 class="static-map-heading">{{ $mapHeading }}</h2>@endif
    @if($mapIntro)<p class="static-map-intro">{{ $mapIntro }}</p>@endif
    @unless($mapState)
        <div class="static-map-toolbar">
            <label for="static-map-state">State / Union territory</label>
            <select id="static-map-state" data-map-state-select>
                <option value="">All India</option>
            </select>
            <button type="button" data-map-reset>Reset map</button>
        </div>
    @endunless
    @php
        $mapPages = $mapPages ?? (class_exists(\App\Services\DistrictDashboardUrls::class)
            ? collect(app(\App\Services\DistrictDashboardUrls::class)->all())->pluck('path')->values()->all()
            : []);
    @endphp
    <div class="static-map"
         data-static-map
         data-src="{{ asset('maps/india-district-fast.json') }}{{ is_file(public_path('maps/india-district-fast.json')) ? '?v='.filemtime(public_path('maps/india-district-fast.json')) : '' }}"
         data-state="{{ $mapState }}"
         data-link="/india/districts/{state}/{district}"
         data-state-link="/india/state/{state}"
         data-home-link="{{ route('home') }}">
        <svg role="group" aria-label="{{ $mapState ? $mapState.' district map' : 'India district map' }}"></svg>
        <script type="application/json" data-map-pages>@json($mapPages)</script>
        <div class="static-map-tooltip" data-map-tooltip hidden></div>
        <p class="static-map-status" data-map-status role="status">Loading district map…</p>
    </div>
</section>
