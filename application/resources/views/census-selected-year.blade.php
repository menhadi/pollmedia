@if($place && (collect($censusSeries['rows'])->contains(fn($point)=>collect(['population','male','female','households','literates'])->contains(fn($key)=>($point[$key]??null)!==null)) || $records->contains(fn($row)=>collect(json_decode($row->values,true))->contains(fn($value)=>$value!==null && is_numeric($value)))))
<section class="panel census-year-data" id="census-years"><div class="panel-heading"><div><p class="eyebrow">Year-wise data</p><h2>{{ $place->name }} · Census figures</h2></div><div class="census-year-filter"><label for="census-display-year">Census year</label><select id="census-display-year" data-census-year>@foreach(array_reverse($censusSeries['rows']) as $point)<option value="{{ $point['year'] }}" @selected($point['year']===$year)>{{ $point['year'] }}</option>@endforeach</select></div></div>
@foreach(array_reverse($censusSeries['rows']) as $point)
<div data-census-year-panel="{{ $point['year'] }}" @if($point['year']!==$year) hidden @endif><div class="metrics">
@php
$currentValues=$point['year']===$year && $records->count()===1?json_decode($records->first()->values,true):[];
$pointFields=['TOT_P'=>'population','TOT_M'=>'male','TOT_F'=>'female','No_HH'=>'households','P_LIT'=>'literates'];
@endphp
@foreach($measures as $code=>$label)
@php($cardValue=$currentValues[$code]??$point[$pointFields[$code]??'']??null)
<article class="metric"><span>{{ $label }}</span><strong>{{ $cardValue===null?'NA':number_format($cardValue) }}</strong><span>{{ $point['year'] }} · {{ $residence }}</span></article>
@endforeach</div>
<details class="census-source-notes"><summary>Sources & data notes · {{ $point['year'] }}</summary>@foreach($point['notes'] as $note)<p>† {{ $note }}</p>@endforeach<a href="{{ $point['year']===$year?$edition?->source_url:($point['source_url']??$censusSeries['source']['url']??route('census.source-tables',['year'=>$point['year']])) }}" target="_blank" rel="noopener">Official Census source ↗</a></details></div>
@endforeach</section>@endif
