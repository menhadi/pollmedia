<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
@php
    $isAssembly = $place->type === 'ac';
    $archiveRoute = $isAssembly ? 'elections.assembly' : 'elections.history';
    $canonical = route($isAssembly ? 'elections.compare-assembly' : 'elections.compare', ['slug' => substr($place->slug, 3)]);
    $placeUrl = route('places.show', ['type' => $place->type, 'slug' => substr($place->slug, 3)]);
    $seoTitle = $place->name.' election history & comparisons · Pollmedia';
    $seoDescription = 'Compare linked election years for '.$place->name.', with candidates, parties, winning margins and official boundary references.';
    $breadcrumbs = ['India' => route('home'), $place->name.' '.strtoupper($place->type) => $placeUrl, 'Election history' => $canonical];
    $chartRows = collect($comparison['rows'])->filter(fn ($row) => $row['record'] && !$row['record']['has_warning'] && isset($row['record']['winner'], $row['record']['margin']));
    $maxMargin = $chartRows->max(fn ($row) => $row['record']['margin']) ?: 1;
@endphp
@include('seo-metadata')
<link rel="stylesheet" href="/css/villages.css"><link rel="stylesheet" href="/css/election-dashboard.css?v={{ substr(hash_file('sha256', public_path('css/election-dashboard.css')), 0, 12) }}"></head><body class="election-ui">
<header class="topbar"><a class="brand" href="{{ route('home') }}">pollmedia.</a><nav><a href="{{ $placeUrl }}">Constituency profile</a><a href="{{ route($archiveRoute) }}">All election years</a></nav></header><main>
<div class="kicker">{{ $isAssembly ? 'Assembly' : 'Lok Sabha' }} / Election comparisons</div><h1>{{ $place->name }} across elections</h1>
@if(!$comparison['mapping'])<section class="card"><h2>Historical links need verification</h2><p>This constituency does not yet have a verified connection to the supported historical editions.</p><a href="{{ route($archiveRoute) }}">Browse official historical tables →</a></section>
@else
<p class="lead">Uttar Pradesh · Official {{ strtoupper($place->type) }} {{ $comparison['mapping']['code'] }} · {{ $isAssembly ? '2012, 2017 and 2022' : '2009, 2014, 2019 and 2024' }}</p>
<p class="notice">Linked using official Uttar Pradesh constituency references and each report’s code and name. Earlier elections remain separately available in the <a href="{{ route($archiveRoute) }}">historical archive</a>. Figures describe each election’s electorate; they do not measure how individual voters changed their vote.</p>
<section class="card"><h2>Year-by-year results</h2><div class="table"><table><caption>General elections only.@if(!$isAssembly) The 2019 edition including Vellore is used once; the alternative edition is not double-counted.@endif</caption><thead><tr><th scope="col">Year</th><th scope="col">Winner</th><th scope="col">Party at election</th><th scope="col">Margin</th><th scope="col">Electors</th><th scope="col">Votes polled</th><th scope="col">Evidence</th></tr></thead><tbody>
@foreach($comparison['rows'] as $row)
@php($record = $row['record'])
<tr><th scope="row">{{ $row['year'] }} @if($record && $record['has_warning'])<a href="#note-{{ $row['year'] }}" aria-label="Data note for {{ $row['year'] }}">†</a>@endif</th>
@if($record)<td>{{ !$record['has_warning'] ? ($record['winner'] ?? 'Not established') : 'Under review' }}</td><td>{{ $row['winner_party'] ?? '—' }}</td><td>{{ !$record['has_warning'] && isset($record['margin']) ? number_format($record['margin']) : '—' }}</td><td>{{ isset($record['electors']) ? number_format($record['electors']) : 'Not reported' }}</td><td>{{ isset($record['votes_polled']) ? number_format($record['votes_polled']) : 'Not reported' }}</td><td><a href="{{ $row['url'] }}">Candidate table</a> · <a href="{{ $row['source_url'] }}" target="_blank" rel="noreferrer">ECI source ↗</a></td>
@else<td colspan="6">{{ $row['reason'] }}</td>@endif
</tr>
@endforeach
</tbody></table></div>
@foreach($comparison['rows'] as $row)
@if(!empty($row['mapping_note']))<p class="small"><strong>{{ $row['year'] }} name reference:</strong> {{ $row['mapping_note'] }} <a href="{{ $row['url'] }}">View the original record</a>.</p>@endif
@if($row['record'] && $row['record']['has_warning'])<p id="note-{{ $row['year'] }}" class="notice"><strong>† {{ $row['year'] }}:</strong> {{ $row['record']['error'] ?? 'Source data requires review.' }} Reported totals are shown with this note; a winner and margin are not inferred.</p>@endif
@endforeach
</section>
<section class="card"><h2>Turnout and party shares by year</h2><p class="small">Only eligible, reconciled single-seat records contribute. Turnout is votes polled divided by electors; party shares use recorded candidate votes plus NOTA. Missing figures remain unavailable.</p><div class="table"><table><thead><tr><th scope="col">Year</th><th scope="col">Votes polled</th><th scope="col">Turnout</th><th scope="col">Margin � percentage points</th><th scope="col">Party � votes � share</th></tr></thead><tbody>
@foreach($comparison['rows'] as $row)
@php($yearAnalysis=app(\App\Services\HistoricalElectionAnalytics::class)->summarize($row['record'] ? [$row['record']] : []))
<tr><th scope="row"><a href="{{ $row['url'] }}">{{ $row['year'] }}</a></th><td>{{ $yearAnalysis['polled']===null?'�':number_format($yearAnalysis['polled']) }}</td><td>{{ $yearAnalysis['turnout']===null?'�':number_format($yearAnalysis['turnout'],2).'%' }}</td><td>{{ $yearAnalysis['margin_percent']===null?'�':number_format($yearAnalysis['margin_percent'],2) }}</td><td>@forelse($yearAnalysis['parties'] as $partyRow)<div>{{ $partyRow['party'] }} � {{ number_format($partyRow['votes']) }} � {{ number_format($partyRow['share'],2) }}%</div>@empty Not available @endforelse</td></tr>
@endforeach
</tbody></table></div></section>
<section class="card"><h2>Winning margins over time</h2><p class="small">Margin in votes, on one shared scale. Years with unresolved result checks or no established winner are omitted.</p>
@forelse($chartRows as $row)<div style="margin:20px 0"><p><strong>{{ $row['year'] }} · {{ number_format($row['record']['margin']) }} votes</strong> · {{ $row['record']['winner'] }} · {{ $row['winner_party'] }}</p><div style="height:14px;background:#edf0e9" aria-hidden="true"><div style="height:100%;background:#176c55;width:{{ 100 * $row['record']['margin'] / $maxMargin }}%"></div></div></div>
@empty<p>No reconciled winning margins are available yet.</p>@endforelse
</section>
@if(!empty($comparison['earlier']))
<section class="card"><h2>Earlier Pilibhit: a different constituency extent</h2>
<p>The 1976 order lists Pilibhit as PC 13 with these Assembly segments: {{ implode(', ', $comparison['earlier']['segments']) }}. The later order lists PC 26 with {{ $comparison['mapping']['source_text'] }}</p>
<p class="notice">The older list includes Powayan; the later list includes Baheri. Assembly segment boundaries and codes also changed. These older results are available separately and are not added to the modern margin chart.</p>
<div class="links">@foreach($comparison['earlier']['links'] as $link)<a href="{{ $link['url'] }}">{{ $link['year'] }} candidate results</a>@endforeach</div>
<p><a href="{{ $comparison['earlier']['url'] }}#page={{ $comparison['earlier']['pdf_page'] }}" target="_blank" rel="noreferrer">Official 1976 order · PDF page {{ $comparison['earlier']['pdf_page'] }} (printed page {{ $comparison['earlier']['printed_page'] }}) ↗</a> · <a href="{{ $comparison['mapping']['url'] }}#page={{ $comparison['mapping']['page'] }}" target="_blank" rel="noreferrer">Later UP order · PDF page {{ $comparison['mapping']['page'] }} ↗</a></p>
@if($comparison['earlier']['reorganisation'])<p><strong>2004 numbering:</strong> The ECI report records Pilibhit as PC 9. The 2000 reorganisation schedule removes old PCs 1–4 and 85 and amends three other constituencies; it does not amend old PC 13 (Pilibhit). <a href="{{ $comparison['earlier']['reorganisation']['url'] }}#page=34" target="_blank" rel="noreferrer">Official Second Schedule · PDF page 34 (printed page 29) ↗</a>.</p>@endif
@foreach($comparison['earlier']['older_orders'] as $order)<p>{{ $order['note'] }} <a href="{{ $order['url'] }}#page={{ $order['pdf_page'] }}" target="_blank" rel="noreferrer">Official earlier order · PDF page {{ $order['pdf_page'] }} @if($order['printed_page']) (printed page {{ $order['printed_page'] }}) @endif ↗</a>.</p>@endforeach
<p class="small">These links retain each report’s historical identity. They do not establish village-level boundary overlap. All available Pilibhit general-election editions from 1951 to 2004 are linked here. The 1951 record is a combined historical constituency.</p></section>
@endif
@if($isAssembly)<section class="card"><h2>How these records are linked</h2><p>The accepted 2022 AC identifier and constituency name are checked against the <a href="{{ $comparison['mapping']['url'] }}#page={{ $comparison['mapping']['page'] }}">official Uttar Pradesh constituency list, PDF page {{ $comparison['mapping']['page'] }}</a> and each election report. Names and codes must match; unmatched records remain unavailable for comparison.</p><p>These links establish constituency identity, not village-level boundary equivalence. Earlier elections remain available in the Assembly archive. Admin reviews are reflected automatically.</p></section>
@else<section class="card"><h2>Why these years are linked</h2><p>The constituency identity is checked against <a href="{{ $comparison['mapping']['url'] }}" target="_blank" rel="noreferrer">ECI’s Uttar Pradesh delimitation table, PDF page {{ $comparison['mapping']['page'] }} ↗</a>.</p><ul><li><a href="https://www.pib.gov.in/newsite/erelcontent.aspx?lang=2&reg=48&relid=48192" target="_blank" rel="noreferrer">ECI’s 2009 election announcement: the new delimitation framework</a></li><li><a href="https://www.eci.gov.in/EBooks/eci-atlas/files/basic-html/page41.html" target="_blank" rel="noreferrer">ECI 2019 Atlas: constituency extent since 2009</a></li><li><a href="https://www.eci.gov.in/EBooks/atlas-2024/files/basic-html/page33.html" target="_blank" rel="noreferrer">ECI 2024 Atlas: applicable delimitation framework</a></li></ul><p>Scope: supported Uttar Pradesh parliamentary constituency identities only. Unmatched names remain pending review. Admin corrections and acceptance are reflected automatically; source changes are checked again.</p></section>
@endif
@endif
<footer>Pollmedia · Official sources, historical identities and explicit coverage.</footer></main></body></html>
