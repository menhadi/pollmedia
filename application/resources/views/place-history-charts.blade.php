@php
$chartRows=$rows->sortBy('entry.year')->values()->map(function($row){
 $summary=$row['record']?app(\App\Services\HistoricalElectionAnalytics::class)->summarize([$row['record']]):[];
 return ['year'=>$row['entry']->year,'label'=>$row['entry']->edition_label,'turnout'=>$summary['turnout']??null,'margin'=>$summary['margin']??null,'polled'=>$summary['polled']??null,'electors'=>$summary['electors']??null];
});
@endphp
<section class="panel history-charts"><h2>Historical election trends</h2>
@include('line-chart',['linePoints'=>$chartRows,'lineTitle'=>'Available election years','lineMetrics'=>['turnout'=>'Turnout (%)','margin'=>'Winning margin (votes)','electors'=>'Registered electors','polled'=>'Votes polled']])
<div class="table-scroll"><table data-sortable><thead><tr><th>Election</th><th data-sort-type="number">Registered electors</th><th data-sort-type="number">Votes polled</th></tr></thead><tbody>@foreach($chartRows as $point)<tr><th>{{ $point['label'] }}</th><td>{{ $point['electors']===null?'—':number_format($point['electors']) }}</td><td>{{ $point['polled']===null?'—':number_format($point['polled']) }}</td></tr>@endforeach</tbody></table></div></section>
