@php
    $goaVillageGroups = json_decode(file_get_contents(database_path('fixtures/goa-source-villages.json')), true, 512, JSON_THROW_ON_ERROR)['districts'];
    $goaOutlineGroups = json_decode(file_get_contents(database_path('fixtures/goa-source-district-outlines.json')), true, 512, JSON_THROW_ON_ERROR)['districts'];
@endphp
<section class="panel" id="goa-source-maps"><h2>Goa district and village maps</h2><p>Choose a source district below. Village groups and district outlines retain their original names and separate editions; these links do not establish Census or electoral boundary equivalence.</p>
<h3>Village maps</h3><div class="source-grid">
@foreach($goaVillageGroups as $sourceDistrict)
<article><h3><a data-goa-village="{{ $sourceDistrict['slug'] }}" href="{{ route('goa-source-village-map', ['slug' => $sourceDistrict['slug']]) }}">{{ $sourceDistrict['source_name'] }}</a></h3><p>{{ number_format($sourceDistrict['features']) }} source shapes · Census context and election archives</p></article>
@endforeach
</div><details><summary>3 district outlines · original SOI source names</summary><ul>
@foreach($goaOutlineGroups as $sourceDistrict)
<li><a data-goa-outline="{{ $sourceDistrict['slug'] }}" href="{{ route('goa-source-district-map', ['slug' => $sourceDistrict['slug']]) }}">{{ $sourceDistrict['source_name'] }}</a></li>
@endforeach
</ul><p>Includes Kushavati. The two older village source groups are separate from these three outline records. Direct links cover all 40 ACs and 2 shared PCs in dated electoral context.</p></details></section>
