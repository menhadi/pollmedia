@if($state==='Arunachal Pradesh' && !$exactSeatOnly)
@php
$arunachalFixture=json_decode(file_get_contents(database_path('fixtures/arunachal-pradesh-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$arunachalNormalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$arunachalMatches=collect($kind==='pc'?$arunachalFixture['pcs']:$arunachalFixture['district_rows'])->filter(fn(array $row):bool=>$arunachalNormalize($row['name'])===$arunachalNormalize($name));
$arunachalSeat=$arunachalMatches->count()===1?$arunachalMatches->first():null;
$arunachalSegments=$arunachalSeat?($kind==='pc'?collect($arunachalFixture['district_rows'])->whereIn('code',$arunachalSeat['ac_codes']):collect([$arunachalSeat])):collect();
$arunachalDistricts=$arunachalSegments->pluck('district')->unique();
@endphp
<section id="census-context" class="panel"><h2>District maps and source context</h2>
<p>Electoral district headings use the official 1 July 1989 territorial reference, reproduced in the 2008 order. Correspondence to Census 2011 and current districts remains unverified. District populations are contextual and never allocated to constituency polygons.</p>
<p>Physical village shapes come from the official NWIC payload attributed to SOI. Its polygon records conflict with the SOI points-only catalogue entry; provenance and edition review remains pending. Duplicate village codes are flagged, invalid shapes excluded without repair, and payload demographic fields withheld.</p>
@if($arunachalDistricts->contains('Tawang'))
<a href="{{ route('district-dashboard',['state'=>'12','district'=>'245']) }}#map">Tawang village map, Census history & graphs</a>
@endif
<p>Historical district references: {{ $arunachalDistricts->isEmpty()?'Unavailable':$arunachalDistricts->implode(', ') }}. Additional district map links are pending rollout.</p>
<p><a href="{{ $arunachalFixture['district_url'] }}" target="_blank" rel="noopener">Official electoral source</a> · Territory reference 1989-07-01; underlying gazette notification 1989-07-17. Source spelling review pending.</p>
</section>
@endif
