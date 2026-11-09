@php
$reportMode=$reportMode??false;
$colors=['var(--chart-series-1,#155748)','var(--chart-series-2,#573451)','var(--chart-series-3,#825026)','var(--chart-series-4,#314766)'];
$isStateHistory=isset($stateHistory);
$chartInputRows=$isStateHistory?collect($stateHistory)->map(fn($summary)=>['entry'=>(object)['year'=>$summary['year']],'summary'=>$summary]):($chartSourceRows??$rows);
$chartRows=$chartInputRows->sortBy('entry.year')->values()->map(function($row){
 $summary=$row['summary']??($row['record']?app(\App\Services\HistoricalElectionAnalytics::class)->summarize([$row['record']]):[]);
 return ['entry_id'=>$row['entry']->id??null,'year'=>$row['entry']->year,'turnout'=>$summary['turnout']??null,'margin'=>$summary['margin']??null,'polled'=>$summary['polled']??null,'electors'=>$summary['electors']??null,'parties'=>$summary['parties']??[],'review'=>max($summary['turnout_review_count']??0,$summary['margin_review_count']??0,$summary['party_review_count']??0)];
})->groupBy('year')->map(function($group){
 $point=['entry_id'=>$group->pluck('entry_id')->filter()->unique()->count()===1?$group->first()['entry_id']:null,'year'=>$group->first()['year'],'review'=>$group->max('review')];
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
 foreach([0,1] as $i){$point['party'.$i]=$ranked[$i]['votes']??($point['parties']?0:null);$point['party'.$i.'_name']=$ranked[$i]['party']??($point['parties']?'No qualifying party':null);}
 $topParties=$ranked->pluck('party')->all();
 $point['others']=$point['parties']?collect($point['parties'])->reject(fn($party)=>in_array($party['party'],$topParties))->sum('votes'):null;
 $total=collect($point['parties'])->sum('votes');
 foreach($fixedParties as $i=>$party){$point['fixed'.$i]=$total>0?collect($point['parties'])->where('party',$party)->sum('votes'):null;$point['fixed'.$i.'_share']=$total>0?100*$point['fixed'.$i]/$total:null;}
 foreach(['party0','party1','others'] as $key){$point[$key.'_share']=$total>0&&$point[$key]!==null?100*$point[$key]/$total:null;}
 unset($point['parties']);
 return $point;
});
$missingStatePartyYears=$isStateHistory?$plotRows->whereNull('party0_share')->pluck('year')->implode(', '):'';
$plots=[
 ['title'=>'Top three parties across the years','unit'=>'%','series'=>array_map(fn($party,$i)=>['key'=>'fixed'.$i.'_share','votes_key'=>'fixed'.$i,'label'=>$party],$fixedParties,array_keys($fixedParties))],
 ['title'=>'Party vote shares by year','unit'=>'%','series'=>[['key'=>'party0_share','votes_key'=>'party0','name_key'=>'party0_name','label'=>'1st party'],['key'=>'party1_share','votes_key'=>'party1','name_key'=>'party1_name','label'=>'2nd party'],['key'=>'others_share','votes_key'=>'others','label'=>'Others']]],
 ['title'=>'Registered electors, votes polled and turnout','unit'=>'people','rightAutoScale'=>true,'series'=>[['key'=>'electors','label'=>'Registered electors'],['key'=>'polled','label'=>'Votes polled'],['key'=>'turnout','label'=>'Turnout (%)','axis'=>'right','dashed'=>false]]],
 ['title'=>$isStateHistory?'Mean winning margin':'Winning margin','unit'=>'votes','series'=>[['key'=>'margin','label'=>'Winning margin']]]
];
@endphp
<section class="panel history-charts" aria-label="Historical election charts"><div class="panel-heading"><div><p class="kicker">Across the years</p><h2>{{ $isStateHistory?($kind==='pc'?'Lok Sabha voting history':'Assembly voting history'):'How voting has changed' }}</h2></div></div>
@if(!$isStateHistory)
@foreach($chartInputRows->map(fn($row)=>isset($row['record'])?app(\App\Services\HistoricalElectionAnalytics::class)->sourceGapNote($row['record']):null)->filter()->unique() as $sourceGapNote)
<p class="small">{{ $sourceGapNote }}</p>
@endforeach
@endif
@php($uncontestedYears=$isStateHistory?collect():$chartInputRows->filter(fn($row)=>!empty($row['result']['uncontested']))->map(fn($row)=>$row['entry']->year)->unique()->sort()->values())
@if($uncontestedYears->isNotEmpty())
<p class="small">Uncontested elections ({{ $uncontestedYears->implode(', ') }}): the candidate was returned without a contested poll. Vote shares, votes polled, turnout and winning margin are not available for these years; chart gaps are not zero results or missing polling data.</p>
@endif
@if(!$isStateHistory && $chartInputRows->contains(fn($row)=>(($row['record']['number_of_seats']??1)>1)))
<p class="small">Historical multi-seat elections: party shares use recorded candidate votes across all seats. Turnout and a single winning margin are unavailable for comparison and remain blank; these are not zero results.</p>
@endif
@if(isset($reportAlternatives) && $reportAlternatives->isNotEmpty())
<div class="notice"><p><strong>{{ $chartReport['entry']->year }} report comparison:</strong> Charts show {{ $reportAlternativeLabels[$chartReport['entry']->edition_id] ?? $chartReport['entry']->edition_label }}. {{ $reportComparisonNote ?? 'The two official reports use CPIM and CPM for the same candidate; original party labels are retained.' }} This is a display selection, not a decision that one report is more authoritative. History rows below are report alternatives for the same election year.</p>
@foreach($reportAlternatives as $alternative)
<p><a href="{{ \App\Services\ConstituencyUrls::url(['kind'=>$kind,'state'=>$state,'name'=>$name,'edition'=>$alternative['entry']->edition_id,'code'=>$alternative['entry']->record_code,'format'=>$reportMode?'report':null]) }}">Use {{ $reportAlternativeLabels[$alternative['entry']->edition_id] ?? $alternative['entry']->edition_label }} for {{ $alternative['entry']->year }} charts and details</a> · <a href="{{ $alternative['source'] }}">Official source: {{ $reportAlternativeLabels[$alternative['entry']->edition_id] ?? $alternative['entry']->edition_label }}</a></p>
@endforeach</div>
@endif

