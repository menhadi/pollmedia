@if($state==='West Bengal' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/west-bengal-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>collect(array_merge([$seat['name']],$seat['aliases']??[]))->contains(fn(string $alias):bool=>$normalize($alias)===$normalize($name)));
$seat=$seats->count()===1?$seats->first():null;
$segments=$seat?($kind==='pc'?collect($fixture['district_rows'])->whereIn('code',$seat['ac_codes']):collect([$seat])):collect();
$districtNames=$segments->pluck('district')->unique()->values();
$codes=['Murshidabad'=>'333','Nadia'=>'336','North Twenty Four Parganas'=>'337','Hugli'=>'338','Puruliya'=>'340','Bankura'=>'339','Koch Bihar'=>'329','Purba Medinipur'=>'345','Uttar Dinajpur'=>'330','Dakshin Dinajpur'=>'331','Maldah'=>'332','Birbhum'=>'334','Haora'=>'341','Kolkata'=>'342','South Twenty Four Parganas'=>'343'];
@endphp
@if(!$seat && $seats->count()>1)<p class="notice">Multiple official constituency codes share this name. District context is withheld until the route identifies the exact code; no geographic match is inferred.</p>@endif
@if($seat)
<section id="census-context" class="panel"><p class="eyebrow">Constituency dashboard · Census context</p><h2>District context & geographic scope</h2>
<p>{{ strtoupper($kind) }} {{ $seat['code'] }} · {{ $seat['name'] }}. Membership follows preserved official CEO sources.</p>
<p class="notice">District figures are context, never constituency population. Boundary edition and Census/LGD correspondence remain unverified. The West Bengal fixture contains all 294 AC codes and 42 complete PC segment lists; these complete PC segments follow the preserved CEO 2024 administrative list. Boundary correspondence is unverified. Population is never allocated by polygon area.</p>
<p class="notice">District references: {{ $districtNames->implode(', ') }}. Their combined populations are not a constituency total.</p>
<ul>@foreach($segments as $segment)<li>AC {{ $segment['code'] }} · {{ $segment['name'] }} · {{ $segment['district'] }}@if(isset($segment['source_district_name'])) (CEO source: {{ $segment['source_district_name'] }}; boundary correspondence unverified)@endif</li>@endforeach</ul>
@foreach($districtNames as $district)
@if(in_array($district,['Purba Bardhaman','Paschim Bardhaman','Jhargram','Paschim Medinipur'],true))
<p><a href="{{ route('modern-district-source-map',['slug'=>strtolower(str_replace(' ','-',$district))]) }}">{{ $district }} source map &amp; geographic evidence</a>. Formed in 2017; Census2011 parent totals are not assigned.</p>
@endif
@if(isset($codes[$district]))
@php($context=app(\App\Services\DistrictDashboardData::class)->published($codes[$district],'19'))
@if($context)
<div class="metrics"><article><h3>{{ $district }} · Census {{ $context['year'] }}</h3><p>District population: {{ number_format($context['totals']['Total']['population']) }}</p><a href="{{ route('district-dashboard',['state'=>'19','district'=>$codes[$district]]) }}">District map, graphs & source records</a></article></div>
@endif
@endif
@endforeach
<p><a href="{{ $fixture['district_url'] }}">Official CEO constituency list</a> · <a href="{{ $fixture['pc_url'] }}">Official membership source</a></p>
<p class="muted">Source edition dates remain unverified; retrieval dates and checksums are retained in the membership fixture.</p>
</section>
@endif
@endif
