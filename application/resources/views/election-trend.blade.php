@php $linePoints=collect($history)->map(fn($point)=>array_merge($point,['url'=>route('states.show',['state'=>$state,'election'=>$kind,'edition'=>$point['id'],'party'=>$party])])); @endphp
@include('line-chart',['linePoints'=>$linePoints,'lineTitle'=>$chartLabel,'lineMetrics'=>[$metric=>$chartLabel]])
