@php
$colors=['var(--palette-315d91)','var(--site-accent)','var(--site-primary)'];
$chartValues=$plotRows->flatMap(fn($point)=>array_map(fn($series)=>$point[$series['key']],$plot['series']))->filter(fn($value)=>$value!==null);
$scale=$plot['unit']==='%'?100:max(1,($chartValues->max()??1)*1.08);
$firstYear=$plotRows->first()['year']??0; $lastYear=$plotRows->last()['year']??0;
$x=fn($year)=>$firstYear===$lastYear?410:70+($year-$firstYear)/($lastYear-$firstYear)*690;
$y=fn($value)=>240-$value/$scale*220;
@endphp
@if($chartValues->isNotEmpty())
<div class="report-legend">@foreach($plot['series'] as $series)<span><i style="background:{{ $series['key']==='others_share'?'var(--site-muted)':$colors[$loop->index] }}"></i>{{ $series['label'] }}</span>@endforeach</div>
<svg class="report-plot" viewBox="0 0 800 280" role="img" aria-label="{{ $plot['title'] }}; exact values in the following table">
@for($tick=0;$tick<=4;$tick++)
@php $value=$scale*$tick/4; @endphp
<line x1="70" x2="760" y1="{{ $y($value) }}" y2="{{ $y($value) }}" stroke="var(--site-border)"/>
<text x="60" y="{{ $y($value)+4 }}" text-anchor="end">{{ $plot['unit']==='%'?number_format($value,0).'%':($value>=10000000?round($value/10000000,1).' Cr.':($value>=100000?round($value/100000,1).' L':round($value))) }}</text>
@endfor
@foreach($plotRows as $point)
@if($loop->first || $loop->last || ($loop->index%max(1,(int)ceil($plotRows->count()/9))===0 && $x($lastYear)-$x($point['year'])>45))<text x="{{ $x($point['year']) }}" y="265" text-anchor="middle">{{ $point['year'] }}</text>@endif
@endforeach
@foreach($plot['series'] as $series)
@php
$color=$series['key']==='others_share'?'var(--site-muted)':$colors[$loop->index]; $path='';$area='';$previous=null;$start=null;
foreach($plotRows as $point){
    $value=$point[$series['key']];
    if($value===null){if($previous!==null){$area.='L'.$previous[0].',240 L'.$start.',240 Z ';}$previous=null;continue;}
    $xx=$x($point['year']);$yy=$y($value);
    if($previous!==null){$path.='L'.$xx.','.$yy.' ';$area.='L'.$xx.','.$yy.' ';}
    else{$path.='M'.$xx.','.$yy.' ';$area.='M'.$xx.','.$yy.' ';$start=$xx;}
    $previous=[$xx,$yy];
}
if($previous!==null){$area.='L'.$previous[0].',240 L'.$start.',240 Z ';}
@endphp
<path d="{{ $area }}" fill="{{ $color }}" fill-opacity="0.09" stroke="none"/>
<path d="{{ $path }}" fill="none" stroke="{{ $color }}" stroke-width="2.5" @if($series['key']==='others_share') stroke-dasharray="6 4" @endif/>
@endforeach
</svg>
@else<p>No usable figures are available for this chart.</p>@endif
