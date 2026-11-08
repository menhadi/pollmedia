@php
    $isHindi=app()->getLocale()==='hi';
    $percent=fn($part)=>$populationTotal>0 && is_numeric($part) && $part>=0 && $part<=$populationTotal?100*$part/$populationTotal:null;
    $complete=is_numeric($ruralCount) && is_numeric($urbanCount) && $populationTotal>0 && $ruralCount>=0 && $urbanCount>=0 && $ruralCount+$urbanCount==$populationTotal;
@endphp
@if(is_numeric($ruralCount) || is_numeric($urbanCount))
<section class="residence-split"><h2>{{ $isHindi?'निवास के अनुसार जनसंख्या':'Population by residence' }}</h2>
@if($complete)<div class="residence-track" aria-hidden="true"><i style="width:{{ $percent($ruralCount) }}%"></i></div>@endif
<div class="residence-values">
@foreach([[$isHindi?'ग्रामीण':'Rural',$ruralCount],[$isHindi?'शहरी':'Urban',$urbanCount]] as [$label,$count])
@if(is_numeric($count))<span>{{ $label }} <strong>{{ $percent($count)!==null?number_format($percent($count),2).'%':number_format($count) }}</strong>@if($percent($count)!==null)<small> ({{ number_format($count) }})</small>@endif</span>@endif
@endforeach
</div></section>
@endif
