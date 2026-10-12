@if($state==='Mizoram' && !$exactSeatOnly)
@php
$mizoramFixture=json_decode(file_get_contents(database_path('fixtures/mizoram-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$mizoramNormalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$mizoramMatches=collect($kind==='pc'?$mizoramFixture['pcs']:$mizoramFixture['district_rows'])->filter(fn(array $row):bool=>$mizoramNormalize($row['name'])===$mizoramNormalize($name));
$mizoramSeat=$mizoramMatches->count()===1?$mizoramMatches->first():null;
$mizoramSegments=$mizoramSeat?($kind==='pc'?collect($mizoramFixture['district_rows'])->whereIn('code',$mizoramSeat['ac_codes']):collect([$mizoramSeat])):collect();
$mizoramDistricts=$mizoramSegments->pluck('district')->unique();
$mizoramMapCodes=['Mamit'=>'281','Kolasib'=>'282','Aizawl'=>'283','Champhai'=>'284','Serchhip'=>'285','Lunglei'=>'286','Lawngtlai'=>'287','Saiha'=>'288'];
@endphp
<section id="census-context" class="panel"><h2>District maps and source context</h2>
<p>District associations and statewide parliamentary scope use Schedule XX of the official 2008 order, with a 16 May 2005 territorial reference. Historical headings do not certify Census 2011 or modern source boundary correspondence. District populations remain contextual and are never allocated to constituency polygons.</p>
<p>Village shapes use the official NWIC payload attributed to SOI. Modern source labels are retained separately from eight Census districts; boundary edition and Census correspondence remain unverified. Partial-coverage placeholders, duplicate codes and unavailable Census joins remain flagged. Invalid shapes are excluded without repair; payload demographic values are withheld.</p>
@foreach($mizoramMapCodes as $mizoramMapName=>$mizoramMapCode)
@if($mizoramDistricts->contains($mizoramMapName))
<p><a href="{{ route('district-dashboard',['state'=>'14','district'=>$mizoramMapCode]) }}#map">{{ $mizoramMapName }} village map, Census history & graphs</a> · Modern source scope; historical boundary correspondence pending.</p>
@endif
@endforeach
<p>Electoral district references: {{ $mizoramDistricts->isEmpty()?'Unavailable':$mizoramDistricts->implode(', ') }}. Separate successor district map navigation remains pending.</p>
@foreach($mizoramFixture['flags'] as $mizoramFlag)<p>{{ $mizoramFlag }}</p>@endforeach
<p><a href="{{ $mizoramFixture['district_url'] }}" target="_blank" rel="noopener">Official CEO district–AC table</a> · Historical territorial reference 2005-05-16. <a href="{{ $mizoramFixture['pc_url'] }}" target="_blank" rel="noopener">Official parliamentary order</a> · Historical reference 2005-05-16.</p>
</section>
@endif
