<aside class="state-atlas-map" aria-label="{{ $title }} district map">
    @include('static-district-map', [
        'mapState' => $title,
        'mapIntro' => 'Select a district to open its summary page.',
    ])
</aside>
