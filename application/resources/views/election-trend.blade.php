@php
    $chartRows = collect($history)->reverse();
    $chartMax = $maximum ?? max(1, $chartRows->max($metric) ?? 0);
@endphp
<p class="small">Longer bars mean higher values. Colour follows the same scale: yellow (low), yellow-green (middle), teal (high). This is not a party colour or a performance rating. Scale: 0–{{ number_format($chartMax) }}{{ $suffix ?? '' }}. Coverage is shown beside each year; missing values have no bar.</p><div class="value-colour-legend" aria-hidden="true"><span>Low</span><span class="value-colour-ramp"></span><span>High</span></div><div class="trend-chart" aria-label="{{ $chartLabel }}">
@foreach($chartRows as $row)
@php $value = $row[$metric] ?? null; $ratio=min(1,max(0,($value??0)/$chartMax)); $barColour=sprintf('rgb(%d,%d,%d)',round(245+(15-245)*$ratio),round(205+(118-205)*$ratio),round(66+(110-66)*$ratio)); $covered=$row[$metric === 'turnout' ? 'turnout_count' : ($metric === 'margin' ? 'margin_count' : 'party_count')]; @endphp
<a class="trend-row" href="{{ route('states.show', ['state'=>$state, 'election'=>$kind, 'edition'=>$row['id'], 'party'=>$party]) }}" title="{{ $row['label'] }}">
<span>{{ $row['year'] }}<small class="trend-coverage">{{ $covered }}/{{ $row['tables'] }} tables</small></span><span class="trend-track" aria-hidden="true"><span class="trend-fill " style="background:{{ $barColour }};width:{{ $value === null ? 0 : min(100, max(0, 100 * $value / $chartMax)) }}%"></span></span><span class="trend-value">{{ $value === null ? 'No data' : number_format($value, $decimals ?? 1).($suffix ?? '') }}</span>
</a>
@endforeach
</div>
