@php
$chartRows=$rows->sortBy('entry.year')->values()->map(function($row){
 $summary=$row['record']?app(\App\Services\HistoricalElectionAnalytics::class)->summarize([$row['record']]):[];
 return ['year'=>$row['entry']->year,'label'=>$row['entry']->edition_label,'turnout'=>$summary['turnout']??null,'margin'=>$summary['margin']??null,'polled'=>$summary['polled']??null,'electors'=>$summary['electors']??null];
});
@endphp
<section class="panel history-charts" aria-label="Historical election charts"><div class="panel-heading"><div><p class="kicker">Across the years</p><h2>How voting has changed</h2></div><a class="place-action" href="#history">View the tables ↓</a></div>
@foreach(['turnout'=>'Voter turnout','margin'=>'Winning margin'] as $metric=>$label)
@php
$values=$chartRows->pluck($metric)->filter(fn($v)=>$v!==null);
$maximum=max(1,$values->max()??1);
$minimum=$values->min()??0;
$scale=$metric==='turnout'?100:$maximum;
@endphp
<div class="history-chart"><h3>{{ $label }}</h3>
<p class="small">Election year · {{ $metric==='turnout'?'Turnout (%) · scale 0–100%':'Margin (votes) · shared scale 0–'.number_format($maximum) }}</p>
<div class="history-horizontal" role="list" aria-label="{{ $label }} by election year">
@foreach($chartRows as $point)
@php
$value=$point[$metric];
$ratio=$maximum==$minimum?0.5:(($value??$minimum)-$minimum)/($maximum-$minimum);
$color=$ratio<0.33?'#936000':($ratio<0.66?'#487000':'#00665d');
@endphp
<div class="history-bar-row" role="listitem" title="{{ $point['label'] }}">
<span class="history-bar-year">{{ $point['year'] }}</span>
<span class="history-bar-track" aria-hidden="true">
@if($value!==null)
<span class="history-bar-fill" style="width:{{ 100*$value/$scale }}%;background:{{ $color }}"></span>
@endif
</span>
<strong class="history-bar-value">{{ $value===null?'—':number_format($value,$metric==='turnout'?2:0).($metric==='turnout'?'%':'') }}</strong>
</div>
@endforeach
</div>
<p class="small">Gold → green → teal: lower to higher values in this chart. A dash means unavailable or under review; it is not zero. Each bar represents an available election report.</p>
</div>
@endforeach
@php $totalMax=max(1,$chartRows->pluck('electors')->filter()->max()??1,$chartRows->pluck('polled')->filter()->max()??1); @endphp
<div class="history-chart"><h3>Registered electors and votes polled</h3>
<p class="chart-legend"><span><i style="background:#315d91"></i>Total registered electors</span><span><i style="background:#007668"></i>Votes polled</span></p>
<p class="small">Shared scale: 0–{{ number_format($totalMax) }} people.</p>
<div class="history-horizontal" role="list" aria-label="Registered electors and votes polled by year">
@foreach($chartRows as $point)
<div class="history-bar-pair" role="listitem" title="{{ $point['label'] }}">
@foreach(['electors'=>'#315d91','polled'=>'#007668'] as $key=>$fill)
@php $value=$point[$key]; @endphp
<div class="history-bar-row" aria-label="{{ $point['year'] }} · {{ $key==='electors'?'Registered electors':'Votes polled' }}">
<span class="history-bar-year">{{ $key==='electors'?$point['year']:'' }}</span>
<span class="history-bar-track" aria-hidden="true">
@if($value!==null)
<span class="history-bar-fill" style="width:{{ 100*$value/$totalMax }}%;background:{{ $fill }}"></span>
@endif
</span>
<strong class="history-bar-value">{{ $value===null?'—':number_format($value) }}</strong>
</div>
@endforeach
</div>
@endforeach
</div>
<p class="small">Absolute counts, not percentages. Missing figures are not treated as zero.</p>
<div class="table-scroll"><table data-sortable><thead><tr><th>Election</th><th data-sort-type="number">Registered electors</th><th data-sort-type="number">Votes polled</th></tr></thead><tbody>@foreach($chartRows as $point)<tr><th>{{ $point['label'] }}</th><td>{{ $point['electors']===null?'—':number_format($point['electors']) }}</td><td>{{ $point['polled']===null?'—':number_format($point['polled']) }}</td></tr>@endforeach</tbody></table></div></div></section>
