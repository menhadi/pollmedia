@php
    $electoralSource = json_decode(file_get_contents(database_path('fixtures/ut-electoral-source-context.json')), true, 512, JSON_THROW_ON_ERROR);
    $associatedPcs = collect($electoralSource['pcs'])->filter(fn ($pc) => $pc['region'] === $region && in_array($district['slug'], $pc['district_slugs'], true));
@endphp
<section class="panel" id="ut-electoral-context"><h2>PC / AC details and election history</h2>
<p class="notice"><strong>Assembly constituencies: not applicable.</strong> Chandigarh and Dadra &amp; Nagar Haveli and Daman &amp; Diu are Union Territories without a Legislative Assembly. Municipal wards and polling stations are not Assembly constituencies. <a href="{{ $electoralSource['source_institutions'] }}">MHA annual report 2021–22, Chapter 6</a>.</p>
@foreach($associatedPcs as $pc)
<article><h3>PC {{ $pc['number'] }} · {{ $pc['name'] }}</h3>
<p><a data-pc-dashboard="{{ $pc['code'] }}" href="{{ route('constituency.overview', ['state' => $pc['state'], 'kind' => 'pc', 'name' => $pc['archive_name'], 'edition' => $pc['edition'], 'code' => $pc['code']]) }}">Open {{ $pc['name'] }} constituency dashboard</a> · {{ $pc['year'] }} archive, results and election history</p>
@if(count($pc['district_slugs']) > 1)<p>Daman and Diu share this PC. It is shown once on each associated district page; both districts remain part of the listed membership.</p>@endif
@if($pc['name'] !== $pc['archive_name'])<p>Archive spelling: “{{ $pc['archive_name'] }}”. The official constituency name is “{{ $pc['name'] }}”; the original archive label is preserved.</p>@endif
<p><a href="{{ $region === 'chandigarh' ? $electoralSource['source_chandigarh'] : $electoralSource['source_dd'] }}">Official 2024 electoral reference</a>. Parliamentary numbers are distinct from archive record identifiers. This district-level association does not certify village-by-village boundary correspondence.</p></article>
@endforeach
</section>
