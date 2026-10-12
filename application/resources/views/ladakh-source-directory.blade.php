@php($currentDistricts = json_decode(file_get_contents(database_path('fixtures/ladakh-2026-revenue-villages.json')), true, 512, JSON_THROW_ON_ERROR)['districts'])
<section class="panel" id="ladakh-current-districts"><h2>Ladakh · districts notified 27 April 2026</h2><p>Official revenue-village lists and the shared parliamentary constituency. Current district and village polygons are unavailable; historical maps are listed separately below.</p><div class="source-grid">
@foreach($currentDistricts as $currentDistrict)
<article><h3><a href="{{ route('ladakh-current-district', ['slug' => $currentDistrict['slug']]) }}">{{ $currentDistrict['name'] }}</a></h3><p>{{ count($currentDistrict['revenue_villages']) }} notified village entries</p></article>
@endforeach
</div></section>
@php($sourceGroups = json_decode(file_get_contents(database_path('fixtures/ladakh-source-district-outlines.json')), true, 512, JSON_THROW_ON_ERROR)['districts'])
<section class="panel" id="ladakh-source-maps"><h2>Ladakh · historical source district maps</h2><p>The SOI 2022–2025 source contains the older LEH and KARGIL outlines. The 27 April 2026 notification defines seven districts: Leh, Kargil, Sham, Nubra, Changthang, Zanskar and Drass. These source maps do not depict the new district boundaries. Current district polygons and village polygons remain unavailable in the checked source; no geometry is inferred. <a href="https://cdnbbsr.s3waas.gov.in/s395192c98732387165bf8e396c0f2dad2/uploads/2026/04/202604271425803221.pdf">Official notification</a></p><div class="source-grid">
@foreach($sourceGroups as $sourceDistrict)
<article><h3><a href="{{ route('ladakh-source-district-map', ['slug' => $sourceDistrict['slug']]) }}">{{ $sourceDistrict['source_name'] }}</a></h3></article>
@endforeach
</div></section>
