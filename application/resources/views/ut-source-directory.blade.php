@foreach(['chandigarh', 'dnh-daman-diu'] as $utRegion)
@php
    $utVillages = json_decode(file_get_contents(database_path('fixtures/'.$utRegion.'-source-villages.json')), true, 512, JSON_THROW_ON_ERROR);
    $utOutlines = json_decode(file_get_contents(database_path('fixtures/'.$utRegion.'-source-district-outlines.json')), true, 512, JSON_THROW_ON_ERROR);
@endphp
<section class="panel" id="{{ $utRegion }}-source-maps"><h2>{{ $utVillages['label'] }} district and settlement maps</h2><p>Original SOI village source groups with separate Census 2011 context and direct Lok Sabha dashboard links. These Union Territories have no Legislative Assembly.</p>
<div class="source-grid">@foreach($utVillages['districts'] as $sourceDistrict)<article><h3><a href="{{ route('ut-source-village-map', ['region' => $utRegion, 'slug' => $sourceDistrict['slug']]) }}">{{ $sourceDistrict['source_name'] }} settlement map</a></h3><p>{{ number_format($sourceDistrict['features']) }} named source shapes · boundary edition and Census correspondence unverified</p></article>@endforeach</div>
<details><summary>{{ count($utOutlines['districts']) }} district outlines · separate SOI edition</summary><ul>@foreach($utOutlines['districts'] as $sourceDistrict)<li><a href="{{ route('ut-source-district-map', ['region' => $utRegion, 'slug' => $sourceDistrict['slug']]) }}">{{ $sourceDistrict['source_name'] }}</a></li>@endforeach</ul></details></section>
@endforeach
