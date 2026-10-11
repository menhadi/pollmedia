@if($state==='Karnataka' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/karnataka-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>$normalize($seat['name'])===$normalize($name));
$seat=$seats->count()===1?$seats->first():null;
$segments=$seat?($kind==='pc'?collect($fixture['district_rows'])->whereIn('code',$seat['ac_codes']):collect([$seat])):collect();
$codes=['Belgaum'=>'555','Bagalkot'=>'556','Bijapur'=>'557','Bidar'=>'558','Raichur'=>'559','Koppal'=>'560','Gadag'=>'561','Dharwad'=>'562','Uttara Kannada'=>'563','Haveri'=>'564','Bellary'=>'565','Chitradurga'=>'566','Davanagere'=>'567','Shimoga'=>'568','Udupi'=>'569','Chikmagalur'=>'570','Tumkur'=>'571','Bangalore'=>'572','Mandya'=>'573','Hassan'=>'574','Dakshina Kannada'=>'575','Kodagu'=>'576','Mysore'=>'577'];
$districtNames=$segments->pluck('district')->unique()->values();
@endphp
@if($seats->count()>1)<section id="census-context" class="panel"><h2>District context requires a constituency code</h2><p class="notice">This name matches multiple constituencies in the dated geographic fixture. District demographics are unavailable until the seat code is established; no district population is assigned from the name.</p><ul>@foreach($seats as $candidate)<li>{{ strtoupper($kind) }} {{ $candidate['code'] }} · {{ $candidate['name'] }}@if(isset($candidate['district'])) · {{ $candidate['district'] }}@endif</li>@endforeach</ul></section>@endif
@if($seat && $districtNames->contains(fn(string $district):bool=>isset($codes[$district])))
<section id="census-context" class="panel"><p class="eyebrow">Constituency dashboard · Census context</p><h2>District demographics & geographic scope</h2>
<p>{{ strtoupper($kind) }} {{ $seat['code'] }} · {{ $seat['name'] }}. Links use the dated district–Assembly gazette and parliamentary delimitation fixture.</p>
@if($districtNames->contains('Bellary'))<p class="notice">Partial source map: SOI Ballari coverage does not represent the complete Census 2011 Bellary district. Vijayanagara was later carved out of Bellary. Its source shapes are not merged or backcast; matching boundary editions and Census/LGD links remain unverified. <a href="https://vijayanagara.nic.in/en/history/">Official district history</a>.</p>@endif
@if($districtNames->contains('Davanagere'))<p class="notice">Source coverage mismatch: SOI Davanagere subdistrict labels omit Harapanahalli, which appears in the dated district–Assembly scope. This map is not established as the complete Census 2011 district outline. Later shapes are not merged or backcast; matching boundary editions and Census/LGD links remain unverified.</p>@endif
<p class="notice">Constituency Census population is not established by this district link. District figures below are contextual, not AC/PC totals. Population is never divided by polygon area. Electoral maps, Census geography and historical constituencies may describe different years.</p>
@if($districtNames->count()>1)
<p class="notice">This constituency spans district references: {{ $districtNames->implode(', ') }}. Their combined populations are not a constituency total.</p>
@endif
<p class="notice">Census 2011 uses Belgaum, Bagalkot and Bijapur; the SOI map uses Belagavi, Bagalkote and Vijayapura. Accepted village links and matching boundary editions remain unverified. The audited electoral fixture is partial; constituency name aliases require source review. No district totals are assigned to constituencies.</p>
@if($districtNames->contains('Bijapur'))<p class="notice">The official district website uses Vijayapura and identifies Bijapur as its former name. This naming evidence does not certify matching boundary editions. <a href="https://vijayapura.nic.in/en/about-district/">Official naming context</a></p>@endif
<div class="metrics">
@foreach($districtNames as $district)
<article><h3>{{ $district }} district</h3>
@if(isset($codes[$district]))
@php($context=app(\App\Services\DistrictDashboardData::class)->published($codes[$district], '29'))
@if($context)
<p>Census {{ $context['year'] }} district population: {{ $context['totals']['Total']['population']===null?'Unavailable':number_format($context['totals']['Total']['population']) }}.</p>
<p>Literacy age 7+: {{ $context['totals']['Total']['literacy']===null?'Unavailable':number_format($context['totals']['Total']['literacy'],2).'%' }}.</p><p>Read from published records on this request.</p>
@else
<p>Published district Census records are unavailable here. No population is inferred.</p>
@endif
<a href="{{ route('district-dashboard',['state'=>'29','district'=>$codes[$district]]) }}">District map, Census history & graphs →</a>
@else
<p>District code crosswalk is not established in this panel. See the dated geographic source.</p>
@endif
</article>
@endforeach
</div>
@if($kind==='pc')
<p>Assembly segments: {{ $segments->map(fn(array $row):string=>$row['name'].' (AC '.$row['code'].')')->implode(', ') }}.</p>
@endif
<details><summary>Scope flags & sources</summary><p>District reference {{ $fixture['district_source_date'] }}; parliamentary reference {{ $fixture['pc_source_date'] }}. Shared names do not establish historical equivalence. Existing election warnings and unverified map dates remain visible.</p><a href="{{ $fixture['district_url'] }}">District–Assembly source ↗</a> · <a href="{{ $fixture['pc_url'] }}">Parliamentary delimitation source ↗</a></details></section>
@endif
@endif
