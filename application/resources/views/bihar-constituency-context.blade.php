@if($state==='Bihar' && !$exactSeatOnly)
@php
$fixture=json_decode(file_get_contents(database_path('fixtures/bihar-electoral-geography.json')),true,512,JSON_THROW_ON_ERROR);
$normalize=fn(string $value):string=>mb_strtolower(trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i','',$value)));
$seats=collect($kind==='pc'?$fixture['pcs']:$fixture['district_rows'])->filter(fn(array $seat):bool=>$normalize($seat['name'])===$normalize($name));
$seat=$seats->count()===1?$seats->first():null;
@endphp
@if($seat)
<section id="census-context" class="panel"><p class="eyebrow">Constituency dashboard · Census context</p><h2>Araria district & geographic scope</h2>
<p>{{ strtoupper($kind) }} {{ $seat['code'] }} · {{ $seat['name'] }}. Official district sources place ACs 46–51 within Araria Parliamentary constituency.</p>
<p class="notice">District figures are context, never constituency population. Boundary edition and Census/LGD correspondence remain unverified. The Bihar membership fixture currently covers Araria only. Population is never allocated by polygon area.</p>
@php($context=app(\App\Services\DistrictDashboardData::class)->published('209','10'))
@if($context)
<div class="metrics"><article><h3>Araria · Census {{ $context['year'] }}</h3><p>District population: {{ number_format($context['totals']['Total']['population']) }}</p><a href="{{ route('district-dashboard',['state'=>'10','district'=>'209']) }}">District map, graphs & source records</a></article></div>
@endif
<p><a href="{{ $fixture['district_url'] }}">Official district constituency map</a> · <a href="{{ $fixture['pc_url'] }}">Official district membership</a></p>
<p class="muted">Source edition dates remain unverified; retrieval dates and checksums are retained in the membership fixture.</p>
</section>
@endif
@endif
