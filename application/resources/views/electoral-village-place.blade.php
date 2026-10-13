<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $village['name'] }} · LGD village · Pollmedia</title><link rel="stylesheet" href="{{ asset('css/electoral-village-map.css') }}"></head><body>
<header><a class="brand" href="{{ route('home') }}">Pollmedia</a><a href="{{ route('electoral-villages.index',['state'=>$village['state']]) }}">Constituency village map →</a></header>
<main class="place-page"><h1>{{ $village['name'] }}</h1><p>{{ $village['subdistrict'] }} · {{ $village['district'] }} · {{ $village['state_name'] }}</p><p>Official LGD village code: {{ $village['lgd'] }}</p>
<section class="coverage"><h2>Population, literacy and other records</h2>
<p>Official Census 2011 crosswalk code: {{ $village['census2011'] ?: 'Unavailable' }}. The code identifies a Census record; it does not establish the polygon’s boundary edition.</p>
@isset($village['census'])
<h3>Existing verified Census 2011 profile</h3>
<p>Population: {{ number_format($village['census']['population']) }} · Households: {{ number_format($village['census']['households']) }} · Literate people: {{ number_format($village['census']['literate']) }}</p>
<p>Literacy rate: {{ $village['census']['literacy_rate'] }}%. These figures describe the dated Census record, not estimated population inside the displayed polygon.</p>
<a href="{{ $village['census_source'] }}">Official Census source →</a>
@endisset
<p><a href="{{ route('civic.index',['year'=>2011,'state'=>$village['state_name'],'q'=>$village['name']]) }}">Find dated population and literacy records →</a></p>
<p>Search results require record verification. Soil and other geographic datasets have not yet been attached.</p></section>
<section class="coverage"><h2>Source</h2><p>Official LGD all-India village directory and constituency coverage export, checked 2026-10-13.</p><a href="https://lgdirectory.gov.in/downloadDirectory.do">Official LGD directory →</a></section></main></body></html>
