@if($state==='Meghalaya' && !$exactSeatOnly)
@php
$meghalayaFixture=json_decode(file_get_contents(database_path('fixtures/meghalaya-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$meghalayaNormalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$meghalayaMatches=collect($kind==='pc'?$meghalayaFixture['pcs']:$meghalayaFixture['district_rows'])->filter(fn(array $row):bool=>$meghalayaNormalize($row['name'])===$meghalayaNormalize($name));
$meghalayaSeat=$meghalayaMatches->count()===1?$meghalayaMatches->first():null;
$meghalayaSegments=$meghalayaSeat?($kind==='pc'?collect($meghalayaFixture['district_rows'])->whereIn('code',$meghalayaSeat['ac_codes']):collect([$meghalayaSeat])):collect();
$meghalayaDistricts=$meghalayaSegments->pluck('district')->unique();
$meghalayaMapCodes=['West Garo Hills'=>'293','East Garo Hills'=>'294','South Garo Hills'=>'295','West Khasi Hills'=>'296','Ribhoi'=>'297','East Khasi Hills'=>'298','Jaintia Hills'=>'299'];
@endphp
<section id="census-context" class="panel"><h2>District maps and source context</h2>
<p>District associations and full parliamentary segments use Schedule XIX of the official 2008 order, with a 15 February 2004 territorial reference. Historical headings do not certify Census 2011 or modern source boundary correspondence. District populations remain contextual and are never allocated to constituency polygons.</p>
<p>Village shapes use the official NWIC payload attributed to SOI. Modern source labels are retained separately from seven Census districts; East and West Jaintia source labels are explicitly presented together without certifying the historical boundary. Partial-coverage placeholders, duplicate codes and unavailable Census joins remain flagged. Invalid shapes are excluded without repair; payload demographic values are withheld.</p>
@foreach($meghalayaMapCodes as $meghalayaMapName=>$meghalayaMapCode)
@if($meghalayaDistricts->contains($meghalayaMapName))
<p><a href="{{ route('district-dashboard',['state'=>'14','district'=>$meghalayaMapCode]) }}#map">{{ $meghalayaMapName }} village map, Census history & graphs</a> · Modern source scope; historical boundary correspondence pending.</p>
@endif
@endforeach
<p>Electoral district references: {{ $meghalayaDistricts->isEmpty()?'Unavailable':$meghalayaDistricts->implode(', ') }}. Separate successor district map navigation remains pending.</p>
@foreach($meghalayaFixture['flags'] as $meghalayaFlag)<p>{{ $meghalayaFlag }}</p>@endforeach
<p><a href="{{ $meghalayaFixture['district_url'] }}" target="_blank" rel="noopener">Official CEO district–AC table</a> · Historical territorial reference 2004-02-15. <a href="{{ $meghalayaFixture['pc_url'] }}" target="_blank" rel="noopener">Official parliamentary order</a> · Historical reference 2004-02-15.</p>
</section>
@endif
