@php
    $chartRows = collect($history)->reverse();
    $chartMax = $maximum ?? max(1, $chartRows->max($metric) ?? 0);
@endphp
<p class="small">Longer and darker teal bars mean a higher value, not better performance. Scale: 0–{{ number_format($chartMax) }}{{ $suffix ?? '' }}. Coverage is shown beside each year; missing values have no bar.</p><div class="trend-chart" aria-label="{{ $chartLabel }}">
@foreach($chartRows as $row)
@php $value = $row[$metric] ?? null; $covered=$row[$metric === 'turnout' ? 'turnout_count' : ($metric === 'margin' ? 'margin_count' : 'party_count')]; @endphp
<a class="trend-row" href="{{ route('states.show', ['state'=>$state, 'election'=>$kind, 'edition'=>$row['id'], 'party'=>$party]) }}" title="{{ $row['label'] }}">
<span>{{ $row['year'] }}<small class="trend-coverage">{{ $covered }}/{{ $row['tables'] }} tables</small></span><span class="trend-track" aria-hidden="true"><span class="trend-fill " style="background:hsl(165 55% {{ 78-50*min(1,max(0,($value??0)/$chartMax)) }}%);width:{{ $value === null ? 0 : min(100, max(0, 100 * $value / $chartMax)) }}%"></span></span><span class="trend-value">{{ $value === null ? 'No data' : number_format($value, $decimals ?? 1).($suffix ?? '') }}</span>
</a>
@endforeach
</div>