@foreach($plots as $plot)
@php($plot['autoScale']=true)
<section class="history-line" @if(!empty($showChartTables)) data-selected-year="{{ $chosen['entry']->year??($isStateHistory?($election['year']??''):'') }}" @endif @if(!$reportMode && count($plot['series']) > 0) data-history-chart @endif><h3>@if($reportMode)<span class="report-section-number">{{ sprintf("%02d",$loop->iteration) }}</span> @endif{{ $plot['title'] }}</h3>
@if($loop->index===0)
<p class="small">These three party labels are selected by total recorded votes across all available years and stay fixed when you change the year range. Independents and NOTA are excluded from selection.</p>
@elseif($loop->index===1)
<p class="small">The two leading parties are ranked separately each year. Independents and any recorded NOTA votes are included in Others, so an independent winner appears there. These lines rank parties, not candidates. A zero labelled “No qualifying party” means that rank is absent from the available party results; missing results remain gaps.</p>
@endif
@if($loop->index<2 && $missingStatePartyYears!=='')
<p class="small coverage-note">{{ app()->getLocale()==='hi'?'इन वर्षों के लिए तुलनीय दलवार मत प्रतिशत उपलब्ध नहीं है':'Comparable party vote shares are unavailable for' }} {{ $missingStatePartyYears }}.</p>
@endif
@if(count($plot['series']) === 0)
<p class="small history-empty-state" role="status">No eligible party series is available for this chart. Independents and NOTA are excluded from selection; unavailable results remain blank.</p>
@if(!$reportMode)
<script type="application/json" class="history-chart-data">{!! json_encode(['rows'=>$plotRows,'series'=>[],'unit'=>$plot['unit'],'autoScale'=>true,'state'=>'empty','emptyReason'=>'no_eligible_party_series'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
@endif
</section>
@continue
@endif
@if($reportMode)
@include('history-static-plot')
@else
<script type="application/json" class="history-chart-data">{!! json_encode(['rows'=>$plotRows,'series'=>$plot['series'],'unit'=>$plot['unit'],'autoScale'=>$plot['autoScale'],'rightAutoScale'=>$plot['rightAutoScale']??false],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
@if($plot['rightAutoScale']??false)<p class="small">Counts use the left axis; turnout percentage uses the right axis.</p>@endif
<div class="history-controls"><label>From <select data-chart-from>@foreach($chartRows as $point)<option value="{{ $point['year'] }}">{{ $point['year'] }}</option>@endforeach</select></label><label>To <select data-chart-to>@foreach($chartRows as $point)<option value="{{ $point['year'] }}" @selected($loop->last)>{{ $point['year'] }}</option>@endforeach</select></label></div>
<div class="history-legend" aria-label="Chart series"></div><div class="history-plot"></div><p class="history-readout" role="status">Hover or focus a year to see the value.</p>
@endif
@if(!empty($showChartTables) && isset($plot['series'][0]['votes_key']))
@include('constituency-party-history-table')
@else
@if(!$reportMode && empty($showChartTables))<details class="chart-values"><summary><span class="chart-values-closed">Show data table</span><span class="chart-values-open">Hide data table</span><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="m4 6 4 4 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></summary>@else<h4>Chart values</h4>@endif<div class="table-scroll"><table><thead><tr><th>Year</th>@foreach($plot['series'] as $series)<th>@if($reportMode)<i class="report-series-swatch" style="background:{{ $colors[$loop->index % count($colors)] }}"></i>@endif{{ preg_replace('/\s*\(%\)$/', '', $series['label']) }} ({{ isset($series['votes_key'])?'votes and share':(($series['axis']??null)==='right'?'%':$plot['unit']) }})</th>@endforeach</tr></thead><tbody>@foreach($plotRows as $point)<tr @class(['atlas-selected-year'=>!empty($showChartTables) && (int)$point['year']===(int)($chosen['entry']->year??($isStateHistory?($election['year']??0):0))])><th>{{ $point['year'] }}</th>@foreach($plot['series'] as $series)@php($sourceField=['polled'=>'votes_polled','electors'=>'electors'][$series['key']]??null)<td @if($sourceField && !empty($point['entry_id'])) data-correction-kind="election" data-correction-record="{{ $point['entry_id'] }}" data-correction-field="{{ $sourceField }}" @endif>@if(isset($series['name_key']) && $point[$series['name_key']])<strong>{{ $point[$series['name_key']] }}</strong><br>@endif
@if(isset($series['votes_key']) && $point[$series['key']]!==null){{ number_format($point[$series['votes_key']]).' ('.number_format($point[$series['key']],2).'%)'.($point['review']?' †':'') }}@else{{ $point[$series['key']]===null?'—':number_format($point[$series['key']],($plot['unit']==='%'||($series['axis']??null)==='right')?2:0).(($plot['unit']==='%'||($series['axis']??null)==='right')?'%':'').($point['review']?' †':'') }}@endif</td>@endforeach</tr>@endforeach</tbody></table></div>@if(!$reportMode && empty($showChartTables))</details>@endif
@endif
</section>
@endforeach
</section>
