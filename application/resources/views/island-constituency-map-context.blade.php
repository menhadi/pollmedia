@if(in_array($state, ['Puducherry', 'Lakshadweep', 'Andaman & Nicobar Islands'], true))
@php
    $islandFixture = json_decode(file_get_contents(database_path('fixtures/island-electoral-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $islandContext = collect($islandFixture[$kind === 'pc' ? 'pcs' : 'acs'])->first(fn ($entry) => $entry['state'] === $state && ($entry['archive_name'] ?? $entry['name']) === $name && $entry['edition'] === $chosen['entry']->edition_id && $entry['code'] === (int) $chosen['entry']->record_code);
@endphp
@if($islandContext)
@php
    $islandGroups = collect(json_decode(file_get_contents(database_path('fixtures/'.$islandContext['region'].'-source-villages.json')), true, 512, JSON_THROW_ON_ERROR)['districts'])->filter(fn ($entry) => $kind === 'pc' || $entry['slug'] === $islandContext['group']);
@endphp
<section class="panel" id="island-district-map-context"><h2>District, village and urban maps</h2><p>Associated source regions; maps and Census figures retain their own editions. Regional association does not assign individual villages to an AC.</p><ul>
@foreach($islandGroups as $entry)
@php($outline = ['pondicherry'=>'puducherry','mahe'=>'puducherry','yanam'=>'puducherry','lakshadweep-district'=>'lakshadweep'][$entry['slug']] ?? $entry['slug'])
<li><a data-constituency-settlement-map href="{{ route('island-source-village-map', ['region'=>$islandContext['region'], 'slug'=>$entry['slug']]) }}">{{ $entry['source_name'] }} · Village and urban map</a> · <a href="{{ route('island-source-district-map', ['region'=>$islandContext['region'], 'slug'=>$outline]) }}">District outline · separate edition</a></li>
@endforeach
</ul></section>
@endif
@endif
