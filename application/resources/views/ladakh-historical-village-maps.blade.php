@php
$historicalSheets = $district['slug'] === 'leh' ? ['leh'=>['Leh',4], 'khalsi'=>['Khalsi',5], 'nubra'=>['Nubra',6]] : ['kargil'=>['Kargil',6], 'zanskar'=>['Zanskar',7], 'sanku'=>['Sanku',8]];
$historicalPdf = $district['slug'] === 'leh' ? 'https://censusindia.gov.in/nada/index.php/catalog/498/download/1640/DH_2011_0103_PART_A_DCHB_LEH.pdf' : 'https://censusindia.gov.in/nada/index.php/catalog/504/download/1664/DH_2011_0104_PART_A_DCHB_KARGIL.pdf';
@endphp
<section class="panel" id="historical-village-maps"><h2>Historical village location maps · Census 2011</h2>
<p class="notice"><strong>Old boundaries: Census 2011, former Jammu &amp; Kashmir.</strong> These published tahsil sheets show village locations and MDDS codes in the historical {{ $district['source_name'] }} district. They do not depict the seven districts notified in 2026. Notional source sheets remain notional; these images are not georeferenced village boundary polygons. No locations or boundaries have been inferred.</p>
<p>Open a map at full size to read its village codes. Original legends, names and source annotations are retained; images are rotated for readability only. <a href="{{ $historicalPdf }}">Original Census 2011 Part A handbook (PDF)</a>.</p>
@foreach($historicalSheets as $sheetSlug => [$sheetName, $pdfPage])
<details data-historical-sheet="{{ $sheetSlug }}" @if($loop->first) open @endif><summary>{{ $sheetName }} tahsil · Census 2011 · PDF page {{ $pdfPage }}</summary>
<figure><a href="{{ asset('maps/ladakh-historical-villages/'.$sheetSlug.'-2011.webp') }}"><img loading="lazy" style="width:100%;height:auto" src="{{ asset('maps/ladakh-historical-villages/'.$sheetSlug.'-2011.webp') }}" alt="Historical Census 2011 {{ $sheetName }} tahsil village location and code map, old boundaries"></a><figcaption>{{ $sheetName }} · historical village locations, not current district boundaries. <a href="{{ $historicalPdf }}#page={{ $pdfPage }}">View original PDF page {{ $pdfPage }}</a></figcaption></figure></details>
@endforeach
</section>
