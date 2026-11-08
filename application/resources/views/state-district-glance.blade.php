@php
    $hi=app()->getLocale()==='hi';
    $districtRows=$districtOptions;
@endphp
<section class="state-district-glance" aria-label="{{ $hi?'ज़िलों का सारांश':'District overview' }}">
<dl class="compact-facts">
@foreach([[$hi?'जनसंख्या':'Population',$areaValues['population']??null,0,''],[$hi?'जनगणना में ज़िले':'Census districts',$districtRows->isNotEmpty()?$districtRows->count():null,0,''],[$hi?'साक्षरता, आयु 7+':'Literacy, age 7+',$areaValues['literacy']??null,2,'%']] as [$label,$value,$precision,$unit])
@if($value!==null)<div><dt>{{ $label }}</dt><dd>{{ number_format($value,$precision).$unit }}</dd></div>@endif
@endforeach
</dl>
<p class="compact-meta">{{ $hi?'जनगणना':'Census' }} {{ $year }} · {{ \App\Services\PublicLanguage::text($residence) }}@if(isset($areaValues['households'])) · {{ number_format($areaValues['households']) }} {{ $hi?'परिवार':'households' }}@endif</p>
@include('residence-breakdown',['populationTotal'=>$residence==='Total'?($areaValues['population']??null):null,'ruralCount'=>$residencePopulation['Rural']??null,'urbanCount'=>$residencePopulation['Urban']??null])
@if($topic==='overview' && $districtRows->isNotEmpty())
<section class="compact-directory" data-local-data-browser data-district-list>
<div class="compact-section-title"><h2>{{ $hi?'ज़िला निर्देशिका':'District directory' }}</h2><label hidden data-seat-tools><input type="search" aria-label="{{ $hi?'ज़िला खोजें':'Find a district' }}" placeholder="{{ $hi?'ज़िला खोजें':'Find a district' }}"></label></div>
<p class="compact-meta">{{ $hi?'ज़िला कुल':'District totals' }} · {{ $year }}</p>
<table><thead><tr><th>{{ $hi?'ज़िला':'District' }}</th><th>{{ $hi?'जनसंख्या':'Population' }}</th><th>{{ $hi?'साक्षरता, आयु 7+':'Literacy, age 7+' }}</th></tr></thead><tbody>
@foreach($districtRows as $row)
@php($metrics=\App\Services\VillageMapMetrics::values(json_decode($row->values,true)))
<tr><th scope="row"><a href="{{ ((int)$year===2011 ? route('district-dashboard',['state'=>$row->state_code,'district'=>$row->district_code,'lang'=>app()->getLocale()==='hi'?'hi':null]) : route('civic.place',['record'=>$row->id,'lang'=>$hi?'hi':null])) }}">{{ \Illuminate\Support\Str::title($row->name) }}</a></th><td>{{ isset($metrics['population'])?number_format($metrics['population']):'—' }}</td><td>{{ isset($metrics['literacy'])?number_format($metrics['literacy'],2).'%':'—' }}</td></tr>
@endforeach
</tbody></table>
@include('state-table-pager')
<p><a href="{{ request()->fullUrlWithQuery(['topic'=>'places']) }}">{{ $hi?'ज़िलों के पूरे रिकॉर्ड':'Full district records' }} →</a></p></section>
@endif
</section>
