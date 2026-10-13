@php
    $islandElectoral = json_decode(file_get_contents(database_path('fixtures/island-electoral-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $islandPc = collect($islandElectoral['pcs'])->firstWhere('region', $region);
    $islandAcs = collect($islandElectoral['acs'])->filter(fn ($ac) => $region === 'puducherry' && ($ac['group'] === $district['slug'] || $ac['outline'] === $district['slug']));
@endphp
<nav class="section-nav" aria-label="Constituency dashboards">
<a data-map-pc-link href="{{ route('constituency.overview', ['state'=>$islandPc['state'], 'kind'=>'pc', 'name'=>$islandPc['archive_name'], 'edition'=>$islandPc['edition'], 'code'=>$islandPc['code']]) }}">PC 1 · {{ $islandPc['name'] }} · Open dashboard</a>
<a href="#island-electoral-context">{{ $region === 'puducherry' ? count($islandAcs).' linked AC dashboards' : 'AC: not applicable · No Legislative Assembly' }}</a>
</nav>
