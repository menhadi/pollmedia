@php($sourceGroups = json_decode(file_get_contents(database_path('fixtures/himachal-pradesh-source-district-outlines.json')), true, 512, JSON_THROW_ON_ERROR)['districts'])
<section class="panel" id="himachal-pradesh-source-maps"><h2>Himachal Pradesh district maps</h2><p>Official district outlines with separate Census 2011 context. Village polygons and electoral membership links remain under verification.</p><div class="source-grid">
@foreach($sourceGroups as $sourceDistrict)
<article><h3><a href="{{ route('himachal-pradesh-source-district-map', ['slug' => $sourceDistrict['slug']]) }}">{{ $sourceDistrict['source_name'] }}</a></h3></article>
@endforeach
</div></section>
