@extends('geography-layout')
@section('title', 'India Assembly election source archive')
@section('content')
<h1>India Assembly election source archive</h1>
<p>Official state/year report editions listed by ECI. Historical state names are preserved as recorded, including states that no longer exist.</p>
<p><a href="{{ route('elections.assembly') }}">Browse extracted Assembly result tables</a> &middot; <a href="{{ $catalogue['landing_url'] }}">Official ECI catalogue</a></p>
<form method="get" class="card filters"><div><label for="state">State as recorded</label><select name="state" id="state"><option value="">All states</option>@foreach($states as $option)<option @selected($state === $option)>{{ $option }}</option>@endforeach</select></div><div><label for="year">Election year</label><select id="year" name="year"><option value="">All years</option>@foreach($years as $option)<option @selected($year === $option)>{{ $option }}</option>@endforeach</select></div><button>Show reports</button></form>
<p>{{ $entries->count() }} report editions. Catalogue retrieved {{ $catalogue['retrieved_at'] }}.</p>
<section class="card scroll"><table><thead><tr><th>State</th><th>Year</th><th>Official report</th><th>Local collection</th></tr></thead><tbody>@foreach($entries as $entry)<tr><td>{{ $entry['state'] }}</td><td>{{ $entry['label'] }}</td><td><a href="{{ $entry['url'] }}">Open official edition</a>@if($entry['extraction'])<br><a href="{{ route('elections.assembly', ['edition' => $entry['extraction']]) }}">View extracted results</a>@endif</td><td><a href="{{ route('elections.assembly-source-files', $entry['archive']) }}">{{ $entry['collected'] }} archived files</a> &middot; {{ str_replace('_', ' ', $entry['status']) }}</td></tr>@endforeach</tbody></table></section>
<p class="notice">A listed report is not necessarily downloaded or extracted. File counts describe this installation. Missing result tables are not zero results. Historical boundaries must be checked before comparing constituencies across years.</p>
@endsection
