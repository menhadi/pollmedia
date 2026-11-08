<section class="state-election-section">
@if($election)
<div class="state-party-comparison">
@php
    $wins=collect($election['constituency_results']??[])->filter(fn($seat)=>filled($seat['result']['winner']??null) && filled($seat['result']['party']??null))->countBy(fn($seat)=>$seat['result']['party'])->sortDesc();
    $seatRows=collect($election['constituency_results']??[]);
    $hasReportedCandidates=$seatRows->contains(fn($seat)=>!empty($seat['source_candidate']));
    $hasStatus=$seatRows->contains(fn($seat)=>empty($seat['result']['winner']) || !empty($seat['result']['uncontested']));
    $shares=collect($election['parties'])->whereNotNull('share')->sortByDesc('share')->take(6);
@endphp
@if($wins->isNotEmpty())
<section class="panel landing-bars"><h2>Seats won by party</h2><p>Declared winners with a recorded party · {{ $wins->sum() }} constituencies</p>
@foreach($wins as $partyName=>$seats)<div><span>{{ $partyName }}</span><meter data-winning-party="{{ $partyName }}" min="0" max="{{ $wins->max() }}" value="{{ $seats }}">{{ $seats }}</meter><strong>{{ $seats }}</strong></div>@endforeach
</section>@endif

<section class="panel landing-bars state-vote-bars" aria-labelledby="vote-bars-title">
<h2 id="vote-bars-title">{{ app()->getLocale()==='hi'?'दलों का मत प्रतिशत':'Vote share by party' }}</h2>
@if($shares->isNotEmpty())
<p>{{ $election['year'] }} · {{ $election['party_count'] }} / {{ $election['tables'] }} {{ app()->getLocale()==='hi'?'निर्वाचन क्षेत्रों में तुलनीय मत':'constituencies with comparable candidate votes' }}</p>
@foreach($shares as $item)
<div><span>{{ $item['party'] }}</span><meter data-winning-party="{{ $item['party'] }}" min="0" max="100" value="{{ $item['share'] }}" aria-label="{{ $item['party'] }} {{ number_format($item['share'],2) }}%"></meter><strong>{{ number_format($item['share'],2) }}%@if($election['party_review_count']??0) †@endif</strong></div>
@endforeach
<a href="#{{ $kind }}-party-votes">{{ app()->getLocale()==='hi'?'सभी दर्ज मत देखें':'All recorded votes and shares' }} →</a>
@else<p>No comparable party vote totals are available for this edition.</p>@endif
</section>
</div>
@include('state-winning-margins')
@include('state-results-map')

<section id="{{ $kind }}-constituency-results" class="panel state-constituency-results"><h2>All constituency results · {{ $election['year'] }}</h2>
<div data-seat-list ><label hidden data-seat-tools>Find a constituency<input type="search" data-seat-filter autocomplete="off"></label>

<div class="table-scroll" role="region" aria-label="Constituency results" tabindex="0"><table data-total-rows="{{ $seatRows->count() }}"><thead><tr>
<th scope="col">Constituency</th><th scope="col">Winner</th><th scope="col">Winning party</th><th scope="col">Margin (votes)</th>
@if($hasReportedCandidates)<th scope="col">Reported candidate</th><th scope="col">Reported party</th>@endif
@if($hasStatus)<th scope="col">Result status</th>@endif
</tr></thead><tbody>
@forelse(\App\Services\StateTablePreview::initial($seatRows) as $seat)
<tr><th scope="row"><a href="{{ \App\Services\ConstituencyUrls::url(['kind'=>$kind,'state'=>$title,'name'=>$seat['name'],'edition'=>$edition,'code'=>$seat['code']]) }}">{{ $seat['name'] }}</a>@if($seat['has_warning']) †@endif</th>
<td>{{ $seat['result']['winner']??'—' }}</td><td>{{ $seat['result']['party']??'—' }}</td>
<td data-sort-value="{{ $seat['result']['margin']??'' }}">{{ isset($seat['result']['margin'])?number_format($seat['result']['margin']):'—' }}</td>
@if($hasReportedCandidates)<td>{{ $seat['source_candidate']['name']??'—' }}</td><td>{{ $seat['source_candidate']['party']??'—' }}</td>@endif
@if($hasStatus)<td>{{ !empty($seat['result']['uncontested'])?'Uncontested':(empty($seat['result']['winner'])?'Winner unconfirmed':'—') }}</td>@endif
</tr>
@empty<tr><td colspan="{{ 4+($hasReportedCandidates?2:0)+($hasStatus?1:0) }}">Constituency details are not available for this year.</td></tr>@endforelse
</tbody></table></div>
</div>

@if($hasReportedCandidates)<p class="small">The reported candidate and party columns retain source information where a winner is unconfirmed.</p>@endif
</section>
<section id="{{ $kind }}-party-votes" class="panel state-share-table"><h2>Party vote shares · {{ $election['year'] }}</h2>
@if($election['party_count'] < $election['tables'])<p class="coverage-note">Party vote shares require reconciled votes for all candidates in each included constituency. A verified winner or turnout alone does not establish those totals. @if($election['party_count'] > 0)Shares below cover only {{ $election['party_count'] }} of {{ $election['tables'] }} constituencies.@endif <a href="#{{ $kind }}-coverage">See coverage by year</a>.</p>@endif<div data-party-list ><label hidden data-seat-tools>{{ app()->getLocale()==='hi'?'दल खोजें':'Find a party' }}<input type="search" data-party-filter autocomplete="off"></label>
<div class="table-scroll"><table data-sortable data-total-rows="{{ count($election['parties']) }}"><thead><tr><th>Party at election</th><th>Votes</th><th scope="col">Vote share (%)</th></tr></thead><tbody>@forelse(\App\Services\StateTablePreview::initial(collect($election['parties'])) as $item)<tr><th>{{ $item['party'] }}</th><td>{{ number_format($item['votes']) }}</td><td data-sort-value="{{ $item['share'] }}">{{ number_format($item['share'],2) }}%@if($election['party_review_count']??0) †@endif</td></tr>@empty<tr><td colspan="3">No comparable party totals are available for this year.</td></tr>@endforelse</tbody></table></div>

</div>
</section>
<p class="state-result-links"><a href="{{ $election['source_url'] }}" target="_blank" rel="noopener">Official report ↗</a></p>
@else
@include('state-results-map')
@endif

<section id="{{ $kind }}-history"><h2>{{ $sectionLabel }} election history</h2>
@if($history)<p><a href="{{ route('states.show',['state'=>$state,'election'=>$kind,'format'=>'report']) }}">Printable election history →</a></p>@endif
@if($history)

@include('state-margin-history')
@include('place-history-charts',['stateHistory'=>$history,'showChartTables'=>true,'chartTableAnchor'=>$kind.'-coverage'])

@endif
</section>
@include('state-constituency-directory')
</section>
