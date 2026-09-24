<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>{{ $selected['name'] }} · {{ $data['year'] }} {{ strtoupper($kind) }} result report · Pollmedia</title>
<style>
body{max-width:900px;margin:2rem auto;padding:0 1rem;color:#173d37;font:15px/1.5 system-ui,sans-serif}a{color:#176c55}h1{line-height:1.2}header,footer{border-bottom:1px solid #b8cabd;padding-bottom:1rem}footer{border-top:1px solid #b8cabd;border-bottom:0;margin-top:2rem;padding-top:1rem}.notice{background:#fff8e8;border-left:4px solid #c9ab69;padding:.75rem 1rem}table{border-collapse:collapse;width:100%;margin:1rem 0}th,td{border:1px solid #b8cabd;padding:.5rem;text-align:left;vertical-align:top}th{background:#e4ede1}.numbers{display:flex;gap:2rem;flex-wrap:wrap}.numbers p{margin:.5rem 0}.small{font-size:.85rem}.actions{display:flex;gap:1rem;flex-wrap:wrap}@media print{body{margin:0;max-width:none}.actions{display:none}a{color:inherit;text-decoration:none}tr{break-inside:avoid}}
</style>
<script src="/js/instant-filters.js?v={{ substr(hash_file('sha256',public_path('js/instant-filters.js')),0,12) }}" defer></script></head>
<body>
<header><strong>Pollmedia · historical election result</strong><h1>{{ $selected['name'] }}</h1><p>{{ $data['year'] }} {{ $kind === 'pc' ? 'Lok Sabha' : 'Assembly' }} election · {{ $state }} · Official constituency code: {{ $selected['official_pc_code'] ?? $selected['official_ac_code'] ?? ($kind === 'ac' ? $selected['code'] : 'Not reported') }}</p><p class="actions"><button type="button" onclick="window.print()">Print or save as PDF</button><a href="{{ route($kind === 'pc' ? 'elections.history' : 'elections.assembly', ['edition' => $edition, 'state' => $state, 'code' => $selected['code']]) }}">View online result</a></p></header>
<p class="notice">This report reproduces one constituency table from the cited historical edition. Names and boundaries belong to that election. It does not establish a match with a present-day constituency or complete national coverage.</p>
@if($selected['has_warning'])<p class="notice"><strong>† Under review:</strong> {{ $selected['error'] ?? 'This record requires review.' }} Candidate rows may be incomplete; check the official report.</p>@elseif($selected['review'])<p>Administrator review: {{ $selected['status'] === 'corrected' ? 'corrected' : 'accepted' }}. Source references remain below.</p>@endif
@foreach($selected['edition_notes'] ?? [] as $note)<p class="notice">{{ $note }}</p>@endforeach
<div class="numbers">@foreach(['electors' => 'Electors', 'votes_polled' => 'Votes polled', 'valid_candidate_votes' => 'Valid candidate votes'] as $key => $label)<p><strong>{{ $label }}:</strong> {{ isset($selected[$key]) ? number_format($selected[$key]) : 'Not reported' }}</p>@endforeach</div>
@if(! $selected['has_warning'] && isset($selected['winner'], $selected['margin']) && ($selected['number_of_seats'] ?? 1) === 1)<p><strong>Winner:</strong> {{ $selected['winner'] }} · <strong>Margin:</strong> {{ number_format($selected['margin']) }} votes</p>@endif
<table><caption>Candidate votes as recorded in the selected report edition</caption><thead><tr><th>Candidate</th><th>Party at election</th><th>General / EVM votes</th><th>Postal votes</th><th>Total votes</th></tr></thead><tbody>
@forelse($selected['candidates'] as $candidate)<tr><td>{{ $candidate['candidate_name'] }}</td><td>{{ $candidate['party_at_election'] }}</td>@foreach(['general_votes', 'postal_votes', 'votes'] as $key)<td>{{ isset($candidate[$key]) ? number_format($candidate[$key]) : 'Not reported' }}</td>@endforeach</tr>
@empty<tr><td colspan="5">Candidate rows could not be extracted reliably. Consult the official source.</td></tr>@endforelse
</tbody></table>
<h2>Source and provenance</h2><p><a href="{{ $data['source_url'] }}">Official election report edition</a></p><p class="small">Archive edition: {{ $edition }} · Source SHA-256: {{ $data['source_sha256'] }} · @isset($selected['detail_page'])Detailed table: PDF page {{ $selected['detail_page'] }} · @endisset {{ $selected['source_locator'] ?? '' }}</p>
@foreach($data['additional_sources'] ?? [] as $source)@if(! empty($source['source_url']))<p class="small"><a href="{{ $source['source_url'] }}">Additional official source: {{ $source['name'] }}</a></p>@endif @endforeach
<footer>Pollmedia research report · Generated {{ now('Asia/Kolkata')->format('d M Y, H:i') }} IST · Figures remain attributed to their original election year.</footer>
</body></html>
