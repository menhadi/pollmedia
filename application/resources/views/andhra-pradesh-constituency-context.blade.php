@if($state==='Andhra Pradesh' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/andhra-pradesh-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>str_replace('thiruvottiyur','tiruvottiyur',mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value))));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>$normalize($seat['name'])===$normalize($name));
$seat=$seats->count()===1?$seats->first():null;
$segments=$seat?($kind==='pc'?collect($fixture['district_rows'])->whereIn('code',$seat['ac_codes']):collect([$seat])):collect();
$segments=$segments->map(fn(array $row):array=>array_merge($row,['district'=>$row['context_district']??$row['district']]));
$codes=['Srikakulam'=>'542','Vizianagaram'=>'543','Visakhapatnam'=>'544','East Godavari'=>'545','West Godavari'=>'546','Krishna'=>'547','Guntur'=>'548','Prakasam'=>'549','Nellore'=>'550','Chittoor'=>'554','Kadapa'=>'551','Kurnool'=>'552','Anantapur'=>'553'];
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
<p class="notice">2014 reorganisation schedule district references are retained. Current district correspondence, SOI boundary edition and accepted Census/LGD village links remain unverified. No district totals are assigned to constituencies.</p>
<p class="notice">Dated electoral fixture includes all175 AC rows and25 PCs from the 2014 schedule. Source conflicts remain flagged for formal review; complete code coverage does not certify current boundaries or data accuracy. Current SOI district coverage differs from historical Census and electoral geography.</p>
<p class="notice">PC7 source conflict: the 2014 parliamentary table names Mandapeta but prints AC47; the Assembly table identifies Mandapeta as AC48. Links use Assembly-table code48 and retain the original conflict for formal review. No boundary correspondence is implied.</p>
<p class="notice">The 2014 electoral schedule uses Nellore; Census district28/550 is Sri Potti Sriramulu Nellore. This historical district context does not establish correspondence with later SOI boundaries.</p>
<p class="notice">The 2014 electoral schedule labels this district Kadapa; Census28/551 and the preserved SOI source use Y.S.R. Context retains these dated names without certifying matching boundaries.</p>
<p><a href="{{ route('andhra-pradesh-source-maps') }}">Additional SOI physical source maps</a> · Explicit source labels; district correspondence unverified.</p>
<div class="metrics">
@foreach($districtNames as $district)
<article><h3>{{ $district }} district</h3>
@if(isset($codes[$district]))
@php($context=app(\App\Services\DistrictDashboardData::class)->published($codes[$district], '28'))
@if($context)
<p>Census {{ $context['year'] }} district population: {{ $context['totals']['Total']['population']===null?'Unavailable':number_format($context['totals']['Total']['population']) }}.</p>
<p>Literacy age 7+: {{ $context['totals']['Total']['literacy']===null?'Unavailable':number_format($context['totals']['Total']['literacy'],2).'%' }}.</p><p>Read from published records on this request.</p>
@else
<p>Published district Census records are unavailable here. No population is inferred.</p>
@endif
<a href="{{ route('district-dashboard',['state'=>'28','district'=>$codes[$district]]) }}">District map, Census history & graphs â†’</a>
@else
<p>District code crosswalk is not established in this panel. See the dated geographic source.</p>
@endif
</article>
@endforeach
</div>
@if($kind==='pc')
<p>Assembly segments: {{ $segments->map(fn(array $row):string=>$row['name'].' (AC '.$row['code'].')')->implode(', ') }}.</p>
@endif
<details><summary>Scope flags & sources</summary><p>District reference {{ $fixture['district_source_date'] }}; parliamentary reference {{ $fixture['pc_source_date'] }}. Territorial divisions refer to 15 February 2004. Shared names do not establish historical equivalence. Existing election warnings and unverified map dates remain visible.</p><a href="{{ $fixture['district_url'] }}">Districtâ€“Assembly source â†—</a> Â· <a href="{{ $fixture['pc_url'] }}">Parliamentary delimitation source â†—</a></details></section>
@endif
@endif
