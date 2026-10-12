@if($state==='Manipur' && !$exactSeatOnly)
@php
$manipurFixture=json_decode(file_get_contents(database_path('fixtures/manipur-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$manipurNormalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$manipurMatches=collect($kind==='pc'?$manipurFixture['pcs']:$manipurFixture['district_rows'])->filter(fn(array $row):bool=>$manipurNormalize($row['name'])===$manipurNormalize($name));
$manipurSeat=$manipurMatches->count()===1?$manipurMatches->first():null;
$manipurSegments=$manipurSeat?($kind==='pc'?collect($manipurFixture['district_rows'])->whereIn('code',$manipurSeat['ac_codes']):collect([$manipurSeat])):collect();
$manipurDistricts=$manipurSegments->pluck('district')->unique();
$manipurMapCodes=['Senapati'=>'272','Tamenglong'=>'273','Churachandpur'=>'274','Bishnupur'=>'275','Thoubal'=>'276','Imphal West'=>'277','Imphal East'=>'278','Ukhrul'=>'279','Chandel'=>'280'];
@endphp
<section id="census-context" class="panel"><h2>District maps and source context</h2>
<p>District associations use the official CEO SIR 2026 table. These modern electoral districts do not certify Census 2011 boundary correspondence. Parliamentary segments use Schedule XVIII of the 2008 consolidated order, reproducing the 1976 order with a 1 August 1975 territorial reference. District populations remain contextual and are never allocated to constituency polygons.</p>
<p>Village shapes use the official NWIC payload attributed to SOI. Modern source labels are retained separately from nine Census districts; successor labels are not silently merged. Partial-coverage placeholders, duplicate codes and unavailable Census joins remain flagged. Invalid shapes are excluded without repair; payload demographic values are withheld.</p>
@foreach($manipurMapCodes as $manipurMapName=>$manipurMapCode)
@if($manipurDistricts->contains($manipurMapName))
<p><a href="{{ route('district-dashboard',['state'=>'14','district'=>$manipurMapCode]) }}#map">{{ $manipurMapName }} village map, Census history & graphs</a> · Modern source scope; historical boundary correspondence pending.</p>
@endif
@endforeach
<p>Electoral district references: {{ $manipurDistricts->isEmpty()?'Unavailable':$manipurDistricts->implode(', ') }}. Separate successor district map navigation remains pending.</p>
@foreach($manipurFixture['flags'] as $manipurFlag)<p>{{ $manipurFlag }}</p>@endforeach
<p><a href="{{ $manipurFixture['district_url'] }}" target="_blank" rel="noopener">Official CEO district–AC table</a> · SIR 2026 page; territorial effective date unverified. <a href="{{ $manipurFixture['pc_url'] }}" target="_blank" rel="noopener">Official parliamentary order</a> · Historical reference 1975-08-01.</p>
</section>
@endif
