<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
@php
    $filters = array_filter(['edition' => $edition, 'state' => $state, 'code' => $selected['code'] ?? null], fn ($value) => $value !== null);
    $canonical = route($archiveRoute, $filters);
    $seoTitle = ($selected ? $selected['name'].' · ' : ($state ? $state.' · ' : '')).($data ? $data['year'].' ' : '').$archiveTitle.' election results · Pollmedia';
    $seoDescription = 'Browse historical election candidate results, parties and votes by official report edition, state and constituency, with source links and data notes.';
    $breadcrumbs = ['India' => route('home'), $archiveTitle.' results' => route($archiveRoute)];
@endphp
@include('seo-metadata')
<link rel="stylesheet" href="/css/villages.css"><link rel="stylesheet" href="/css/election-dashboard.css?v={{ substr(hash_file('sha256', public_path('css/election-dashboard.css')), 0, 12) }}"><script src="/js/instant-filters.js?v={{ substr(hash_file('sha256',public_path('js/instant-filters.js')),0,12) }}" defer></script>@include('site-theme')<link rel="stylesheet" href="{{ asset('css/archive-page.css') }}?v={{ substr(hash_file('sha256',public_path('css/archive-page.css')),0,12) }}"></head>
<body class="election-ui archive-page public-place-page">@include('public-header')<div class="dashboard-shell archive-layout"><main class="dashboard-main">
<div class="kicker">Politics & elections / Historical results</div><h1>{{ $selected ? ($selected['constituency_name'] ?? $selected['name']) : $archiveTitle.' election results' }}</h1>@if($selected)<p>{{ $state }} &middot; {{ $data['year'] }} &middot; {{ $kind==='pc'?'Lok Sabha':'Assembly' }}</p><p><a href="{{ route('constituency.overview',['kind'=>$kind,'state'=>$state,'name'=>$selected['constituency_name']??$selected['name']]) }}">All election years for this place &rarr;</a></p>@endif
@if($data)
<section class="card archive-filters" aria-label="Election filters">
<form class="search" method="get" action="{{ route($archiveRoute) }}"><div><label for="edition">Election year / official edition</label><select id="edition" name="edition">@foreach($editions as $item)<option value="{{ $item['id'] }}" @selected($edition===$item['id'])>{{ $item['label'] }}</option>@endforeach</select></div><button>Choose edition</button></form>
<form class="search" method="get" action="{{ route($archiveRoute) }}"><input type="hidden" name="edition" value="{{ $edition }}"><div><label for="state">State / union territory as recorded</label><select id="state" name="state" required><option value="">Choose a state</option>@foreach($states as $option)<option value="{{ $option }}" @selected($state===$option)>{{ $option }}</option>@endforeach</select></div><button>Choose state</button></form>
@if($state)<form class="search" method="get" action="{{ route($archiveRoute) }}"><input type="hidden" name="edition" value="{{ $edition }}"><input type="hidden" name="state" value="{{ $state }}"><div><label for="code">Constituency in {{ $data['year'] }}</label><select id="code" name="code" required><option value="">Choose a constituency</option>@foreach($constituencies as $record)<option value="{{ $record['code'] }}" @selected(($selected['code'] ?? null)===$record['code'])>{{ $record['official_pc_code'] ?? $record['official_ac_code'] ?? $record['code'] }} / {{ $record['constituency_name'] ?? $record['name'] }}</option>@endforeach</select></div><button>Show results</button></form>@endif
<p class="small">{{ count($data['records']) }} available election results in this edition. State codes are shown where the state name is unavailable.</p>
</section>
@include('election-map',['mapKind'=>$kind,'mapStateName'=>\App\Services\ElectionPlaceIdentity::state($state??''),'mapEdition'=>$edition,'mapYear'=>$data['year']??null])

