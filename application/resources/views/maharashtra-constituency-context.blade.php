@if($state==='Maharashtra' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/maharashtra-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>$normalize($seat['name'])===$normalize($name));
$seat=$seats->count()===1?$seats->first():null;
$segments=$seat?($kind==='pc'?collect($fixture['district_rows'])->whereIn('code',$seat['ac_codes']):collect([$seat])):collect();
$codes=['Nandurbar'=>'497','Dhule'=>'498','Nashik'=>'516','Jalgaon'=>'499','Buldhana'=>'500','Akola'=>'501','Washim'=>'502','Yavatmal'=>'510','Amravati'=>'503','Wardha'=>'504','Nagpur'=>'505','Bhandara'=>'506','Gondiya'=>'507','Gadchiroli'=>'508','Chandrapur'=>'509','Nanded'=>'511','Hingoli'=>'512','Latur'=>'524','Parbhani'=>'513','Jalna'=>'514','Aurangabad'=>'515','Thane'=>'517','Mumbai Suburban'=>'518','Mumbai City'=>'519','Raigad'=>'520','Pune'=>'521','Ratnagiri'=>'528','Ahmednagar'=>'522','Beed'=>'523','Osmanabad'=>'525','Solapur'=>'526','Satara'=>'527'];
$districtNames=$segments->pluck('district')->unique()->values();
@endphp
@if($seats->count()>1)<section id="census-context" class="panel"><h2>District context requires a constituency code</h2><p class="notice">This name matches multiple constituencies in the dated geographic fixture. District demographics are unavailable until the seat code is established; no district population is assigned from the name.</p><ul>@foreach($seats as $candidate)<li>{{ strtoupper($kind) }} {{ $candidate['code'] }} · {{ $candidate['name'] }}@if(isset($candidate['district'])) · {{ $candidate['district'] }}@endif</li>@endforeach</ul></section>@endif
@if($seat && $districtNames->contains(fn(string $district):bool=>isset($codes[$district])))
<section id="census-context" class="panel"><p class="eyebrow">Constituency dashboard · Census context</p><h2>District demographics & geographic scope</h2>
<p>{{ strtoupper($kind) }} {{ $seat['code'] }} · {{ $seat['name'] }}. Links use the dated district–Assembly gazette and parliamentary delimitation fixture.</p>
<p class="notice">Constituency Census population is not established by this district link. District figures below are contextual, not AC/PC totals. Population is never divided by polygon area. Electoral maps, Census geography and historical constituencies may describe different years.</p>
@if($districtNames->count()>1)
<p class="notice">This constituency spans district references: {{ $districtNames->implode(', ') }}. Their combined populations are not a constituency total.</p>
@endif
<p class="notice">Partial audited ECI 2008 scope. Osmanabad map retains SOI later label DHARASHIV; three ambiguous shapes remain unlinked, and equivalence to Census boundaries remains unverified. Census Bid and electoral Beed names are retained; map boundary edition and Census/LGD links remain unverified. Census Ahmadnagar and electoral Ahmednagar names are retained; map boundary edition and Census/LGD links remain unverified. Census Raigarh is shown under dated electoral name Raigad. Twenty source-code shapes are ambiguous; all Census/LGD links remain pending. Ratnagiri fixture scope is partial; its dashboard integration remains pending. Pune dated AC195-215 membership is complete; Census/LGD map links and boundary edition remain unverified. Mumbai Suburban and Mumbai City each have only one unlinked SOI source record. Ward, village and AC boundary geometry is unavailable; the source record is not treated as individual village coverage or assigned population. PC30 Mumbai South-Central retains membership across both districts. Census 2011 Thane includes the area later recorded as Palghar. The SOI map selected by district code 517 excludes Palghar code 601; it is partial coverage of the Census district and is not a historical boundary reconstruction. Dated 2008 electoral membership retains AC128-151 and PC22-25; district totals are context only. SOI source label NANDURB&gt;R is retained; boundary edition and Census/LGD joins remain unverified. Nandurbar has twenty ambiguous source-code shapes and one invalid shape excluded without repair. Dhule has five ambiguous source-code shapes. Jalgaon has eight ambiguous source-code shapes. Buldhana has five ambiguous source-code shapes. Akola source shape 530458 is invalid and excluded without repair. Amravati SOI label AMARĀWATI is retained; twelve ambiguous shapes and one invalid blank-code shape remain documented. Wardha has twenty-eight ambiguous source-code shapes. Nagpur has forty-seven ambiguous source-code shapes; the dated Nagpur West source prints ward 999, retained without inferring ward geometry. Bhandara has thirty-three ambiguous source-code shapes and one invalid blank-code shape excluded without repair. Gondiya SOI label GONDIA is retained; sixteen ambiguous source-code shapes remain unlinked. Nashik has four ambiguous source-code shapes; accepted Census/LGD links remain pending. Latur AC234-239 is complete in the dated fixture. Shared PC40 includes Osmanabad and Solapur; Solapur AC244-254 scope is complete. Shared PC43 retains Satara255/258; Satara AC255-262 scope is complete; accepted map links remain unverified. Aurangabad map uses source district code 515 and the later SOI label CHHATRAPATI SAMBHAJINAGAR; seven ambiguous shapes remain unlinked, and boundary equivalence to Census 2011 is unverified. Jalna has 976 source shapes with Census/LGD links pending. Parbhani has 854 source shapes with Census/LGD links pending. Hingoli has 711 source shapes with Census/LGD links pending. Nanded SOI literal label N&gt;NDED is retained; all 1685 source shapes remain unlinked. Yavatmal has four ambiguous source-code shapes; Census links remain pending. Chandrapur has fifty-three ambiguous source-code shapes; four invalid shapes are excluded without repair, including two blank codes, 540491 and 541391. Gadchiroli has sixty-four ambiguous source-code shapes and one invalid blank-code shape excluded without repair.</p>
<div class="metrics">
@foreach($districtNames as $district)
<article><h3>{{ $district }} district</h3>
@if(isset($codes[$district]))
@php($context=app(\App\Services\DistrictDashboardData::class)->published($codes[$district], '27'))
@if($context)
<p>Census {{ $context['year'] }} district population: {{ $context['totals']['Total']['population']===null?'Unavailable':number_format($context['totals']['Total']['population']) }}.</p>
<p>Literacy age 7+: {{ $context['totals']['Total']['literacy']===null?'Unavailable':number_format($context['totals']['Total']['literacy'],2).'%' }}.</p><p>Read from published records on this request.</p>
@else
<p>Published district Census records are unavailable here. No population is inferred.</p>
@endif
<a href="{{ route('district-dashboard',['state'=>'27','district'=>$codes[$district]]) }}">District map, Census history & graphs →</a>
@else
<p>District code crosswalk is not established in this panel. See the dated geographic source.</p>
@endif
</article>
@endforeach
</div>
@if($kind==='pc')
<p>Assembly segments: {{ $segments->map(fn(array $row):string=>$row['name'].' (AC '.$row['code'].')')->implode(', ') }}.</p>
@endif
<details><summary>Scope flags & sources</summary>@if(($kind ?? null) === 'pc' && ($seat['code'] ?? null) === 5)<p>ECI 2008 prints “5. Mehkar (SC)” in PC 5; its Assembly section lists AC 25. The separate <a href="https://ceoelection.maharashtra.gov.in/Downloads/PDF/PCs.pdf">official Maharashtra CEO PC list</a> corroborates AC 25. The original discrepancy is retained; this does not establish unchanged historical boundaries.</p>@endif<p>District reference {{ $fixture['district_source_date'] }}; parliamentary reference {{ $fixture['pc_source_date'] }}. Shared names do not establish historical equivalence. Existing election warnings and unverified map dates remain visible.</p><a href="{{ $fixture['district_url'] }}">District–Assembly source ↗</a> · <a href="{{ $fixture['pc_url'] }}">Parliamentary delimitation source ↗</a></details></section>
@endif
@endif
