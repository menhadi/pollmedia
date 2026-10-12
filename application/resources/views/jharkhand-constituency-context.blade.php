@if($state==='Jharkhand' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/jharkhand-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>str_replace('thiruvottiyur','tiruvottiyur',mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value))));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>$normalize($seat['name'])===$normalize($name));
$seat=$seats->count()===1?$seats->first():null;
$segments=$seat?($kind==='pc'?collect($fixture['district_rows'])->whereIn('code',$seat['ac_codes']):collect([$seat])):collect();
$segments=$segments->map(fn(array $row):array=>array_merge($row,['district'=>$row['context_district']??$row['district']]));
$codes=['Garhwa'=>'346','Palamu'=>'358','Chatra'=>'347','Kodarma'=>'348','Giridih'=>'349','Hazaribagh'=>'360'];
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
<p class="notice">2008 Jharkhand electoral district references are retained. Current district correspondence, SOI boundary edition and accepted Census/LGD village links remain unverified. No district totals are assigned to constituencies.</p>
@if(isset($seat['source_code_note']))<p class="notice">{{ $seat['source_code_note'] }} Original source codes: {{ implode(', ', $seat['raw_ac_codes']) }}. Formal source review remains pending.</p><p><a href="{{ $fixture['supplementary_pc_source']['url'] }}">Official CEO PC list (publication date unverified)</a></p>@endif
<div class="metrics">
@foreach($districtNames as $district)
<article><h3>{{ $district }} district</h3>
@if(isset($codes[$district]))
@php($context=app(\App\Services\DistrictDashboardData::class)->published($codes[$district], '20'))
@if($context)
<p>Census {{ $context['year'] }} district population: {{ $context['totals']['Total']['population']===null?'Unavailable':number_format($context['totals']['Total']['population']) }}.</p>
<p>Literacy age 7+: {{ $context['totals']['Total']['literacy']===null?'Unavailable':number_format($context['totals']['Total']['literacy'],2).'%' }}.</p><p>Read from published records on this request.</p>
@else
<p>Published district Census records are unavailable here. No population is inferred.</p>
@endif
<a href="{{ route('district-dashboard',['state'=>'20','district'=>$codes[$district]]) }}">District map, Census history & graphs Ã¢â€ â€™</a>
@else
<p>District code crosswalk is not established in this panel. See the dated geographic source.</p>
@endif
</article>
@endforeach
</div>
@if($kind==='pc')
<p>Assembly segments: {{ $segments->map(fn(array $row):string=>$row['name'].' (AC '.$row['code'].')')->implode(', ') }}.</p>
@endif
<details><summary>Scope flags & sources</summary><p>District reference {{ $fixture['district_source_date'] }}; parliamentary reference {{ $fixture['pc_source_date'] }}. Partial fixture covers Garhwa and dated Palamau district references, complete shared Palamu PC13, Chatra PC4 and Kodarma PC5; other seats pending. The2008 PALAMAU heading includes Manika and Latehar. Modern Census district correspondence remains unverified; no silent reassignment. Official schedule refers to Bihar Re-organization Act2000 continuation. Exact notification date remains pending. Shared names do not establish historical equivalence. Existing election warnings and unverified map dates remain visible.</p><a href="{{ $fixture['district_url'] }}">DistrictÃ¢â‚¬â€œAssembly source Ã¢â€ â€”</a> Ã‚Â· <a href="{{ $fixture['pc_url'] }}">Parliamentary delimitation source Ã¢â€ â€”</a></details></section>
@endif
@endif