@endif
@if($data && !$selected)<section class="panel archive-snapshot"><div class="panel-heading"><div><p class="kicker">{{ $data['year'] }} / selected election report</p><h2>{{ $state ?: $archiveTitle }} at a glance</h2></div><a href="#{{ $state ? 'state-results' : 'coverage' }}">Explore the full tables ↓</a></div><div class="data-overview"><div class="data-stat"><strong>{{ number_format($state ? $constituencies->count() : count($data['records'])) }}</strong><span>Available election results</span></div><div class="data-stat"><strong>{{ number_format($state ? $constituencies->sum(fn($r)=>count($r['candidates']??[])) : $coverage->sum('rows')) }}</strong><span>Candidate rows, including NOTA where reported</span></div><div class="data-stat"><strong>{{ $state ? $partySummary['counted'] : $states->count() }}</strong><span>{{ $state ? 'Reported single-seat winners' : 'State names / codes in this edition' }}</span></div></div><p class="small">Available records only. These counts are not an official seat tally; incomplete and disputed records keep their notes.</p>
@if(!$state)<div class="table-scroll" role="region" aria-label="Edition data preview" tabindex="0"><table data-sortable><caption>Eight state groups with the most available election results</caption><thead><tr><th scope="col">State as recorded</th><th scope="col" data-sort-type="number">Tables</th><th scope="col" data-sort-type="number">Candidate rows</th></tr></thead><tbody>@foreach($coverage->sortByDesc('tables')->take(8) as $item)<tr><th scope="row">@if($item['state'])<a href="{{ route($archiveRoute,['edition'=>$edition,'state'=>$item['state']]) }}">{{ $item['state'] }}</a>@else State not identified @endif</th><td>{{ number_format($item['tables']) }}</td><td>{{ number_format($item['rows']) }}</td></tr>@endforeach</tbody></table></div>@endif</section>@endif
@if($selected)@include('constituency-election-analysis')@endif
@if(!$selected)<p class="lead">Choose a year, state and constituency to see results.</p>@endif
<p><a href="{{ route('elections.constituencies') }}">Search historical Lok Sabha and AC constituencies across editions →</a></p>
<p><a href="{{ route('elections.history') }}">Lok Sabha results</a> · <a href="{{ route('elections.assembly') }}">India Assembly results</a></p>
@if($kind === 'ac')<p class="small">Assembly coverage includes available state election reports; use the edition selector for each state and year. Historical state and constituency boundaries belong to each edition.</p>@endif
<p><a href="{{ route('elections.assembly-sources') }}">Assembly source reports: all listed states and historical years</a></p>
@if(($data['unavailable_original_count'] ?? 0) > 0)<p class="notice">{{ $data['unavailable_original_count'] }} preserved original {{ $data['unavailable_original_count'] === 1 ? 'file is' : 'files are' }} not yet accessible from this site. The available results remain available with their data notes; use the official source links below to check the report.</p>@endif
@if(!$data)<section class="card"><p>Election results are not available here yet.</p></section>@else

