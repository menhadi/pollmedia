@if($state==='Gujarat' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/gujarat-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>$normalize($seat['name'])===$normalize($name));
$seat=$seats->count()===1?$seats->first():null;
$segments=$seat?($kind==='pc'?collect($fixture['district_rows'])->whereIn('code',$seat['ac_codes']):collect([$seat])):collect();
$codes=['Kachchh'=>'468','Rajkot'=>'476','Banaskantha'=>'469','Patan'=>'470','Mahesana'=>'471','Gandhinagar'=>'473','Sabarkantha'=>'472','Ahmedabad'=>'474','Surendranagar'=>'475','Kheda'=>'483','Porbandar'=>'478','Junagadh'=>'479','Jamnagar'=>'477'];
$districtNames=$segments->pluck('district')->unique()->values();
@endphp
@if($seats->count()>1)<section id="census-context" class="panel"><h2>District context requires a constituency code</h2><p class="notice">This name matches multiple constituencies in the dated geographic fixture. District demographics are unavailable until the seat code is established; no district population is assigned from the name.</p><ul>@foreach($seats as $candidate)<li>{{ strtoupper($kind) }} {{ $candidate['code'] }} Ã‚Â· {{ $candidate['name'] }}@if(isset($candidate['district'])) Ã‚Â· {{ $candidate['district'] }}@endif</li>@endforeach</ul></section>@endif
@if($seat && $districtNames->contains(fn(string $district):bool=>isset($codes[$district])))
<section id="census-context" class="panel"><p class="eyebrow">Constituency dashboard Ã‚Â· Census context</p><h2>District demographics & geographic scope</h2>
<p>{{ strtoupper($kind) }} {{ $seat['code'] }} Ã‚Â· {{ $seat['name'] }}. Links use the dated districtÃ¢â‚¬â€œAssembly gazette and parliamentary delimitation fixture.</p>
<p class="notice">Constituency Census population is not established by this district link. District figures below are contextual, not AC/PC totals. Population is never divided by polygon area. Electoral maps, Census geography and historical constituencies may describe different years.</p>
@if($districtNames->count()>1)
<p class="notice">This constituency spans district references: {{ $districtNames->implode(', ') }}. Their combined populations are not a constituency total.</p>
@endif
<p class="notice">Partial dated ECI2008 Gujarat scope. Sabarkantha uses dated district membership; later Aravalli district geometry is not backcast into Census2011. Patan has 43 ambiguous source-code shapes. Banaskantha has fifty ambiguous source-code shapes. Its AC7 Vav source includes Kesargadh village of Patan; the dated district heading does not establish exclusive district geography. Shared PC3 includes Patan and Mahesana; PC4 also retains Mansa AC37 in dated Gandhinagar district; Shared PCs6/7 retain dated Ahmedabad segments; Ahmedabad shares PC9 with Surendranagar and PC17 with Kheda; Kheda integration remains pending. Municipal ward and later Botad/Morbi district geometry coverage remain unverified. Kachchh has 98 ambiguous source-code shapes; all accepted Census/LGD map links remain pending. PC1 includes Morbi AC65 in dated Rajkot district; later Morbi district geometry is not backcast into Census2011. Rajkot PC11 retains Porbandar and Junagadh segments; their integrations remain pending. Dated Tankara AC66 includes Jamnagar villages Challa/Golita; Jasdan AC72 includes Surendranagar village Ori; Jetpur AC74 includes Amreli village Devalki. Kalavad AC76 includes Rajkot villages Khokhri/Jivapar. District headings do not establish exclusive geography. Later Devbhumi Dwarka geometry is not backcast into Census2011; municipal ward coverage remains unverified.</p>
<div class="metrics">
@foreach($districtNames as $district)
<article><h3>{{ $district }} district</h3>
@if(isset($codes[$district]))
@php($context=app(\App\Services\DistrictDashboardData::class)->published($codes[$district], '24'))
@if($context)
<p>Census {{ $context['year'] }} district population: {{ $context['totals']['Total']['population']===null?'Unavailable':number_format($context['totals']['Total']['population']) }}.</p>
<p>Literacy age 7+: {{ $context['totals']['Total']['literacy']===null?'Unavailable':number_format($context['totals']['Total']['literacy'],2).'%' }}.</p><p>Read from published records on this request.</p>
@else
<p>Published district Census records are unavailable here. No population is inferred.</p>
@endif
<a href="{{ route('district-dashboard',['state'=>'24','district'=>$codes[$district]]) }}">District map, Census history & graphs Ã¢â€ â€™</a>
@else
<p>District code crosswalk is not established in this panel. See the dated geographic source.</p>
@endif
</article>
@endforeach
</div>
@if($kind==='pc')
<p>Assembly segments: {{ $segments->map(fn(array $row):string=>$row['name'].' (AC '.$row['code'].')')->implode(', ') }}.</p>
@endif
<details><summary>Scope flags & sources</summary><p>District reference {{ $fixture['district_source_date'] }}; parliamentary reference {{ $fixture['pc_source_date'] }}. Shared names do not establish historical equivalence. Existing election warnings and unverified map dates remain visible.</p><a href="{{ $fixture['district_url'] }}">DistrictÃ¢â‚¬â€œAssembly source Ã¢â€ â€”</a> Ã‚Â· <a href="{{ $fixture['pc_url'] }}">Parliamentary delimitation source Ã¢â€ â€”</a></details></section>
@endif
@endif
