@if($state==='Tripura' && !$exactSeatOnly)
@php
$tripuraFixture=json_decode(file_get_contents(database_path('fixtures/tripura-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$tripuraNormalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$tripuraMatches=collect($kind==='pc'?$tripuraFixture['pcs']:$tripuraFixture['district_rows'])->filter(fn(array $row):bool=>$tripuraNormalize($row['name'])===$tripuraNormalize($name));
$tripuraSeat=$tripuraMatches->count()===1?$tripuraMatches->first():null;
$tripuraSegments=$tripuraSeat?($kind==='pc'?collect($tripuraFixture['district_rows'])->whereIn('code',$tripuraSeat['ac_codes']):collect([$tripuraSeat])):collect();
$tripuraDistricts=$tripuraSegments->pluck('district')->unique();
$tripuraMapCodes=['West Tripura'=>'289','South Tripura'=>'290','Dhalai'=>'291','North Tripura'=>'292'];
@endphp
<section id="census-context" class="panel"><h2>District maps and source context</h2>
<p>District associations and full parliamentary segments use Schedule XXVII of the official 2008 order, with a 3 May 2005 territorial reference. Historical headings do not certify Census 2011 or modern source boundary correspondence. District populations remain contextual and are never allocated to constituency polygons.</p>
<p>Village shapes use the official NWIC payload attributed to SOI. Modern source labels are retained separately from four Census districts; eight modern source labels are retained separately with successor coverage gaps visible. Partial-coverage placeholders, duplicate codes and unavailable Census joins remain flagged. Invalid shapes are excluded without repair; payload demographic values are withheld.</p>
@foreach($tripuraMapCodes as $tripuraMapName=>$tripuraMapCode)
@if($tripuraDistricts->contains($tripuraMapName))
<p><a href="{{ route('district-dashboard',['state'=>'16','district'=>$tripuraMapCode]) }}#map">{{ $tripuraMapName }} village map, Census history & graphs</a> · Modern source scope; historical boundary correspondence pending.</p>
@endif
@endforeach
<p>Electoral district references: {{ $tripuraDistricts->isEmpty()?'Unavailable':$tripuraDistricts->implode(', ') }}. Separate successor district map navigation remains pending.</p>
@foreach($tripuraFixture['flags'] as $tripuraFlag)<p>{{ $tripuraFlag }}</p>@endforeach
<p><a href="{{ $tripuraFixture['district_url'] }}" target="_blank" rel="noopener">Official CEO district–AC table</a> · Historical territorial reference 2005-05-03. <a href="{{ $tripuraFixture['pc_url'] }}" target="_blank" rel="noopener">Official parliamentary order</a> · Historical reference 2005-05-03.</p>
</section>
@endif
