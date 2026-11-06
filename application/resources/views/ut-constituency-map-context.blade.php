@if($kind === 'pc' && in_array($state, ['Chandigarh', 'Dadra & Nagar Haveli and Daman & Diu'], true))
@php
    $utPcFixture = json_decode(file_get_contents(database_path('fixtures/ut-electoral-source-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $utPcContext = collect($utPcFixture['pcs'])->first(fn ($pc) => $pc['state'] === $state && $pc['archive_name'] === $name && $pc['edition'] === $chosen['entry']->edition_id && $pc['code'] === (int) $chosen['entry']->record_code);
@endphp
@if($utPcContext)
<section class="panel" id="ut-district-map-context"><h2>District, village and urban maps</h2>
<p>Associated district source maps for PC {{ $utPcContext['number'] }} · {{ $utPcContext['name'] }}. Census and source-map editions are separately labelled; district totals are not constituency totals.</p>
<ul>@foreach($utPcContext['district_slugs'] as $utSlug)
@php
    $utSourceDistrict = collect(json_decode(file_get_contents(database_path('fixtures/'.$utPcContext['region'].'-source-villages.json')), true, 512, JSON_THROW_ON_ERROR)['districts'])->firstWhere('slug', $utSlug);
@endphp
<li><a data-pc-settlement-map href="{{ route('ut-source-village-map', ['region' => $utPcContext['region'], 'slug' => $utSlug]) }}">{{ $utSourceDistrict['source_name'] }} · Village and urban map</a> · <a href="{{ route('ut-source-district-map', ['region' => $utPcContext['region'], 'slug' => $utSlug]) }}">District outline</a></li>
@endforeach</ul>
<p>AC: not applicable. These Union Territories have no Legislative Assembly. @if(count($utPcContext['district_slugs']) > 1)Daman and Diu share this parliamentary constituency; both district maps are linked above.@endif</p>
<p><a href="{{ $utPcContext['region'] === 'chandigarh' ? $utPcFixture['source_chandigarh'] : $utPcFixture['source_dd'] }}">Official 2024 constituency reference</a></p>
</section>
@endif
@endif
