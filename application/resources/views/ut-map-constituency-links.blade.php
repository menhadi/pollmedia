@php
    $utMapElectoral = json_decode(file_get_contents(database_path('fixtures/ut-electoral-source-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $utMapPcs = collect($utMapElectoral['pcs'])->filter(fn ($pc) => $pc['region'] === $region && in_array($district['slug'], $pc['district_slugs'], true));
@endphp
<nav class="section-nav" id="ut-map-constituencies" aria-label="District constituency dashboards">
@foreach($utMapPcs as $pc)
<a data-map-pc-link href="{{ route('constituency.overview', ['state' => $pc['state'], 'kind' => 'pc', 'name' => $pc['archive_name'], 'edition' => $pc['edition'], 'code' => $pc['code']]) }}">PC {{ $pc['number'] }} · {{ $pc['name'] }} · Open dashboard</a>
@endforeach
<span>AC: not applicable · No Legislative Assembly</span>
<a href="#ut-electoral-context">Electoral sources &amp; membership</a>
</nav>
