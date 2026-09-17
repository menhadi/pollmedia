@extends('geography-layout')
@section('title', $place->name)
@section('content')
<p class="muted">{{ $place->country_code }} · {{ \Illuminate\Support\Str::headline($place->type) }}</p><h1>{{ $place->name }}</h1>
<p class="notice">This profile contains available accepted evidence for this place. Missing records do not mean zero activity or no responsible authority.</p>
<p><a href="{{ route('indicators.index', ['country' => $place->country_code, 'place' => $place->id]) }}">Browse this place's historical measurements</a></p>
<section class="card"><h2>Election evidence</h2>@forelse($elections as $election)<p>{{ $election->year }} · {{ \Illuminate\Support\Str::headline($election->election_type) }} · <a href="{{ $election->url }}">Official results</a> · {{ $election->source_locator }}</p>@empty<p>No accepted election results are linked to this place.</p>@endforelse</section>
<section class="card"><h2>Representatives and authorities</h2>@forelse($people as $person)<div class="row"><h3>{{ $person->title }}</h3><p>{{ $person->display_name ?? 'No named officeholder' }} · {{ \Illuminate\Support\Str::headline($person->status) }}</p><p>Verified {{ $person->verified_at }} UTC · <a href="{{ $person->url }}">Official evidence</a></p></div>@empty<p>No verified officeholder assignments are linked to this place.</p>@endforelse</section>
<section class="card"><h2>Development and historical measurements</h2><p>Periods and units come from each dataset. These values are not summed across overlapping areas.</p><div class="scroll"><table><thead><tr><th>Indicator</th><th>Period</th><th>Value</th><th>Evidence</th></tr></thead><tbody>
@forelse($observations as $row)<tr><td>{{ $row->label }}<br>{{ $row->unit }}</td><td>{{ $row->period }}</td><td>{{ $row->value === null ? 'Not available' : $row->value }}</td><td>{{ \Illuminate\Support\Str::headline($row->evidence_class) }} · <a href="{{ $row->url }}">Official source</a><br>{{ $row->source_locator }}</td></tr>@empty<tr><td colspan="4">No accepted measurements are available for this place.</td></tr>@endforelse
</tbody></table></div></section>
<section class="card"><h2>Linked geography</h2>@forelse($relations as $relation)
@php($other = $relatedPlaces->get($relation->from_place_id === $place->id ? $relation->to_place_id : $relation->from_place_id))
@if($other)<p><a href="{{ route('geography.show', $other->slug) }}">{{ $other->name }}</a> ({{ $other->country_code }} · {{ $other->type }}) · {{ \Illuminate\Support\Str::headline($relation->type) }} · {{ $relation->from_place_id === $place->id ? 'Outgoing relation' : 'Incoming relation' }} · <a href="{{ $relation->url }}">Source</a></p>@endif
@empty<p>No current source-backed relationships are available.</p>@endforelse</section>
<section class="card"><h2>Identifiers and editions</h2>@forelse($identifiers as $identifier)<p>{{ $identifier->namespace }} · {{ $identifier->code }} · {{ $identifier->version }} · <a href="{{ $identifier->url }}">Source</a></p>@empty<p>No accepted identifiers are available.</p>@endforelse</section>
@endsection
