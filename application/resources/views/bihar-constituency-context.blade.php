@if($state==='Bihar' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/bihar-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>$normalize($seat['name'])===$normalize($name));
$seat=$seats->count()===1?$seats->first():null;
$segments=$seat?($kind==='pc'?collect($fixture['district_rows'])->whereIn('code',$seat['ac_codes']):collect([$seat])):collect();
$districtNames=$segments->pluck('district')->unique()->values();
$codes=['Araria'=>'209','Arwal'=>'240','Aurangabad'=>'235','Banka'=>'225','Begusarai'=>'222','Bhojpur'=>'231','Bhagalpur'=>'224','Buxar'=>'232','Gaya'=>'236','Darbhanga'=>'215','Gopalganj'=>'217'];
@endphp
@if($seat)
<section id="census-context" class="panel"><p class="eyebrow">Constituency dashboard · Census context</p><h2>District context & geographic scope</h2>
<p>{{ strtoupper($kind) }} {{ $seat['code'] }} · {{ $seat['name'] }}. Membership follows preserved official CEO sources.</p>
<p class="notice">District figures are context, never constituency population. Boundary edition and Census/LGD correspondence remain unverified. The Bihar membership fixture remains partial; recorded PC segment lists are preserved. Population is never allocated by polygon area.</p>
<p class="notice">District references: {{ $districtNames->implode(', ') }}. Their combined populations are not a constituency total.</p>
<ul>@foreach($segments as $segment)<li>AC {{ $segment['code'] }} · {{ $segment['name'] }} · {{ $segment['district'] }}</li>@endforeach</ul>
@foreach($districtNames as $district)
@if(isset($codes[$district]))
@php($context=app(\App\Services\DistrictDashboardData::class)->published($codes[$district],'10'))
@if($context)
<div class="metrics"><article><h3>{{ $district }} · Census {{ $context['year'] }}</h3><p>District population: {{ number_format($context['totals']['Total']['population']) }}</p><a href="{{ route('district-dashboard',['state'=>'10','district'=>$codes[$district]]) }}">District map, graphs & source records</a></article></div>
@endif
@endif
@endforeach
<p><a href="{{ $fixture['district_url'] }}">Official CEO constituency list</a> · <a href="{{ $fixture['pc_url'] }}">Official membership source</a></p>
<p class="muted">Source edition dates remain unverified; retrieval dates and checksums are retained in the membership fixture.</p>
</section>
@endif
@endif
