@php
$reportMode=$reportMode??false;
$isStateHistory=isset($stateHistory);
$historyRows=$isStateHistory?collect($stateHistory)->map(fn($summary)=>['entry'=>(object)['year'=>$summary['year']],'summary'=>$summary]):$rows;
$chartRows=$historyRows->sortBy('entry.year')->values()->map(function($row){
 $summary=$row['summary']??($row['record']?app(\App\Services\HistoricalElectionAnalytics::class)->summarize([$row['record']]):[]);
 return ['year'=>$row['entry']->year,'turnout'=>$summary['turnout']??null,'margin'=>$summary['margin']??null,'polled'=>$summary['polled']??null,'electors'=>$summary['electors']??null,'parties'=>$summary['parties']??[],'review'=>max($summary['turnout_review_count']??0,$summary['margin_review_count']??0,$summary['party_review_count']??0)];
})->groupBy('year')->map(function($group){
 $point=['year'=>$group->first()['year'],'review'=>$group->max('review')];
 foreach(['turnout','margin','polled','electors','parties'] as $key){
  $values=$group->pluck($key)->unique(fn($value)=>json_encode($value));
  $point[$key]=$values->count()===1?$values->first():($key==='parties'?[]:null);
 }
 return $point;
})->values();
$partyTotals=[];
foreach($chartRows as $point){foreach($point['parties'] as $party){if(!in_array(strtoupper($party['party']),['NOTA','IND','INDEPENDENT'])){$partyTotals[$party['party']]=($partyTotals[$party['party']]??0)+$party['votes'];}}}
$fixedParties=collect($partyTotals)->map(fn($votes,$party)=>['party'=>(string)$party,'votes'=>$votes])->sortBy([['votes','desc'],['party','asc']])->take(3)->pluck('party')->values()->all();
$plotRows=$chartRows->map(function($point)use($fixedParties){
 $ranked=collect($point['parties'])->reject(fn($party)=>in_array(strtoupper($party['party']),['NOTA','IND','INDEPENDENT']))->sortBy([['votes','desc'],['party','asc']])->take(2)->values();
 foreach([0,1] as $i){$point['party'.$i]=$ranked[$i]['votes']??null;$point['party'.$i.'_name']=$ranked[$i]['party']??null;}
 $topParties=$ranked->pluck('party')->all();
 $point['others']=$point['parties']?collect($point['parties'])->reject(fn($party)=>in_array($party['party'],$topParties))->sum('votes'):null;
 $total=collect($point['parties'])->sum('votes');
 foreach($fixedParties as $i=>$party){$point['fixed'.$i]=$total>0?collect($point['parties'])->where('party',$party)->sum('votes'):null;$point['fixed'.$i.'_share']=$total>0?100*$point['fixed'.$i]/$total:null;}
 foreach(['party0','party1','others'] as $key){$point[$key.'_share']=$total>0&&$point[$key]!==null?100*$point[$key]/$total:null;}
 return $point;
});
$plots=[
 ['title'=>'Top three parties across the years','unit'=>'%','series'=>array_map(fn($party,$i)=>['key'=>'fixed'.$i.'_share','votes_key'=>'fixed'.$i,'label'=>$party],$fixedParties,array_keys($fixedParties))],
 ['title'=>'Party vote shares by year','unit'=>'%','series'=>[['key'=>'party0_share','votes_key'=>'party0','name_key'=>'party0_name','label'=>'1st party'],['key'=>'party1_share','votes_key'=>'party1','name_key'=>'party1_name','label'=>'2nd party'],['key'=>'others_share','votes_key'=>'others','label'=>'Others']]],
 ['title'=>'Voter turnout','unit'=>'%','series'=>[['key'=>'turnout','label'=>'Turnout']]],
 ['title'=>$isStateHistory?'Mean winning margin':'Winning margin','unit'=>'votes','series'=>[['key'=>'margin','label'=>'Winning margin']]],
 ['title'=>'Registered electors and votes polled','unit'=>'people','series'=>[['key'=>'electors','label'=>'Registered electors'],['key'=>'polled','label'=>'Votes polled']]]
];
@endphp
<section class="panel history-charts" aria-label="Historical election charts"><div class="panel-heading"><div><p class="kicker">Across the years</p><h2>{{ $isStateHistory?($kind==='pc'?'Lok Sabha voting history':'Assembly voting history'):'How voting has changed' }}</h2></div><a class="place-action" href="{{ $isStateHistory?'#'.($chartTableAnchor??'turnout'):'#history' }}">View the tables ↓</a></div>
@if(!$reportMode)<p class="small">The year filters control the period shown. Each point shows an election-year value; curves only connect those points. Gaps indicate missing or conflicting figures. Constituency boundaries may differ between years.</p>@endif
@foreach($plots as $plot)
<section class="history-line" @if(!$reportMode) data-history-chart @endif><h3>@if($reportMode)<span class="report-section-number">{{ sprintf("%02d",$loop->iteration) }}</span> @endif{{ $plot['title'] }}</h3>
@if(!$reportMode && $loop->first)<p class="small">The three parties with the most combined recorded votes across available years, counting each year once. Each line follows the same party label. Shares use all recorded candidate votes plus NOTA; these three lines need not total 100%.</p>@endif
@if(!$reportMode && $loop->index===1)<p class="small">The top two parties are selected separately for each year. Lines track rank, not the same party. The table identifies the party in each year.</p>@endif
<p class="small chart-explanation">@switch($loop->index)
@case(0)Each colour follows one party across elections. The vertical axis shows its percentage of recorded votes; years run left to right.@break
@case(1)The first and second lines show the leading two parties in each year, so party names can change. Others combines the remaining votes. Percentages appear on the vertical axis.@break
@case(2)Turnout is the percentage of registered electors who voted. A higher point means a larger proportion voted that year.@break
@case(3)The gap in votes between the winner and runner-up. A lower point indicates a closer contest.@if($isStateHistory) State figures show the average margin across constituencies with available results.@endif @break
@case(4)One line shows registered electors; the other shows votes polled. The vertical axis shows counts, not percentages.@break
@endswitch</p>
@if($reportMode)
@include('history-static-plot')
@else
<script type="application/json" class="history-chart-data">{!! json_encode(['rows'=>$plotRows,'series'=>$plot['series'],'unit'=>$plot['unit']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<div class="history-controls"><label>From <select data-chart-from>@foreach($chartRows as $point)<option value="{{ $point['year'] }}">{{ $point['year'] }}</option>@endforeach</select></label><label>To <select data-chart-to>@foreach($chartRows as $point)<option value="{{ $point['year'] }}" @selected($loop->last)>{{ $point['year'] }}</option>@endforeach</select></label></div>
<div class="history-legend" aria-label="Chart series"></div><div class="history-plot"></div><p class="history-readout" role="status">Tap or focus a point to see the value.</p>
@endif
@if(!$reportMode)<details><summary>View chart values</summary>@else<h4>Chart values</h4>@endif<div class="table-scroll"><table><thead><tr><th>Year</th>@foreach($plot['series'] as $series)<th>{{ $series['label'] }} ({{ isset($series['votes_key'])?'votes and share':$plot['unit'] }})</th>@endforeach</tr></thead><tbody>@foreach($plotRows as $point)<tr><th>{{ $point['year'] }}</th>@foreach($plot['series'] as $series)<td>@if(isset($series['name_key']) && $point[$series['name_key']])<strong>{{ $point[$series['name_key']] }}</strong><br>@endif
@if(isset($series['votes_key']) && $point[$series['key']]!==null){{ number_format($point[$series['votes_key']]).' ('.number_format($point[$series['key']],2).'%)'.($point['review']?' †':'') }}@else{{ $point[$series['key']]===null?'—':number_format($point[$series['key']],$plot['unit']==='%'?2:0).($plot['unit']==='%'?'%':'').($point['review']?' †':'') }}@endif</td>@endforeach</tr>@endforeach</tbody></table></div>@if(!$reportMode)</details>@endif
</section>
@endforeach
@if(!$reportMode)<p class="small">Absolute counts, not percentages, for margin and electors/votes polled. Turnout and party shares use percentages. Party shares divide each group’s votes by all recorded candidate votes plus NOTA for that year. Top two parties are ranked independently by recorded votes in each year. Equal votes use alphabetical party order; this does not establish an election winner. Others includes remaining parties, independents and NOTA. Party labels remain as reported; alliances and renamed parties are not merged. † marks a figure with a source note. Conflicting editions in the same year are omitted from that chart value; their source records remain below.</p>@endif</section>
