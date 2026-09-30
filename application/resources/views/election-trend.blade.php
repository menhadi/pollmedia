@php
    $chartRows = collect($chartHistory ?? $history)->filter(fn ($row) => ($row[$metric] ?? null) !== null)->reverse();
    $chartMax = $maximum ?? max(1, $chartRows->max($metric) ?? 0);
    $colourValues = $chartRows->pluck($metric)->filter(fn($value) => $value !== null);
    $colourMin = $colourValues->min();
    $colourMax = $colourValues->max();
    $chartTheme = app(\App\Services\SiteSettings::class)->appearance();
    $palette = collect(['ca8a04','65a30d','15803d','0f5e59'])->map(function($key) use($chartTheme) {
        $hex=ltrim($chartTheme['palette'][$key]??config('site.palette.'.$key),'#');
        if(strlen($hex)===3 || strlen($hex)===4) { $hex=implode('',array_map(fn($c)=>$c.$c,str_split($hex))); }
        return array_map('hexdec',str_split(substr($hex,0,6),2));
    })->all();
@endphp
<p class="small">Bars show the available figures for each year. Coverage is shown beside each year.</p>
@if($colourMin !== null)<div class="value-colour-legend"><span>{{ number_format($colourMin, $decimals ?? 1) }}{{ $suffix ?? '' }} · lowest</span><span class="value-colour-ramp" aria-hidden="true"></span><span>{{ number_format($colourMax, $decimals ?? 1) }}{{ $suffix ?? '' }} · highest</span></div>@endif
<div class="trend-chart" aria-label="{{ $chartLabel }}">
@foreach($chartRows as $row)
@php $value = $row[$metric] ?? null; $ratio=$colourMax > $colourMin ? min(1,max(0,(($value??$colourMin)-$colourMin)/($colourMax-$colourMin))) : 0.5; $position=$ratio*(count($palette)-1); $segment=min(count($palette)-2,(int)floor($position)); $fraction=$position-$segment;
    $rgb=[];
    for($channel=0;$channel<3;$channel++){
        $rgb[]=round($palette[$segment][$channel]+($palette[$segment+1][$channel]-$palette[$segment][$channel])*$fraction);
    }
    $barColour=sprintf('rgb(%d,%d,%d)',...$rgb); $covered=$row[$metric === 'turnout' ? 'turnout_count' : ($metric === 'margin' ? 'margin_count' : 'party_count')]; @endphp
<a class="trend-row" href="{{ route('states.show', ['state'=>$state, 'election'=>$kind, 'edition'=>$row['id'], 'party'=>$party]) }}" title="{{ $row['label'] }}">
<span>{{ $row['year'] }}<small class="trend-coverage">{{ $covered }}/{{ $row['tables'] }} tables</small></span><span class="trend-track" aria-hidden="true"><span class="trend-fill " style="background:{{ $barColour }};width:{{ min(100, max(0, 100 * $value / $chartMax)) }}%"></span></span><span class="trend-value">{{ number_format($value, $decimals ?? 1) }}{{ $suffix ?? '' }}{{ $metric === 'turnout' && ($row['turnout_review_count'] ?? 0) ? ' †' : '' }}</span>
</a>
@endforeach
</div>
