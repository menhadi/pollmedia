@extends('seo-layout')
@section('content')
<h1>Representatives and authorities</h1>
<p>Compare published officeholders with official directory evidence. A detected table change does not establish a transfer, appointment date or vacancy.</p>
<section class="card"><h2>Published officeholders</h2>
@forelse($assignments as $assignment)
<div class="row"><strong>{{ $assignment->title }}</strong> — {{ $assignment->display_name ?? 'No named officeholder' }}
<p>{{ ucfirst(str_replace('_', ' ', $assignment->status)) }} · Verified {{ $assignment->verified_at }} UTC
@if($assignment->effective_from) · Effective from {{ $assignment->effective_from }} @else · Appointment date not recorded @endif</p>
<a href="{{ $assignment->url }}" target="_blank" rel="noopener noreferrer">Official evidence ↗</a></div>
@empty<p>No officeholders have been published.</p>@endforelse
</section>
@forelse($sources as $source)
<section class="card"><h2>{{ \Illuminate\Support\Str::headline($source->key) }}</h2>
<a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer">Open official directory ↗</a>
<p>Latest check: {{ $source->latest->checked_at ?? 'Not checked yet' }}{{ $source->latest ? ' UTC' : '' }} · {{ $source->latest->status ?? 'Pending' }}</p>
@if(($source->latest->status ?? null) === 'failed')<p class="notice error">Latest check failed. The last successful evidence remains below.</p>@endif
@if(($source->successful->status ?? null) === 'changed')<p class="notice">Directory changes need review. Published officeholders remain unchanged.</p>@endif
<form method="post" action="{{ route('authorities.check', $source->key) }}">@csrf<button>Check official directory now</button></form>
@foreach(['Last successful capture' => $source->tables, 'Original monitoring baseline' => $source->baselineTables] as $heading => $tables)
<details class="card" @if($loop->first) open @endif><summary><strong>{{ $heading }}</strong></summary>
<p class="muted">Captured {{ $loop->first ? ($source->successful->checked_at ?? 'Never') : ($source->baseline->checked_at ?? 'Never') }} UTC</p>
@forelse($tables as $table)
<div style="overflow-x:auto;margin:16px 0"><table style="border-collapse:collapse;width:100%"><caption>Official directory table {{ $loop->iteration }}</caption><tbody>
@foreach($table as $row)<tr>@foreach($row as $cell)<td style="border:1px solid #d5dfd5;padding:8px;vertical-align:top">{{ $cell }}</td>@endforeach</tr>@endforeach
</tbody></table></div>
@empty<p>No captured table is available.</p>@endforelse
</details>@endforeach
@if($source->successful && $source->latest->id === $source->successful->id)
<details class="card"><summary><strong>Publish a reviewed officeholder</strong></summary>
<p>Use this only for an unambiguous, single-holder appointment in this directory. Confirm the person's name, office and jurisdiction against the captured table. Acting appointments, vacancies and conflicting evidence need separate review. The capture date is a verification date, not a tenure start date.</p>
@forelse($source->offices as $office)
<form method="post" action="{{ route('authorities.replace') }}" class="card">@csrf
<h3>{{ $office->title }}</h3>
<p>Linked jurisdiction: @foreach($office->places as $place){{ $place->name }} ({{ strtoupper($place->type) }}){{ $loop->last ? '' : ', ' }}@endforeach</p>
<input type="hidden" name="check_id" value="{{ $source->successful->id }}"><input type="hidden" name="office_id" value="{{ $office->id }}"><input type="hidden" name="assignment_id" value="{{ $office->assignment_id }}">
<div class="field"><label for="name-{{ $office->id }}">Exact name in the captured directory</label><input id="name-{{ $office->id }}" type="text" name="name" required maxlength="200"></div>
<div class="field"><label for="note-{{ $office->id }}">Review evidence and jurisdiction</label><textarea id="note-{{ $office->id }}" name="note" required minlength="10" maxlength="2000"></textarea></div>
<label><input type="checkbox" name="confirmed" value="1" required>I verified this person's identity, office and jurisdiction in the official evidence.</label>
<p class="muted">A different name creates a separate person record; names alone are not used to merge identities. This publishes to linked place pages and records your review.</p><button>Publish reviewed officeholder</button>
</form>
@empty<p>No published office is linked to this source yet.</p>@endforelse
</details>
@endif
</section>
@empty<p>No official directories are configured.</p>@endforelse
@endsection