@if($state && !$selected)
<section class="card" id="party-summary"><div class="kicker">{{ $data['year'] }} / {{ $state }}</div><h2>Party-wise wins in available results</h2>
<div class="stats"><div class="stat"><span>Reported wins</span><strong>{{ $partySummary['counted'] }}</strong></div><div class="stat"><span>Results with notes</span><strong>{{ $partySummary['under_review'] }}</strong></div><div class="stat"><span>Other results</span><strong>{{ $partySummary['other'] }}</strong></div></div>
<p class="small">This summary uses reported single-seat winners in the selected state and edition, including results marked †. It is not a complete official seat tally. Party names are shown as recorded at the election.</p>
@if($partySummary['counted'])<div class="table"><table><caption>Reported wins by party, on a shared scale of {{ $partySummary['counted'] }} results</caption><thead><tr><th scope="col">Party at election</th><th scope="col">Reported wins</th><th scope="col">Chart</th></tr></thead><tbody>
@foreach($partySummary['parties'] as $party => $wins)<tr><th scope="row">{{ $party }}</th><td>{{ $wins }}</td><td><meter min="0" max="{{ $partySummary['counted'] }}" value="{{ $wins }}" aria-label="{{ $party }}: {{ $wins }} of {{ $partySummary['counted'] }} counted wins" style="width:100%;min-width:8rem">{{ $wins }}</meter></td></tr>@endforeach
</tbody></table></div>@else<p>No reported single-seat winners are available to chart in this selection.</p>@endif
<p><a href="#state-results">View constituency results and † data notes</a> · <a href="#sources">Official sources</a></p></section>
<section class="card" id="state-results"><div class="kicker">{{ $data['year'] }} / {{ $state }}</div><h2>Constituency results in {{ $state }}</h2>
<p><a href="{{ route($archiveRoute, $filters + ['format' => 'csv']) }}">Download this state’s candidate results (CSV)</a></p><p class="small">Exports the available results in this election, with official references and current data notes. Blank cells mean not reported. Constituency totals repeat on candidate rows; do not sum them across rows.</p>
<p>{{ $stateResults->count() }} available election results. † marks reported figures with a data note. Open a candidate table for the full figures and official references.</p>
<div class="table"><table><caption>Results from the selected official edition</caption><thead><tr><th scope="col">Constituency</th><th scope="col">{{ \App\Services\PublicLanguage::text('Winner') }}</th><th scope="col">Party at election</th><th scope="col">Margin (votes)</th><th scope="col">Details</th></tr></thead><tbody>
@foreach($stateResults as $record)<tr><th scope="row">{{ $record['official_pc_code'] ?? $record['official_ac_code'] ?? $record['code'] }} / {{ $record['constituency_name'] ?? $record['name'] }} @if($record['has_warning'])<a href="#state-note-{{ $record['code'] }}" aria-label="Data note for {{ $record['constituency_name'] ?? $record['name'] }}">†</a>@endif</th>
<td>@if($record['display_result']){{ $record['display_result']['winner'] }}@if($record['has_warning']) †@endif @elseif($record['source_candidate'])Reported candidate*: {{ $record['source_candidate']['name'] }}@else See source table*@endif</td><td>{{ $record['winner_party'] ?? $record['source_candidate']['party'] ?? '—' }}</td><td>{{ ($record['display_result']['uncontested'] ?? false) ? 'Uncontested' : (isset($record['display_result']['margin']) ? number_format($record['display_result']['margin']).($record['has_warning'] ? ' †' : '') : '—') }}</td>
<td><a href="{{ route($archiveRoute, ['edition'=>$edition,'state'=>$state,'code'=>$record['code']]) }}">Candidate table</a>@if(!$record['display_result']) · <a href="{{ $data['source_url'] }}">Official source ↗</a>@endif · <a href="{{ route('feedback.create',['path'=>route($archiveRoute,['edition'=>$edition,'state'=>$state,'code'=>$record['code']],false),'category'=>'data']) }}">Report issue</a></td></tr>@endforeach
</tbody></table></div>
@if($stateResults->contains(fn (array $record): bool => ! $record['display_result']))<p class="small">* A candidate or source table is recorded, but a winner has not been confirmed here. The linked official report contains the original entry.</p>@endif
@foreach($stateResults->where('has_warning', true) as $record)<p class="small" id="state-note-{{ $record['code'] }}"><strong>† {{ $record['constituency_name'] ?? $record['name'] }}:</strong> {{ $record['error'] ?? 'This record requires review.' }} See the candidate table for available figures.</p>@endforeach
<p><a href="{{ route($archiveRoute, ['edition' => $edition]) }}">View all states in this edition</a> · <a href="#sources">Official sources</a></p></section>
@endif
@if(!$selected && !$state)
<section class="card" id="coverage"><div class="kicker">Available data / {{ $data['year'] }}</div><h2>State coverage in this edition</h2>
<p>Browse the available results by the state names or codes used in this report. Counts describe the collected data, not the total seats or complete election coverage.</p>
<div class="table"><table><caption>Available election results and candidate rows, including NOTA where reported</caption><thead><tr><th scope="col">State / union territory</th><th scope="col">Constituency tables</th><th scope="col">Candidate records</th><th scope="col">{{ \App\Services\PublicLanguage::text('Explore') }}</th></tr></thead><tbody>
@forelse($coverage as $item)<tr><th scope="row">{{ $item['state'] ?: 'State not identified' }}</th><td>{{ number_format($item['tables']) }}</td><td>{{ number_format($item['rows']) }}</td><td>@if($item['state'] !== '')<a href="{{ route($archiveRoute, ['edition' => $edition, 'state' => $item['state']]) }}">Browse {{ $item['state'] }}</a>@else State mapping pending @endif</td></tr>
@empty<tr><td colspan="4">No constituency tables have been available for this election.</td></tr>@endforelse
</tbody></table></div><p class="small">Only this selected edition is counted. Missing states are not treated as zero results. Individual result pages show any unresolved data notes.</p></section>
@endif
@if($selected)

