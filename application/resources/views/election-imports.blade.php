@extends('seo-layout')
@section('content')
<a href="{{ route('imports.index') }}">Back to data imports</a>
<h1>Election imports & publishing</h1><section class="card"><h2>Statewide bulk imports</h2><p>Review statewide 2022, 2017 and 2012 Uttar Pradesh imports, including incomplete source coverage.</p><a class="button" href="{{ route('election-batches.index') }}">Open statewide imports</a></section>
<p>Review official ECI reports before updating constituency results, vote-share charts and historical margins.</p>
<p>Coverage goal: every election year available from official sources, including archived reports. Browse the source catalogue below; publication coverage is shown separately.</p>
<section class="card" id="historical-archive"><h2>Official historical archive</h2>
<p><a href="{{ $catalogue['catalogue_url'] }}" target="_blank" rel="noreferrer">ECI statistical reports catalogue</a> / checked {{ $catalogue['checked_on'] }}. Includes all {{ count($catalogue['pc']) }} listed Lok Sabha entries and {{ count($catalogue['ac']) }} Uttar Pradesh Assembly years in this catalogue snapshot.</p>
<form method="get" action="{{ route('election-imports.index') }}#historical-archive" class="filters">
<div><label for="archive_type">Election archive</label><select id="archive_type" name="archive_type"><option value="pc" @selected($archiveType==='pc')>Lok Sabha / PC</option><option value="ac" @selected($archiveType==='ac')>Uttar Pradesh Assembly / AC</option></select></div>
<div><label for="archive_year">Source year</label><select id="archive_year" name="archive_year"><option value="">All source years</option>@foreach($archiveYears as $year)<option value="{{ $year }}" @selected($archiveYear===$year)>{{ $year }}</option>@endforeach</select></div><button>Show archive</button></form>
<p class="muted">A catalogue entry confirms that ECI lists a report; it does not confirm that its download works or that it covers a particular constituency. Local result counts refer to the year, not to a verified match with every report variant. Older constituency codes, boundaries and multi-member seats need historical mapping before comparison.</p>
<div style="overflow:auto"><table style="width:100%;text-align:left"><thead><tr><th>Official report edition</th><th>Public pilot constituencies for this year</th><th>Local official files</th><th>Publication status</th></tr></thead><tbody>
@forelse($archiveEntries as $entry)<tr><td><a href="{{ $entry['url'] }}" target="_blank" rel="noreferrer">{{ $entry['label'] }}</a></td><td>{{ $entry['published_places'] }}</td><td>{{ str_replace('_',' ',$entry['collection']['status']) }} · {{ count($entry['collection']['files']) }} files
@if($entry['collection']['files'])<details><summary>Inspect archived reports</summary><ul>@foreach($entry['collection']['files'] as $file)<li><a href="{{ route('election-archives.file',[$entry['collection']['id'],$file['download_id']]) }}">{{ $file['name'] }}</a> · {{ number_format($file['bytes']/1024) }} KB<br><small style="overflow-wrap:anywhere">SHA-256 {{ $file['sha256'] }}</small></li>@endforeach</ul></details>@endif
@if($entry['collection']['errors'])<details><summary>{{ count($entry['collection']['errors']) }} collection issues</summary><ul>@foreach($entry['collection']['errors'] as $issue)<li>{{ is_array($issue) ? ($issue['reason'] ?? 'Source needs review') : $issue }}</li>@endforeach</ul></details>@endif
</td><td>@if($entry['collection']['has_extraction'] ?? false)<a href="{{ route('election-archives.extraction',$entry['collection']['id']) }}">Review extracted candidate tables</a><p>Historical mapping and publication pending.</p>@else{{ $entry['published_places'] ? 'Partial pilot coverage; remaining coverage to review' : 'Extraction and historical mapping pending' }}@endif</td></tr>
@empty<tr><td colspan="4">No entry for this year in the selected official catalogue snapshot.</td></tr>@endforelse
</tbody></table></div>
<p>By-elections and reorganized-state archives remain separate categories in the <a href="{{ $catalogue['catalogue_url'] }}" target="_blank" rel="noreferrer">official catalogue</a>; their coverage has not yet been imported here.</p></section>
<section class="card"><h2>Review editions with working extractors</h2><p>Individual re-publication tools: Pilibhit PC 2019/2024 and five pilot ACs for 2022. Statewide 2012/2017/2022 imports use the batch workflow above. Historical entries above remain visible while their extraction and geographic mapping are prepared.</p>
<form method="post" enctype="multipart/form-data" action="{{ route('election-imports.store') }}">@csrf
<div class="field"><label for="contest_id">Constituency and election year</label><select name="contest_id" id="contest_id" required>@foreach($contests as $contest)<option value="{{ $contest->id }}" @selected((string)old('contest_id')===(string)$contest->id)>{{ strtoupper($contest->type) }} / {{ $contest->name }} / {{ $contest->year }}</option>@endforeach</select></div>
<p><button name="mode" value="archive" class="secondary">Preview saved official reports</button></p>
<details><summary>Upload a newly downloaded official report</summary>
<p>AC 2022: upload “10-Detailed Results.xlsx” and “8-Constituency Data Summary” workbook. PC 2024: Report 33 PDF only. PC 2019: Report 33 detailed PDF and Report 32 summary PDF. Choose the matching constituency and year above.</p>
<div class="field"><label for="detail">Detailed report</label><input type="file" id="detail" name="detail" accept=".xlsx,.pdf"></div>
<div class="field"><label for="totals">Summary report (AC 2022 and PC 2019)</label><input type="file" id="totals" name="totals" accept=".xlsx,.pdf"></div>
<p>These ECI report adapters use uploaded or saved official files. Automatic URL fetching remains managed separately in Data imports.</p><button name="mode" value="upload">Extract and preview</button>
</details></form></section>
<section class="card"><h2>Review and publication history</h2>
@forelse($drafts as $draft)
<div class="row"><a href="{{ route('election-imports.show', $draft->id) }}">{{ $draft->name }} / {{ $draft->year }}</a> / {{ str_replace('_',' ',$draft->status) }}<br><small>Created {{ $draft->created_at }} UTC / administrator #{{ $draft->created_by }}</small></div>
@empty<p>No election reviews yet.</p>@endforelse
{{ $drafts->links() }}</section>
@endsection
