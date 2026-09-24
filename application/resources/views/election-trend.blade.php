@php
    $chartRows = collect($history)->reverse();
    $chartMax = $maximum ?? max(1, $chartRows->max($metric) ?? 0);
    $colourValues = $chartRows->pluck($metric)->filter(fn($value) => $value !== null);
    $colourMin = $colourValues->min();
    $colourMax = $colourValues->max();
@endphp
<p class="small">Bar length uses 0–{{ number_format($chartMax) }}{{ $suffix ?? '' }}. Colour uses this chart's displayed minimum and maximum: yellow (lowest), green (middle), teal (highest). Colours are relative to this chart, not party labels or performance ratings. Coverage is shown beside each year; missing values have no bar.</p>
@if($colourMin !== null)<div class="value-colour-legend"><span>{{ number_format($colourMin, $decimals ?? 1) }}{{ $suffix ?? '' }} · lowest</span><span class="value-colour-ramp" aria-hidden="true"></span><span>{{ number_format($colourMax, $decimals ?? 1) }}{{ $suffix ?? '' }} · highest</span></div>@endif
<div class="trend-chart" aria-label="{{ $chartLabel }}">
@foreach($chartRows as $row)
@php $value = $row[$metric] ?? null; $ratio=$colourMax > $colourMin ? min(1,max(0,(($value??$colourMin)-$colourMin)/($colourMax-$colourMin))) : 0.5; $barColour=sprintf('rgb(%d,%d,%d)',round(245+(15-245)*$ratio),round(205+(118-205)*$ratio),round(66+(110-66)*$ratio)); $covered=$row[$metric === 'turnout' ? 'turnout_count' : ($metric === 'margin' ? 'margin_count' : 'party_count')]; @endphp
<a class="trend-row" href="{{ route('states.show', ['state'=>$state, 'election'=>$kind, 'edition'=>$row['id'], 'party'=>$party]) }}" title="{{ $row['label'] }}">
<span>{{ $row['year'] }}<small class="trend-coverage">{{ $covered }}/{{ $row['tables'] }} tables</small></span><span class="trend-track" aria-hidden="true"><span class="trend-fill " style="background:{{ $barColour }};width:{{ $value === null ? 0 : min(100, max(0, 100 * $value / $chartMax)) }}%"></span></span><span class="trend-value">{{ $value === null ? 'No data' : number_format($value, $decimals ?? 1).($suffix ?? '') }}</span>
</a>
@endforeach
</div>
