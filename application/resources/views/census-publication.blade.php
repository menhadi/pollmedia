@extends('seo-layout')
@section('content')
<a href="{{ route('imports.run', $preview['record']->id) }}">Back to import run #{{ $preview['record']->id }}</a>
<h1>Publish Census village figures</h1>
<p>Pilibhit / Census 2011 / compared with public release #{{ $preview['release']->id }}.</p>
<p>Matches existing villages by official Census code. Updates population, households, literate persons and children aged 0-6. Historical 2001 data, district totals, names, boundaries and electoral links keep their separately sourced editions.</p>
<section class="card"><h2>Validation and changes</h2>
<p>{{ count($preview['payload']['villages']) }} existing villages / {{ count($preview['changes']) }} changed values / {{ count($preview['added']) }} added codes / {{ count($preview['removed']) }} missing codes.</p>
@if($preview['errors'])
<div class="notice error"><strong>Publication blocked</strong><ul>@foreach(array_slice(array_unique($preview['errors']), 0, 30) as $error)<li>{{ $error }}</li>@endforeach</ul><p>Resolve validation errors before publishing. At most 30 messages are shown.</p></div>
@elseif(!$preview['changes'])
<p class="notice">All mapped figures already match the public pages. No publication is needed.</p>
@else
<p class="notice">Village codes, identities, coverage, numeric values and population totals passed validation.</p>
@endif
<p><a href="{{ $preview['record']->source_url }}" rel="noreferrer" target="_blank">Official workbook</a> / <a href="{{ route('imports.download', $preview['record']->id) }}">Archived file</a></p>
<p class="url">SHA-256: {{ $preview['record']->sha256 }}</p>
@if($preview['changes'])
<div style="overflow:auto;max-height:550px"><table style="width:100%;text-align:left"><thead><tr><th>Village</th><th>Field</th><th>Current</th><th>Imported</th></tr></thead><tbody>
@foreach($preview['changes'] as $change)
<tr><td>{{ $change['code'] }} / {{ $change['name'] }}</td><td>{{ str_replace('_', ' ', $change['field']) }}</td><td>{{ number_format($change['before']) }}</td><td>{{ number_format($change['after']) }}</td></tr>
@endforeach
</tbody></table></div>
@endif
@if($preview['record']->status !== 'accepted')
<p>First <a href="{{ route('imports.run', $preview['record']->id) }}">accept the reviewed baseline</a>, then return here to publish any changes.</p>
@endif
<form method="post" action="{{ route('imports.census.publish', $preview['record']->id) }}">@csrf<input type="hidden" name="base_release_id" value="{{ $preview['release']->id }}"><button @disabled($preview['errors'] || !$preview['changes'] || $preview['record']->status !== 'accepted')>Publish reviewed changes</button></form>
</section>
<section class="card"><h2>Publication history</h2><p>Each publication saves a new source edition. Rolling back restores the previous figures as another edition and keeps the full history.</p>
@forelse($history as $entry)
<div class="row"><strong>{{ ucfirst($entry->action) }} #{{ $entry->id }}</strong> / {{ $entry->created_at }} UTC / administrator #{{ $entry->user_id }}<br><a href="{{ route('imports.census', $entry->import_run_id) }}">Import #{{ $entry->import_run_id }}</a> / release #{{ $entry->before_release_id }} to #{{ $entry->after_release_id }}
@if($entry->action === 'publish' && $entry->after_release_id === $preview['release']->id)
<form method="post" action="{{ route('imports.census.restore', $entry->id) }}">@csrf<input type="hidden" name="base_release_id" value="{{ $preview['release']->id }}"><button class="secondary">Restore previous figures</button></form>
@endif
</div>
@empty
<p>No Census imports have been published yet.</p>
@endforelse
</section>
@endsection
