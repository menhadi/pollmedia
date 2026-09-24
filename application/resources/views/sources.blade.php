<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sources & update status · Pollmedia</title><meta name="description" content="Check Pollmedia's official data sources, imported editions and update status."><link rel="stylesheet" href="/css/villages.css"><script src="/js/instant-filters.js?v={{ substr(hash_file('sha256',public_path('js/instant-filters.js')),0,12) }}" defer></script></head>
<body><header><a class="brand" href="{{ route('home') }}">pollmedia.</a><nav><a href="{{ route('home') }}">Explore India</a><a href="{{ route('villages.index') }}">Villages</a></nav></header><main>
<div class="kicker">Evidence & dates</div><h1>Sources & update status</h1><p class="lead">See which official sources support Pollmedia and when the displayed editions were imported.</p>
<p class="notice"><strong>Updates are currently manual.</strong> No automatic source refresh is active in this pilot. An import date is not the date an official took office or a measurement was collected. Historical Census and election figures keep their original reference year.</p>
<p><a href="{{ route('elections.history') }}">Lok Sabha historical results</a> · <a href="{{ route('elections.assembly') }}">Available Assembly results</a> · <a href="{{ route('census-catalogue.index') }}">Census tables</a> · <a href="{{ route('census.national-history') }}">Historical population</a> · <a href="{{ route('indicators.india') }}">Other historical measurements</a></p>
<form method="get" class="search"><div><label for="publisher">Official publisher</label><select id="publisher" name="publisher"><option value="">All publishers</option>@foreach($publishers as $name)<option value="{{ $name }}" @selected($publisher===$name)>{{ $name }}</option>@endforeach</select></div><button type="submit">Show sources</button><a href="{{ route('sources.index') }}">Reset</a></form>
<p role="status">{{ $sources->count() }} sources in this selection.</p><div class="grid">
@forelse($sources as $source)
<article class="card"><div class="kicker">{{ $source->publisher }}</div><h2>{{ $source->label }}</h2>
@if($source->monitor)
@if($source->review_pending && $source->monitor->status==='failed')<p><strong>An earlier directory change still needs review.</strong></p>@endif
<p><strong>{{ ['baseline'=>'Monitoring baseline captured','unchanged'=>'No table changes since monitoring began','changed'=>'Official directory changed — review needed','failed'=>'Latest source check failed'][$source->monitor->status] }}</strong><br><span class="small">Last attempted check {{ $source->monitor->checked_at }} (application time). Checks compare directory tables with the first monitoring snapshot; they do not verify officeholders or replace displayed records.</span></p>
@endif
@if($source->release)
<p><strong>Displayed edition imported {{ substr($source->release->retrieved_at,0,10) }}</strong><br>{{ $source->import_age }} days since import.</p>
<p class="small">Publication date: {{ $source->release->published_on ?? 'Not recorded' }}<br>Refresh method: manual import / verification.</p>
@else
<p>No accepted edition is available for display.</p>
@endif
@if($source->editions->isNotEmpty())<details><summary>All {{ $source->editions->count() }} recorded source editions</summary><table><thead><tr><th>Edition</th><th>Retrieved</th><th>Status / note</th><th>Official reference</th></tr></thead><tbody>
@foreach($source->editions as $entry)<tr><td>{{ $entry->id }}</td><td>{{ $entry->retrieved_at }}</td><td>{{ $entry->status }} @if($entry->status !== 'accepted')&#8224; @if($entry->status === 'superseded')Earlier edition.@elseif(in_array($entry->status, ['pending','needs_review']))Awaiting review; available measurements retain this note.@else Retained in source history; this status does not establish a current fact.@endif @endif</td><td><a href="{{ $entry->url }}">Official source</a></td></tr>@endforeach
</tbody></table></details>@endif
<p><a href="{{ $source->url }}" target="_blank" rel="noreferrer">Official source ↗</a></p>
@if($source->release && $source->release->sha256)<details><summary>Verify downloaded edition</summary><p class="small">SHA-256 identifies the exact imported file.</p><p class="hash">{{ $source->release->sha256 }}</p></details>@endif
</article>
@empty
<p>No sources have been imported.</p>
@endforelse
</div><section class="card"><h2>How to read these dates</h2><p>For changing information such as officeholders, check the dated entry and its official directory. A recent import does not guarantee that the publisher updated every record recently. Older historical data is not automatically incorrect because its import is old.</p></section>
<footer>Pollmedia · Local research pilot · Official links and dated evidence</footer></main></body></html>