<section class="card" id="results"><div class="kicker">{{ $data['year'] }} / {{ $state }}</div><h2>{{ $selected['name'] }} @if($selected['has_warning'])<a href="#data-note" aria-label="Data note">†</a>@endif</h2>
<p>Official constituency code: {{ $selected['official_pc_code'] ?? $selected['official_ac_code'] ?? ($kind === 'ac' ? $selected['code'] : 'Not reported') }} · Seats: {{ $selected['number_of_seats'] ?? 1 }}</p>
<p><a href="{{ route($archiveRoute, $filters + ['format' => 'csv']) }}">Download candidate results (CSV)</a></p><p class="small">Includes official references and current data notes. Blank cells mean not reported; constituency totals repeat on each candidate row and should not be added together.</p>
<p><a href="{{ route($archiveRoute, $filters + ['format' => 'report']) }}">Open printable constituency result report</a> · <a href="{{ route('feedback.create',['path'=>route($archiveRoute,['edition'=>$edition,'state'=>$state,'code'=>$selected['code']],false),'category'=>'data']) }}">Report a problem with this result</a></p>
@if($relatedPlace)
<nav aria-label="Related constituency"><a href="{{ route('places.show', ['type' => $kind, 'slug' => $relatedPlace['slug']]) }}">{{ $relatedPlace['name'] }} present-day profile</a> · <a href="{{ route($kind === 'ac' ? 'elections.compare-assembly' : 'elections.compare', ['slug' => $relatedPlace['slug']]) }}">Election history & boundary references</a></nav>
@if($relatedPlace['earlier'])<p class="small">This historical constituency is linked through checked official records. Its boundaries differ from the present-day constituency; see the boundary references before comparing years.</p>@endif
@if($relatedPlace['previous'] || $relatedPlace['next'])
<nav aria-label="Browse election years">
@if($relatedPlace['previous'])<a href="{{ $relatedPlace['previous']['url'] }}">← Previous available election: {{ $relatedPlace['previous']['year'] }}</a>@endif
@if($relatedPlace['previous'] && $relatedPlace['next']) · @endif
@if($relatedPlace['next'])<a href="{{ $relatedPlace['next']['url'] }}">Next available election: {{ $relatedPlace['next']['year'] }} →</a>@endif
</nav>
<p class="small">Navigation includes verified links only. Historical names and boundaries can change between elections; check the boundary references when comparing results.</p>
@endif
@endif
@if($selected['display_result'])<p><strong>Winner: {{ $selected['display_result']['winner'] }}@if($selected['has_warning']) †@endif</strong> @if($selected['display_result']['uncontested'] ?? false) · Uncontested; no poll or winning margin @else · Margin: {{ number_format($selected['display_result']['margin']) }} votes @if($selected['has_warning'])†@endif @endif</p>@endif
@if(!$selected['display_result'])<p class="notice">* @if(!empty($selected['candidates']))Available candidate rows are shown as recorded.@else Candidate rows could not be extracted reliably.@endif A winner has not been confirmed from this table. <a href="{{ $data['source_url'] }}">Check the official report ↗</a></p>@endif
@php
    $summaryVoteLabel = ($selected['source_warning_code'] ?? '') === 'workbook_pdf_summary'
        ? (isset($selected['summary_totals']['nota_votes']) ? 'Valid candidate votes' : 'Source valid votes (including NOTA)')
        : (in_array($selected['source_warning_code'] ?? '', ['summary_turnout_with_detail_warnings', 'summary_only_turnout'], true)
            ? 'Valid votes (official summary)' : 'Valid candidate votes');
@endphp
<div class="stats">@foreach(['electors'=>'Electors','votes_polled'=>'Votes polled','valid_candidate_votes'=>$summaryVoteLabel] as $key=>$label)@php $value = $key === 'valid_candidate_votes' && in_array($selected['source_warning_code'] ?? '', ['workbook_pdf_summary', 'summary_turnout_with_detail_warnings', 'summary_only_turnout'], true) ? ($selected['summary_totals'][$key] ?? null) : ($selected[$key] ?? null); @endphp<div class="stat"><span>{{ $label }}</span><strong>{{ ($selected['display_result']['uncontested'] ?? false) && $value === 0 ? '—' : ($value !== null ? number_format($value) : '—') }}</strong></div>@endforeach</div>
@php
    $showSymbols = collect($selected['candidates'])->contains(fn ($row) => !empty($row['election_symbol']));
