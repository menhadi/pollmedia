@extends('geography-layout')
@section('title', 'Find historical constituency results')
@section('content')
<h1>Find historical constituency results</h1>
<p>Search the available source-backed Lok Sabha and Assembly candidate tables by the constituency name recorded in each election edition. <a href="{{ route('elections.by-election-results') }}">Browse by-election results separately</a>.</p>
<p class="notice">Each row belongs to one report edition. A matching name in another year does not prove that the constituency had the same boundaries. Extracted tables are shown with source review warnings and are not necessarily published as accepted place-profile results.</p>
@if(! $indexed || ! $results->total())
<p class="notice">@if(! $indexed)Search is not yet available for these records. @else No election records match this selection. @endif You can still browse <a href="{{ route('elections.history') }}">Lok Sabha editions</a> and <a href="{{ route('elections.assembly') }}">Assembly editions</a>.</p>
@endif
<form method="get" action="{{ route('elections.constituencies') }}" class="card filters">
<div><label for="q">Constituency name</label><input id="q" name="q" type="search" value="{{ $input['q'] ?? '' }}" placeholder="Name as recorded in an election"></div>
<div><label for="kind">Election type</label><select id="kind" name="kind"><option value="">PC and AC</option><option value="pc" @selected(($input['kind'] ?? '') === 'pc')>Lok Sabha (PC)</option><option value="ac" @selected(($input['kind'] ?? '') === 'ac')>Assembly (AC)</option></select></div>
<div><label for="year">Election year</label><select id="year" name="year"><option value="">All available years</option>@foreach($years as $year)<option value="{{ $year }}" @selected(($input['year'] ?? '') == $year)>{{ $year }}</option>@endforeach</select></div>
<div><label for="state">State / Union Territory</label><select id="state" name="state"><option value="">All recorded states</option>@foreach($states as $state)<option value="{{ $state }}" @selected(($input['state'] ?? '') === $state)>{{ $state }}</option>@endforeach</select></div>
<button>Find results</button>
</form>
@if($results && $results->total())
<p>{{ number_format($results->total()) }} election records match. Each link opens candidate figures, the official source and any data notes.</p>
<div class="scroll"><table><thead><tr><th>Election</th><th>State as recorded</th><th>Constituency</th><th>Candidate rows</th><th>Data notes</th></tr></thead><tbody>
@foreach($results as $result)<tr><td>{{ $result->edition_label }} · {{ strtoupper($result->kind) }}</td><td>{{ $result->state_label ?? 'State not identified' }}</td><td><a href="{{ route($result->kind === 'pc' ? 'elections.history' : 'elections.assembly', ['edition' => $result->edition_id, 'state' => $result->state_label, 'code' => $result->record_code]) }}">{{ $result->constituency_name }}</a></td><td>{{ number_format($result->candidate_count) }}</td><td>{{ $result->has_warning ? 'Needs review †' : 'No unresolved data note' }}</td></tr>@endforeach
</tbody></table></div>
{{ $results->links() }}
@endif
@endsection
