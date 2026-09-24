<!doctype html>

<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">

@php

    $historicalYear = $selectedElection && $selectedElection->year !== $elections->first()->year ? $selectedElection->year : null;

    $canonical = url()->current().($historicalYear ? '?year='.$historicalYear : '');

    $seoTitle = $pageTitle.($historicalYear ? ' · '.$historicalYear.' election' : '').' · Pollmedia';

    $seoDescription = $pageTitle.': '.($selectedElection ? $selectedElection->year.' election results, parties and winning margins; ' : '').'available representatives, connected places and dated official sources.';

@endphp



@include('seo-metadata', ['breadcrumbs' => ['India' => route('home'), 'Uttar Pradesh' => route('states.show', ['state' => 'uttar-pradesh']), $pageTitle => $canonical]])

<style>

:root{--ink:#173d37;--muted:#65766c;--line:#dce5dc;--green:#176c55}*{box-sizing:border-box}body{margin:0;font:15px/1.6 system-ui,sans-serif;color:var(--ink);background:#f5f6f0}a{color:var(--green);text-underline-offset:4px}header{background:#123d35;padding:20px 5vw;color:white;display:flex;justify-content:space-between}header a{color:#def1e4;text-decoration:none;margin-right:24px}.brand{font-size:27px;font-weight:750}main{max-width:1220px;margin:auto;padding:38px 24px}.crumb,.kicker,.label{font-size:11px;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}.hero h1{font-size:48px;line-height:1.15;letter-spacing:-2px;margin:20px 0}.nav,.sources{display:flex;gap:22px;flex-wrap:wrap;margin:24px 0}.nav{border-bottom:1px solid var(--line);padding-bottom:18px}section{scroll-margin-top:20px;margin:28px 0}h2{font-size:27px;margin:4px 0 12px}h3{font-size:18px;margin:6px 0}.lead,.small{color:var(--muted)}.small{font-size:12px}.stats,.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:20px}.card,.stat{background:white;border:1px solid var(--line);border-radius:10px;padding:23px;min-width:0}.stat{background:#e4ede1}.stat strong{font-size:35px;display:block}.stat small{display:block}.person{border-bottom:1px solid var(--line);padding:18px 0}.person a{font-size:12px;margin-right:16px}.button{display:inline-block;background:var(--green);color:white;border-radius:6px;padding:10px 18px;text-decoration:none}.notice{background:#fff8e8;border-left:3px solid #c9ab69;padding:14px 18px;color:#6e5f42}.search{border:1px solid #b8cabd;border-radius:6px;padding:11px 14px;font:inherit;margin:10px 0 18px}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:11px 13px;border-bottom:1px solid var(--line)}th{background:#eef3eb}.table{overflow:auto}.empty{padding:24px;background:#edf0e9;border-radius:8px}footer{border-top:1px solid var(--line);padding:22px 0;font-size:12px}@media(max-width:800px){.grid3,.grid2,.stats{grid-template-columns:1fr}.hero h1{font-size:36px}}

</style><link rel="stylesheet" href="/css/election-dashboard.css"></head><body class="election-ui"><header class="topbar"><a class="brand" href="/">pollmedia.</a><nav><a href="/">Places</a><a href="/india/sir">SIR explorer</a><a href="#sources">Sources</a><a href="{{ route('sources.index') }}">Data status</a></nav></header><div class="dashboard-shell"><main class="dashboard-main">

<div class="crumb"><a href="{{ route('india') }}">India</a> / <a href="{{ route('states.show', ['state'=>'uttar-pradesh']) }}">Uttar Pradesh</a> / {{ $type === 'district' ? 'Administrative geography' : 'Electoral geography' }}</div>

<div class="hero"><h1>{{ $pageTitle }}</h1><p class="lead">Explore election results, public representatives, development and citizen participation.</p><p class="small">{{ strtoupper($type) }} {{ $code }} · India pilot</p></div>

@if(in_array($place->slug, ['district-pilibhit','pc-pilibhit']))

<a href="{{ route('places.show', ['type' => $type === 'district' ? 'pc' : 'district', 'slug' => 'pilibhit']) }}">View Pilibhit {{ $type === 'district' ? 'PC' : 'district' }} →</a>

@include('place-map')

@else

<section id="geography" class="card"><div class="kicker">Map coverage</div><h2>{{ $place->name }} on the map</h2><p>Verified {{ strtoupper($type) }} boundaries have not been imported yet. Connected places below provide the available geographic context.</p></section>

@endif

<section id="related-places"><div class="kicker">Connected places</div><h2>{{ $type === 'ac' ? 'Parliamentary constituency & district' : 'Assembly constituencies' }}</h2><p class="small">Electoral and administrative relationships are kept separately. Links below follow the cited official source editions. District and electoral boundaries are different; these links do not assert that historical boundaries are unchanged today.</p><div class="grid3">@foreach($relations as $related)<article class="card"><span class="label">{{ strtoupper($related->type) }}</span><h3><a href="{{ $related->url }}">{{ $related->name }} {{ strtoupper($related->type) }} →</a></h3><a class="small" href="{{ $related->source_url }}" target="_blank" rel="noreferrer">Official relationship source ↗</a><p class="small">Source checked {{ $related->checked_on }}@if($related->reference_date)<br>Source edition: {{ $related->reference_date }} · {{ $related->source_locator }}@endif</p></article>@endforeach</div>@if($relations->isEmpty())<p>Verified parliamentary constituency and district links have not been imported for this seat.</p>@endif</section>

@if($crossBoundaryLinks->isNotEmpty())<section class="card" aria-labelledby="cross-boundary-heading"><div class="kicker">District and parliamentary connections</div><h2 id="cross-boundary-heading">{{ $type === 'district' ? 'Related parliamentary constituencies' : 'Related administrative districts' }}</h2><p class="small">These areas share one or more verified Assembly constituency links. This describes an overlap; it does not treat a district and a parliamentary constituency as the same boundary.</p><div class="grid3">@foreach($crossBoundaryLinks as $related)<article><span class="label">{{ strtoupper($related->type) }}</span><h3><a href="{{ $related->url }}">{{ $related->name }} {{ strtoupper($related->type) }} →</a></h3><p class="small">Connected through {{ $related->shared_acs }} linked AC{{ $related->shared_acs === 1 ? '' : 's' }}.</p></article>@endforeach</div></section>@endif

@if($place->slug==='district-pilibhit')

<p><a class="button" href="{{ route('reports.pilibhit') }}">Generate district report draft →</a></p>

@endif

<nav class="nav" aria-label="Page sections"><a href="#elections">Elections & SIR</a><a href="#people">Representatives & authorities</a><a href="#village-coverage">Villages & coverage</a><a href="#development">Development</a><a href="#issues">Citizen issues</a><a href="#geography">Geography</a><a href="#surveys">Community surveys</a></nav>

@if($place->slug === 'pc-pilibhit')<p class="notice">Pilibhit PC and Pilibhit district are different areas. Baheri AC is in Bareilly district. District Census totals are not shown as constituency totals.</p>@endif

@include('election-results')
@if($type === 'pc')<section class="card"><h2>Lok Sabha election history</h2><p><a href="{{ route('elections.compare', ['slug' => substr($place->slug, 3)]) }}">Compare linked election years →</a></p><p>Browse available historical editions by state and constituency. Historical names and boundaries may differ from this constituency.</p><a href="{{ route('elections.history') }}">Explore the national election archive →</a></section>@endif
@if($type === 'ac')<section class="card"><h2>Assembly election history</h2><p><a href="{{ route('elections.compare-assembly', ['slug' => substr($place->slug, 3)]) }}">Compare linked election years →</a></p><p>Explore extracted Uttar Pradesh Assembly reports by year and historical constituency. Historical names and boundaries may differ from this constituency.</p><a href="{{ route('elections.assembly') }}">Explore the Assembly election archive →</a></section>@endif

@if($populationHistoryAvailable)<section><div class="kicker">Historical Census</div><h2>Pilibhit population through the decades</h2><div class="card"><p>Official district population for 11 Census years from 1901 to 2001, with rural, urban and male/female counts.</p><a class="button" href="{{ route('census.history') }}">Explore historical Census data →</a></div></section>@endif
@if($villageCoverage['url'])
@include('place-village-coverage')
@else
<section id="village-coverage" class="card"><h2>Village connections</h2><p>Verified village-to-constituency mappings have not been imported for this seat.</p></section>
@endif

<section id="people"><div class="kicker">People in public office</div><h2>Representatives & authorities</h2><p class="lead">Assignments reflect dated official directory checks. Appointment start dates are not inferred. Current officeholder synchronization is still to be connected.</p><div class="grid2">@foreach(['elected'=>'Elected representatives','administrative'=>'Administrative authorities'] as $kind=>$heading)<div class="card"><h3>{{ $heading }}</h3>@forelse($people->where('kind',$kind) as $person)<article class="person"><div class="small">{{ $person->title }} · {{ $person->area }} {{ strtoupper($person->area_type) }}</div><h3>{{ $person->display_name }}</h3><p class="small">Last verified {{ substr($person->verified_at,0,10) }}</p><a href="{{ $person->url }}" target="_blank" rel="noreferrer">Official directory ↗</a>@foreach($person->profiles as $profile)<a href="{{ $profile->url }}" target="_blank" rel="noreferrer">{{ $profile->kind }} ↗</a>@endforeach</article>@empty<p>No verified assignments imported for this scope. See the connected district or constituency for other offices.</p>@endforelse</div>@endforeach</div></section>

<section id="development"><div class="kicker">Official measurements</div><h2>Development at a glance</h2><p class="lead">Each indicator keeps its own reference year and source. The initial baseline is Census 2011.</p><div class="stats">@forelse($observations as $o)<div class="stat"><small>{{ $o->label }}</small><strong>{{ number_format((float)$o->value) }}</strong><small>{{ $o->period }} · <a href="{{ $o->url }}">Census source ↗</a></small></div>@empty<div class="empty" style="grid-column:1/-1">Constituency development totals have not been validated yet.</div>@endforelse</div><div class="grid3" style="margin-top:18px">@foreach([['Water & sanitation','https://ejalshakti.gov.in/JJM/JJMReports/profiles/rpt_VillageProfile.aspx'],['Education','https://www.udiseplus.gov.in/'],['Health & livelihoods','https://www.data.gov.in/catalog/hmis-sub-district-level-item-wise-monthly-report-uttar-pradesh']] as [$label,$url])<article class="card"><span class="label">Import pending</span><h3>{{ $label }}</h3><a href="{{ $url }}">Official source ↗</a></article>@endforeach</div></section>

<section id="issues"><div class="kicker">Citizen participation</div><h2>Report, verify, follow up</h2><div class="grid3">@foreach(['Report an issue','Identify responsibility','Track resolution'] as $label)<article class="card"><span class="label">Workflow not yet enabled</span><h3>{{ $label }}</h3></article>@endforeach</div><p class="small">Issue submission and external notifications are not active.</p></section>

<section id="village-baseline"><h2>Village data coverage</h2>@if(!empty($census))<div class="card"><h3>Village baseline sample</h3><p><a href="{{ route('villages.index') }}">Browse all Census villages →</a></p><label for="villagesearch">Search 20 sampled Census villages</label><br><input class="search" id="villagesearch" placeholder="Village name or Census code"><div class="table"><table><thead><tr><th>Census code</th><th>Village</th><th>Population · 2011</th><th>Households · 2011</th></tr></thead><tbody id="villagerows">@foreach($census['villages'] as $v)<tr><td>{{ $v['code'] }}</td><td><a href="{{ route('villages.show',['code'=>$v['code'],'slug'=>\Illuminate\Support\Str::slug($v['name'])]) }}">{{ $v['name'] }} →</a></td><td>{{ number_format($v['population']) }}</td><td>{{ number_format($v['households']) }}</td></tr>@endforeach</tbody></table><p id="villageempty" hidden>No sampled villages match.</p></div><p class="small">20-village sample from 1,435 village records. These are administrative records, not constituency or polling-part assignments.</p></div>@else

@if(in_array($place->slug,['pc-pilibhit','ac-pilibhit','ac-barkhera','ac-puranpur','ac-bisalpur']))<div class="card"><h3>Linked Census village profiles</h3><p><a href="{{ route('villages.index',[$type=>substr($place->slug,strlen($type)+1)]) }}">Browse villages mapped to {{ $place->name }} {{ strtoupper($type) }} →</a></p><p class="small">LGD electoral mapping checked 2026-09-16. Coverage includes imported Pilibhit district Census profiles only; this is not a complete constituency population or a polling-station list.</p><a href="https://lgdirectory.gov.in/rptMappedGPNWardforPCAC.do">Official village-to-constituency source ↗</a></div>@else<p class="lead">Census village profiles for this area have not been imported.</p>@endif

@endif</section>

<section id="surveys"><div class="kicker">Community perception</div><h2>Safety, harmony & trust</h2><div class="card"><span class="label">Survey design pending</span><p>Community perceptions will appear separately from official statistics, with survey dates, respondent counts and a published sampling method. No survey scores are available yet.</p></div></section>

@include('place-references')



<footer>Pollmedia · Local integrated pilot · Public launch and source-permission review pending.</footer></main>@include('public-related-links')</div><script>const input=document.getElementById('villagesearch');if(input)input.addEventListener('input',()=>{let visible=0;for(const row of document.querySelectorAll('#villagerows tr')){row.hidden=!row.textContent.toLowerCase().includes(input.value.trim().toLowerCase());if(!row.hidden)visible++}document.getElementById('villageempty').hidden=visible>0});</script></body></html>
