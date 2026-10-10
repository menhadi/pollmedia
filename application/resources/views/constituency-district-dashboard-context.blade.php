@if($state==='Uttar Pradesh' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/up-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>$normalize($seat['name'])===$normalize($name));
$seat=$seats->count()===1?$seats->first():null;
$segments=$seat?($kind==='pc'?collect($fixture['district_rows'])->whereIn('code',$seat['ac_codes']):collect([$seat])):collect();
$codes=['Rampur'=>'136','Pilibhit'=>'151','Agra'=>'146','Aligarh'=>'143','Prayagraj'=>'175','Ambedkar Nagar'=>'178','Auraiya'=>'162','Azamgarh'=>'191','Baghpat'=>'139','Bahraich'=>'180','Ballia'=>'193','Balrampur'=>'182','Banda'=>'170','Barabanki'=>'176','Bareilly'=>'150','Basti'=>'185','Bijnor'=>'134','Badaun'=>'149','Bulandshahar'=>'142','Chandauli'=>'196','Chitrakoot'=>'171','Deoria'=>'190','Etah'=>'201'];
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
@if($districtNames->contains('Prayagraj'))<p class="notice">Census 2011 records use Allahabad. The electoral fixture uses Prayagraj, following the 18 October 2018 district renaming. This dated name correspondence does not establish matching boundary editions. <a href="https://prayagrajdivision.nic.in/about-department/introduction/">Official rename source</a>.</p>@endif
@if($districtNames->contains('Barabanki'))<p class="notice">Census 2011 and SOI source records use Bara Banki; the dated election fixture uses Barabanki. This name correspondence does not verify matching boundary editions. <a href="https://censusindia.gov.in/nada/index.php/catalog/6367">Census district 09/176</a> | <a href="https://barabanki.nic.in/about-district/geography/">Official district geography</a>.</p>@endif
@if($districtNames->contains('Badaun'))<p class="notice">Census 2011 and SOI records use Budaun; the dated electoral fixture uses Badaun. Official district notices use both spellings for AC 115. This name correspondence does not verify matching boundary editions or village coverage. Invalid source shapes remain excluded and documented; no population is assigned to shapes. <a href="https://budaun.nic.in/census/page/3/">Official electoral spelling source</a>.</p>@endif
@if($districtNames->contains('Bulandshahar'))<p class="notice">Census 2011 and SOI records use Bulandshahr; the dated electoral district fixture uses Bulandshahar. The official district constituency directory confirms AC codes 64–70. This spelling correspondence does not verify matching boundary editions or village coverage. Source shapes remain unlinked; no population is assigned to shapes. <a href="https://bulandshahar.nic.in/constituencies/">Official constituency directory</a>.</p>@endif
<div class="source-grid">
@foreach($districtNames as $district)
<article><h3>{{ $district }} district</h3>
@if(isset($codes[$district]))
@php($context=app(\App\Services\DistrictDashboardData::class)->published($codes[$district]))
@if($context)
<p>Census {{ $context['year'] }} district population: {{ $context['totals']['Total']['population']===null?'Unavailable':number_format($context['totals']['Total']['population']) }}.</p>
<p>Literacy age 7+: {{ $context['totals']['Total']['literacy']===null?'Unavailable':number_format($context['totals']['Total']['literacy'],2).'%' }}.</p><p>Read from published records on this request.</p>
@else
<p>Published district Census records are unavailable here. No population is inferred.</p>
@endif
<a href="{{ route('district-dashboard',['state'=>'09','district'=>$codes[$district]]) }}">District map, Census history & graphs →</a>
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
