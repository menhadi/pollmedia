@php($sourceGroups = json_decode(file_get_contents(database_path('fixtures/jammu-kashmir-source-district-outlines.json')), true, 512, JSON_THROW_ON_ERROR)['districts'])
<section class="panel" id="jammu-kashmir-source-maps"><h2>Jammu &amp; Kashmir district maps</h2><p>Official district outlines with separate Census 2011 context. Village source maps and dated AC/PC dashboard links are available; edition correspondence remains unverified. The SOI archive also contains MIRPUR and MUZAFFARABAD records, which are outside the checked 20-district administrative register and remain separately flagged source records.</p><div class="source-grid">
@foreach($sourceGroups as $sourceDistrict)
<article><h3><a href="{{ route('jammu-kashmir-source-district-map', ['slug' => $sourceDistrict['slug']]) }}">{{ $sourceDistrict['source_name'] }}</a></h3></article>
@endforeach
</div></section>

@php($villageGroups = json_decode(file_get_contents(database_path('fixtures/jammu-kashmir-source-villages.json')), true, 512, JSON_THROW_ON_ERROR)['districts'])
<section class="panel" id="jammu-kashmir-village-maps"><h2>Jammu &amp; Kashmir village maps</h2><p>6,590 named NWIC/SOI source shapes across 20 districts. Boundary edition and Census/LGD correspondence remain unverified; source exceptions are flagged.</p><div class="source-grid">
@foreach($villageGroups as $group)
<article><h3><a href="{{ route('jammu-kashmir-source-village-map', ['slug'=>$group['slug']]) }}">{{ $group['source_name'] }}</a></h3><p>{{ $group['features'] }} village source shapes</p></article>
@endforeach
</div></section>
