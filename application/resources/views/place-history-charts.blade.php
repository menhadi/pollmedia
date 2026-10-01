@php
$chartRows=$rows->sortBy('entry.year')->values()->map(function($row){
 $summary=$row['record']?app(\App\Services\HistoricalElectionAnalytics::class)->summarize([$row['record']]):[];
 return ['year'=>$row['entry']->year,'turnout'=>$summary['turnout']??null,'margin'=>$summary['margin']??null,'polled'=>$summary['polled']??null,'electors'=>$summary['electors']??null,'parties'=>$summary['parties']??[],'review'=>max($summary['turnout_review_count']??0,$summary['margin_review_count']??0,$summary['party_review_count']??0)];
})->groupBy('year')->map(function($group){
 $point=['year'=>$group->first()['year'],'review'=>$group->max('review')];
 foreach(['turnout','margin','polled','electors','parties'] as $key){
  $values=$group->pluck($key)->unique(fn($value)=>json_encode($value));
  $point[$key]=$values->count()===1?$values->first():($key==='parties'?[]:null);
 }
 return $point;
})->values();
$totals=[];
foreach($chartRows as $point){foreach($point['parties'] as $party){if(!in_array(strtoupper($party['party']),['NOTA','IND','INDEPENDENT'])){$totals[$party['party']]=($totals[$party['party']]??0)+$party['votes'];}}}
arsort($totals); $topParties=array_slice(array_keys($totals),0,3);
$plotRows=$chartRows->map(function($point)use($topParties){
 foreach($topParties as $i=>$party){$point['party'.$i]=$point['parties']?collect($point['parties'])->where('party',$party)->sum('votes'):null;}
 $point['others']=$point['parties']?collect($point['parties'])->reject(fn($party)=>in_array($party['party'],$topParties))->sum('votes'):null;
 $total=collect($point['parties'])->sum('votes');
 foreach(array_merge(array_map(fn($i)=>'party'.$i,array_keys($topParties)),['others']) as $key){$point[$key.'_share']=$total>0&&$point[$key]!==null?100*$point[$key]/$total:null;}
 return $point;
});
$plots=[['title'=>'Voter turnout','unit'=>'%','series'=>[['key'=>'turnout','label'=>'Turnout']]],['title'=>'Winning margin','unit'=>'votes','series'=>[['key'=>'margin','label'=>'Winning margin']]],['title'=>'Registered electors and votes polled','unit'=>'people','series'=>[['key'=>'electors','label'=>'Registered electors'],['key'=>'polled','label'=>'Votes polled']]],['title'=>'Party vote shares across the years','unit'=>'%','series'=>array_merge(array_map(fn($party,$i)=>['key'=>'party'.$i.'_share','votes_key'=>'party'.$i,'label'=>$party],$topParties,array_keys($topParties)),[['key'=>'others_share','votes_key'=>'others','label'=>'Others']])]];
@endphp
<section class="panel history-charts" aria-label="Historical election charts"><div class="panel-heading"><div><p class="kicker">Across the years</p><h2>How voting has changed</h2></div><a class="place-action" href="#history">View the tables ↓</a></div>
<p class="small">Choose a period and tap a point for its exact value. Toggle a legend to compare lines. Missing or conflicting figures leave a gap, not a zero. Historical names do not establish unchanged boundaries.</p>
@foreach($plots as $plot)
<section class="history-line" data-history-chart><h3>{{ $plot['title'] }}</h3>
<script type="application/json" class="history-chart-data">{!! json_encode(['rows'=>$plotRows,'series'=>$plot['series'],'unit'=>$plot['unit']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<div class="history-controls"><label>From <select data-chart-from>@foreach($chartRows as $point)<option value="{{ $point['year'] }}">{{ $point['year'] }}</option>@endforeach</select></label><label>To <select data-chart-to>@foreach($chartRows as $point)<option value="{{ $point['year'] }}" @selected($loop->last)>{{ $point['year'] }}</option>@endforeach</select></label></div>
<div class="history-legend" aria-label="Chart series"></div><div class="history-plot"></div><p class="history-readout" role="status">Tap or focus a point to see the value.</p>
<details><summary>View chart values</summary><div class="table-scroll"><table><thead><tr><th>Year</th>@foreach($plot['series'] as $series)<th>{{ $series['label'] }} ({{ isset($series['votes_key'])?'votes and share':$plot['unit'] }})</th>@endforeach</tr></thead><tbody>@foreach($plotRows as $point)<tr><th>{{ $point['year'] }}</th>@foreach($plot['series'] as $series)<td>@if(isset($series['votes_key']) && $point[$series['key']]!==null){{ number_format($point[$series['votes_key']]).' ('.number_format($point[$series['key']],2).'%)'.($point['review']?' †':'') }}@else{{ $point[$series['key']]===null?'—':number_format($point[$series['key']],$plot['unit']==='%'?2:0).($plot['unit']==='%'?'%':'').($point['review']?' †':'') }}@endif</td>@endforeach</tr>@endforeach</tbody></table></div></details>
</section>
@endforeach
<p class="small">Absolute counts, not percentages, for margin and electors/votes polled. Turnout and party shares use percentages. Party shares divide each group’s votes by all recorded candidate votes plus NOTA for that year. Top three parties are ranked by combined votes across the available years, counting each year once. Others includes remaining parties, independents and NOTA. Party labels remain as reported; alliances and renamed parties are not merged. † marks a figure with a source note. Conflicting editions in the same year are omitted from that chart value; their source records remain below.</p></section>
