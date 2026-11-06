@if($state === 'Sikkim' && !$exactSeatOnly)
@php
$sikkimFixture=json_decode(file_get_contents(database_path('fixtures/sikkim-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$sikkimNormalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:BL|SC|ST)\)\s*$/i','',$value)));
$sikkimSeat=collect($kind==='pc'?$sikkimFixture['pcs']:$sikkimFixture['district_rows'])->first(fn(array $row):bool=>$sikkimNormalize($row['name'])===$sikkimNormalize($name));
$sikkimCodes=['North'=>'241','West'=>'242','South'=>'243','East'=>'244'];
$sikkimContexts=$kind==='pc'&&$sikkimSeat?array_keys($sikkimCodes):($sikkimSeat['district_memberships']??[]);
@endphp
<section id="census-context" class="constituency-district-context">
<h2>District maps and Census context</h2>
<p>Historical Census district contexts use physical SOI source coverage. Modern source labels remain visible. Polygon equivalence, village-code joins and source editions remain unverified. No population is allocated to shapes.</p>
@if($sikkimContexts)
<nav aria-label="Sikkim district maps">
@foreach($sikkimContexts as $sikkimDistrict)
<a href="{{ route('district-dashboard',['state'=>'11','district'=>$sikkimCodes[$sikkimDistrict]]) }}#map">{{ $sikkimDistrict }} District village map</a>
@endforeach
</nav>
@else
<p>District correspondence unavailable. Sangha is retained as a separate electoral constituency; territorial assignment is withheld.</p>
@endif
<p><a href="{{ $sikkimFixture['district_url'] }}" target="_blank" rel="noopener">Official 2008 electoral source</a> · Exact notification date pending. Explicit cross-district extents retained.</p>
</section>
@endif
