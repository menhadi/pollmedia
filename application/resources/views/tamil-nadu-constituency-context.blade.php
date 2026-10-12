@if($state==='Tamil Nadu' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/tamil-nadu-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>str_replace('thiruvottiyur','tiruvottiyur',mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value))));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>$normalize($seat['name'])===$normalize($name));
$seat=$seats->count()===1?$seats->first():null;
$segments=$seat?($kind==='pc'?collect($fixture['district_rows'])->whereIn('code',$seat['ac_codes']):collect([$seat])):collect();
$segments=$segments->map(fn(array $row):array=>array_merge($row,['district'=>$row['context_district']??$row['district']]));
$codes=['Thiruvallur'=>'602','Chennai'=>'603','Kancheepuram'=>'604','Vellore'=>'605','Tiruvannamalai'=>'606','Viluppuram'=>'607','Salem'=>'608','Namakkal'=>'609','Erode'=>'610','The Nilgiris'=>'611','Dindigul'=>'612','Karur'=>'613','Tiruchirappalli'=>'614','Perambalur'=>'615','Ariyalur'=>'616','Cuddalore'=>'617','Nagapattinam'=>'618','Thiruvarur'=>'619','Thanjavur'=>'620','Pudukkottai'=>'621','Sivaganga'=>'622','Madurai'=>'623','Theni'=>'624','Virudhunagar'=>'625','Ramanathapuram'=>'626','Thoothukkudi'=>'627','Tirunelveli'=>'628','Kanniyakumari'=>'629'];
$districtNames=$segments->pluck('district')->unique()->values();
@endphp
@if($seats->count()>1)<section id="census-context" class="panel"><h2>District context requires a constituency code</h2><p class="notice">This name matches multiple constituencies in the dated geographic fixture. District demographics are unavailable until the seat code is established; no district population is assigned from the name.</p><ul>@foreach($seats as $candidate)<li>{{ strtoupper($kind) }} {{ $candidate['code'] }} Â· {{ $candidate['name'] }}@if(isset($candidate['district'])) Â· {{ $candidate['district'] }}@endif</li>@endforeach</ul></section>@endif
@if($seat && $districtNames->contains(fn(string $district):bool=>isset($codes[$district])))
<section id="census-context" class="panel"><p class="eyebrow">Constituency dashboard Â· Census context</p><h2>District demographics & geographic scope</h2>
<p>{{ strtoupper($kind) }} {{ $seat['code'] }} Â· {{ $seat['name'] }}. Links use the dated districtâ€“Assembly gazette and parliamentary delimitation fixture.</p>
<p class="notice">Constituency Census population is not established by this district link. District figures below are contextual, not AC/PC totals. Population is never divided by polygon area. Electoral maps, Census geography and historical constituencies may describe different years.</p>
@if($districtNames->count()>1)
<p class="notice">This constituency spans district references: {{ $districtNames->implode(', ') }}. Their combined populations are not a constituency total.</p>
@endif
<p class="notice">ECI 2008 district references are retained. Current district correspondence, SOI boundary edition and accepted Census/LGD village links remain unverified. No district totals are assigned to constituencies.</p>
@if($districtNames->contains('Kancheepuram'))<p class="notice">Partial district source coverage: SOI KANCHIPURAM is not established as the complete Census 2011 Kancheepuram outline. Later Chengalpattu shapes are not merged or backcast; matching editions and accepted Census/LGD links remain unverified.</p>@endif
@if($districtNames->contains('Vellore'))<p class="notice">Partial source coverage: SOI Vellore is not established as the full Census 2011 Vellore outline. Later Ranipet and Tirupathur shapes are not merged or backcast; boundary editions and Census/LGD joins remain unverified.</p>@endif
@if($districtNames->contains('Viluppuram'))<p class="notice">Partial source coverage: SOI Viluppuram is not established as the complete Census 2011 outline. Later Kallakkurichi shapes are not merged or backcast; matching editions and accepted Census/LGD links remain unverified.</p>@endif
@if($districtNames->contains('Erode'))<p class="notice">District links retain ECI 2008 Erode references, including Dharapuram and Kangayam. Later Tiruppur district correspondence and equivalence to Census 2011 Erode boundaries remain unverified. District figures are context only; no constituency totals are inferred.</p>@endif
@if($districtNames->contains('Tirunelveli'))<p class="notice">ECI 2008 and Census 2011 Tirunelveli references predate the later Tenkasi split. The SOI Tirunelveli map excludes separately labelled Tenkasi source shapes. Current boundaries and village correspondence remain unverified; district totals are context only.</p>@endif
@if($districtNames->contains('Perambalur'))<p class="notice">ECI 2008 Perambalur references include Ariyalur and Jayankondam AC149/150. Correspondence to later Ariyalur district and Census 2011 district boundaries remains unverified. Dated membership is retained; district totals are context only.</p>@endif
@if($districtNames->contains('Ariyalur'))<p class="notice">Ariyalur AC149 and Jayankondam AC150 district context follows the official district election page observed 12 October 2026. ECI 2008 Perambalur references are preserved; Census boundary equivalence remains unverified. <a href="https://ariyalur.nic.in/departments/election/">Official district seat source</a>.</p>@endif
@if($districtNames->contains('Nagapattinam'))<p class="notice">Partial source coverage: SOI Nagapattinam is not established as the complete Census 2011 outline. Later Mayiladuthurai shapes are not merged or backcast; dated ECI membership, current districts and matching Census/LGD boundary editions remain separate and unverified.</p>@endif
<div class="metrics">
@foreach($districtNames as $district)
<article><h3>{{ $district }} district</h3>
@if(isset($codes[$district]))
@php($context=app(\App\Services\DistrictDashboardData::class)->published($codes[$district], '33'))
@if($context)
<p>Census {{ $context['year'] }} district population: {{ $context['totals']['Total']['population']===null?'Unavailable':number_format($context['totals']['Total']['population']) }}.</p>
<p>Literacy age 7+: {{ $context['totals']['Total']['literacy']===null?'Unavailable':number_format($context['totals']['Total']['literacy'],2).'%' }}.</p><p>Read from published records on this request.</p>
@else
<p>Published district Census records are unavailable here. No population is inferred.</p>
@endif
<a href="{{ route('district-dashboard',['state'=>'33','district'=>$codes[$district]]) }}">District map, Census history & graphs â†’</a>
@else
<p>District code crosswalk is not established in this panel. See the dated geographic source.</p>
@endif
</article>
@endforeach
</div>
@if($kind==='pc')
<p>Assembly segments: {{ $segments->map(fn(array $row):string=>$row['name'].' (AC '.$row['code'].')')->implode(', ') }}.</p>
@endif
<details><summary>Scope flags & sources</summary><p>District reference {{ $fixture['district_source_date'] }}; parliamentary reference {{ $fixture['pc_source_date'] }}. Shared names do not establish historical equivalence. Existing election warnings and unverified map dates remain visible.</p><a href="{{ $fixture['district_url'] }}">Districtâ€“Assembly source â†—</a> Â· <a href="{{ $fixture['pc_url'] }}">Parliamentary delimitation source â†—</a></details></section>
@endif
@endif
