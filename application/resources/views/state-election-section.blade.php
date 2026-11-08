<section class="state-election-section">
<section id="{{ $kind }}-results" class="panel state-year-results">

@if($election)

<h3>{{ $election['year'] }} {{ $sectionLabel }} results</h3><div class="state-year-metrics"><div><span>Votes polled</span><strong>{{ $election['polled']===null?'—':number_format($election['polled']) }}@if($election['turnout_review_count']??0) †@endif</strong></div><div><span>Turnout</span><strong>{{ $election['turnout']===null?'—':number_format($election['turnout'],2).'%' }}@if($election['turnout_review_count']??0) †@endif</strong></div><div><span>Mean winning margin</span><strong>{{ $election['margin']===null?'—':number_format($election['margin']) }}@if($election['margin_review_count']??0) †@endif</strong></div><div><span>Available constituencies</span><strong>{{ number_format($election['tables']) }}</strong></div></div>
<p class="small">{{ $election['label'] }} · turnout covers {{ $election['turnout_count'] }} of {{ $election['tables'] }} constituencies; mean margin covers {{ $election['margin_count'] }}; party vote shares cover {{ $election['party_count'] }}. † marks figures with source notes.</p>
@if(($election['review_count']??0)||($election['party_review_count']??0)||($election['turnout_review_count']??0))<p class="coverage-note">This election includes records with data notes. <a href="{{ '#'.$kind.'-constituency-results' }}">View results and notes ↓</a></p>@endif
@php
    $wins=collect($election['constituency_results']??[])->filter(fn($seat)=>filled($seat['result']['winner']??null) && filled($seat['result']['party']??null))->countBy(fn($seat)=>$seat['result']['party'])->sortDesc();
@endphp
@if($wins->isNotEmpty())
<section class="landing-bars"><h3>Seats won by party</h3><p>Declared winners with a recorded party · {{ $wins->sum() }} constituencies</p>
@foreach($wins as $partyName=>$seats)<div><span>{{ $partyName }}</span><meter min="0" max="{{ $wins->max() }}" value="{{ $seats }}">{{ $seats }}</meter><strong>{{ $seats }}</strong></div>@endforeach
</section>@endif
<h3>Party vote shares · {{ $election['year'] }}</h3>
@if($election['party_count'] < $election['tables'])<p class="coverage-note">Party vote shares require reconciled votes for all candidates in each included constituency. A verified winner or turnout alone does not establish those totals. @if($election['party_count'] > 0)Shares below cover only {{ $election['party_count'] }} of {{ $election['tables'] }} constituencies.@endif <a href="#{{ $kind }}-coverage">See coverage by year</a>.</p>@endif<div class="table-scroll"><table data-sortable><thead><tr><th>Party at election</th><th>Votes</th><th>Share</th></tr></thead><tbody>@forelse($election['parties'] as $item)<tr><th>{{ $item['party'] }}</th><td>{{ number_format($item['votes']) }}</td><td>{{ number_format($item['share'],2) }}%@if($election['party_review_count']??0) †@endif</td></tr>@empty<tr><td colspan="3">No comparable party totals are available for this year.</td></tr>@endforelse</tbody></table></div>
<section id="{{ $kind }}-constituency-results" class="state-constituency-results"><h3>All constituency results · {{ $election['year'] }}</h3>
<div data-seat-list><label hidden data-seat-tools>Find a constituency<input type="search" data-seat-filter autocomplete="off"></label>
<div class="table-scroll"><table><thead><tr><th>Constituency</th><th>Winner</th><th>Party</th><th>Margin (votes)</th></tr></thead><tbody>
@forelse($election['constituency_results']??[] as $seat)
<tr><th><a href="{{ \App\Services\ConstituencyUrls::url(['kind'=>$kind,'state'=>$title,'name'=>$seat['name'],'edition'=>$edition,'code'=>$seat['code']]) }}">{{ $seat['name'] }}</a>@if($seat['has_warning']) †@endif</th>
<td>{{ $seat['result']['winner']??($seat['source_candidate']?'Candidate: '.$seat['source_candidate']['name'].' *':'See official report *') }}</td><td>{{ $seat['result']['party']??$seat['source_candidate']['party']??'—' }}</td><td>{{ ($seat['result']['uncontested']??false)?'Uncontested':(isset($seat['result']['margin'])?number_format($seat['result']['margin']):'—') }}</td></tr>
@empty<tr><td colspan="4">Constituency details are not available for this year.</td></tr>@endforelse
</tbody></table></div>
<div class="landing-pager" hidden data-seat-tools><button type="button" data-step="-1">Previous</button><span role="status"></span><button type="button" data-step="1">Next</button></div></div>
@foreach($election['constituency_results']??[] as $seat)@if($seat['has_warning'] && $seat['note'])<p class="small"><strong>† {{ $seat['name'] }}:</strong> {{ $seat['note'] }}</p>@endif @endforeach
@if(collect($election['constituency_results']??[])->contains(fn($seat)=>!$seat['result']))<p class="small">* Available candidate information is shown where the winner is not established. Check the official report.</p>@endif
</section>
<p class="state-result-links"><a href="{{ $election['source_url'] }}" target="_blank" rel="noopener">Official report ↗</a></p>
@else<p>No {{ $sectionLabel }} results are available for {{ $title }} in this collection yet.</p>@endif
</section>
@if($election)
@include('election-map',['mapKind'=>$kind,'mapStateName'=>$title,'mapEdition'=>$edition,'mapYear'=>$election['year']])
@else
@include('election-map',['mapKind'=>$kind,'mapStateName'=>$title,'mapMode'=>'focus','mapLabels'=>true])
@endif
<section id="{{ $kind }}-history"><h2>{{ $sectionLabel }} election history</h2>
@if($history)<p><a href="{{ route('states.show',['state'=>$state,'election'=>$kind,'format'=>'report']) }}">Printable election history →</a></p>@endif
@if($history)

@include('place-history-charts',['stateHistory'=>$history,'chartTableAnchor'=>$kind.'-coverage'])
<details class="panel" id="{{ $kind }}-coverage"><summary>Historical coverage and original editions</summary><div class="table-scroll"><table data-sortable><thead><tr><th>Report / year</th><th>Turnout</th><th>Turnout coverage</th><th>Margin coverage</th><th>Party coverage</th><th>Candidate rows</th></tr></thead><tbody>@foreach($history as $row)<tr><th><a href="{{ route($kind==='pc'?'elections.history':'elections.assembly',['edition'=>$row['id'],'state'=>$row['state']]) }}">{{ $row['label'] }}</a></th><td>{{ $row['turnout']===null?'—':number_format($row['turnout'],2).'%' }}@if($row['turnout_review_count']??0) †@endif</td><td>{{ $row['turnout_count'] }} / {{ $row['tables'] }}</td><td>{{ $row['margin_count'] }} / {{ $row['tables'] }}</td><td>{{ $row['party_count'] }} / {{ $row['tables'] }}</td><td>{{ number_format($row['candidate_rows']??0) }} candidate rows · <a href="{{ route($kind==='pc'?'elections.history':'elections.assembly',['edition'=>$row['id'],'state'=>$row['state']]) }}">View candidate tables →</a></td></tr>@endforeach</tbody></table></div></details>
@endif
</section>
@include('state-constituency-directory')
</section>
