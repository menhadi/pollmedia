@if($state==='Nagaland' && !$exactSeatOnly)
@php
$nagalandFixture=json_decode(file_get_contents(database_path('fixtures/nagaland-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$nagalandNormalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$nagalandMatches=collect($kind==='pc'?$nagalandFixture['pcs']:$nagalandFixture['district_rows'])->filter(fn(array $row):bool=>$nagalandNormalize($row['name'])===$nagalandNormalize($name));
$nagalandSeat=$nagalandMatches->count()===1?$nagalandMatches->first():null;
$nagalandSegments=$nagalandSeat?($kind==='pc'?collect($nagalandFixture['district_rows'])->whereIn('code',$nagalandSeat['ac_codes']):collect([$nagalandSeat])):collect();
$nagalandDistricts=$nagalandSegments->pluck('district')->unique();
$nagalandMapCodes=['Mon'=>'261','Mokokchung'=>'262','Zunheboto'=>'263','Wokha'=>'264','Dimapur'=>'265','Phek'=>'266','Tuensang'=>'267','Longleng'=>'268','Kiphire'=>'269','Kohima'=>'270','Peren'=>'271'];
@endphp
<section id="census-context" class="panel"><h2>District maps and source context</h2>
<p>District associations use the official CEO table with 2026 entries; territorial effective date remains unverified. Original conflicting district labels are retained visibly. ECI Atlas 2024 reports 60 AC segments for the statewide Nagaland PC. The historical order uses a 1 August 1978 territorial reference. District populations remain contextual and are never allocated to constituency polygons.</p>
<p>Village shapes use the official NWIC payload attributed to SOI. Modern source labels are retained separately from eleven Census districts; boundary edition and Census correspondence remain unverified. Partial-coverage placeholders, duplicate codes and unavailable Census joins remain flagged. Invalid shapes are excluded without repair; payload demographic values are withheld.</p>
@foreach($nagalandMapCodes as $nagalandMapName=>$nagalandMapCode)
@if($nagalandDistricts->contains($nagalandMapName))
<p><a href="{{ route('district-dashboard',['state'=>'13','district'=>$nagalandMapCode]) }}#map">{{ $nagalandMapName }} village map, Census history & graphs</a> · Modern source scope; historical boundary correspondence pending.</p>
@endif
@endforeach
<p>Electoral district references: {{ $nagalandDistricts->isEmpty()?'Unavailable':$nagalandDistricts->implode(', ') }}. Separate successor district map navigation remains pending.</p>
@foreach($nagalandFixture['flags'] as $nagalandFlag)<p>{{ $nagalandFlag }}</p>@endforeach
<p><a href="{{ $nagalandFixture['district_url'] }}" target="_blank" rel="noopener">Official CEO district–AC table</a> · CEO 2026 entries; effective date unverified. <a href="{{ $nagalandFixture['pc_url'] }}" target="_blank" rel="noopener">Official parliamentary order</a> · ECI Atlas 2024 statewide segment count.</p>
</section>
@endif