@endphp
<div class="table"><table>
<caption>Candidate votes as recorded in this edition @if($selected['has_warning'])†@endif</caption>
<thead><tr><th scope="col">{{ \App\Services\PublicLanguage::text('Candidate') }}</th><th scope="col">Party at election</th>
@if($showSymbols)
<th scope="col">Election symbol</th>
@endif
<th scope="col">General / EVM votes</th><th scope="col">Postal votes</th><th scope="col">Total votes</th></tr></thead>
<tbody>
@forelse($selected['candidates'] as $candidate)
<tr><td>{{ $candidate['candidate_name'] }}</td><td>{{ $candidate['party_at_election'] }}</td>
@if($showSymbols)
<td>{{ $candidate['election_symbol'] ?? 'Not reported' }}</td>
@endif
@foreach(['general_votes','postal_votes','votes'] as $key)
<td>{{ ($selected['display_result']['uncontested'] ?? false) && ($candidate[$key] ?? null) === 0 ? '—' : (isset($candidate[$key]) ? number_format($candidate[$key]) : 'Not reported') }}</td>
@endforeach
</tr>
@empty
<tr><td colspan="{{ $showSymbols ? 6 : 5 }}">Candidate details need verification. Please check the official report.</td></tr>
@endforelse
</tbody></table></div>
@foreach($selected['edition_notes'] ?? [] as $note)<p class="notice">{{ $note }}</p>@endforeach
@if($selected['has_warning'])<p class="notice" id="data-note"><strong>† Data note:</strong> {{ $selected['error'] ?? 'This record requires review.' }} @if(($selected['source_discrepancy']['field']??'')==='reported_total')Workbook total: {{ number_format($selected['source_discrepancy']['workbook_value']) }}; candidate rows and official summary: {{ number_format($selected['source_discrepancy']['summary_value']) }}. @elseif(($selected['source_discrepancy']['field']??'')==='candidate_total')Candidate rows: {{ number_format($selected['source_discrepancy']['candidate_sum']) }}; official summary: {{ number_format($selected['source_discrepancy']['summary_value']) }}. @elseif(($selected['source_discrepancy']['field']??'')==='elector_components')Source elector components: {{ number_format($selected['source_discrepancy']['component_value']) }}; official summary total: {{ number_format($selected['source_discrepancy']['summary_value']) }}. @endif @if(isset($selected['summary_totals']['valid_candidate_votes'],$selected['valid_candidate_votes']) && $selected['summary_totals']['valid_candidate_votes']!==$selected['valid_candidate_votes'] && ($selected['source_warning_code']??'')!=='workbook_pdf_summary')Detailed candidate votes: {{ number_format($selected['valid_candidate_votes']) }}; report summary: {{ number_format($selected['summary_totals']['valid_candidate_votes']) }}. @endif @if($selected['display_result']['derived'] ?? false)The winner and margin are calculated from the recorded candidate votes. @endif Check the official report for the original figures.</p>
@if(isset($selected['summary_totals']))<details><summary>Compare the report’s summary totals</summary>@foreach(['electors'=>'Electors','votes_polled'=>'Votes polled','valid_candidate_votes'=>$summaryVoteLabel] as $key=>$label)<p>{{ $label }}: {{ isset($selected['summary_totals'][$key]) ? number_format($selected['summary_totals'][$key]) : 'Not reported' }}</p>@endforeach</details>@endif
@elseif($selected['review'])<p class="small">{{ $selected['status']==='corrected' ? 'This result includes a documented correction.' : 'The source discrepancy has a review note.' }} See the original report below.</p>@endif
<p class="small">@isset($selected['detail_page'])Detailed results: PDF page {{ $selected['detail_page'] }}. @endisset {{ $selected['source_locator'] ?? '' }} @isset($selected['summary_page'])Summary: PDF page {{ $selected['summary_page'] }}.@endisset {{ $selected['summary_locator'] ?? '' }}</p>
</section>
@endif
<section class="card" id="sources"><div class="kicker">Evidence</div><h2>Official sources & coverage</h2><p><a href="{{ $data['source_url'] }}" target="_blank" rel="noreferrer">ECI official {{ $data['year'] }} report edition ↗</a></p>
@foreach($data['additional_sources'] ?? [] as $source)@if(!empty($source['source_url']))<p><a href="{{ $source['source_url'] }}" target="_blank" rel="noreferrer">{{ $source['name'] }} ↗</a></p>@endif
@endforeach
@if(!empty($data['coverage']['unmatched_summaries']))<p>{{ count($data['coverage']['unmatched_summaries']) }} summary entries have no matched detailed candidate table.</p>@endif
<p>Coverage includes available results only. † marks figures that need review. Historical results do not identify the current office-holder.</p></section>
@endif
</main>@if($selected)
@include('election-context-nav')
@else
<aside class="archive-guide"><h2>{{ $archiveTitle }} elections</h2><nav class="sidebar-links" aria-label="Related election pages"><a href="{{ route('elections.constituencies') }}">Find a constituency →</a><a href="{{ route('elections.history') }}">Lok Sabha results →</a><a href="{{ route('elections.assembly') }}">Assembly results →</a><a href="{{ route('elections.by-election-results') }}">By-elections →</a><a href="#sources">Official sources ↓</a></nav></aside>
@endif
</div><script>const menu=document.querySelector(".dashboard-sidebar details");const wide=matchMedia("(min-width:1100px)");if(menu){menu.open=wide.matches;wide.addEventListener("change",event=>{menu.open=event.matches;});}</script><script src="/js/sortable-tables.js?v={{ substr(hash_file('sha256', public_path('js/sortable-tables.js')),0,12) }}" defer></script>@include('public-footer')</body></html>
