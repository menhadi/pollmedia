@extends('geography-layout')
@section('title', 'India historical Census population')
@section('content')
<h1>India historical Census population</h1>
<p>Official A-02 population figures for India and states / union territories, including all years recorded in the workbook.</p>
<p class="notice">{{ $data['source']['boundary_basis'] }} These are historical figures on the source's geographic basis, not today's boundaries. India and state totals overlap and must not be added together.</p>
<p><a href="{{ route('census-catalogue.index') }}">Explore other Census tables</a></p>
<form method="get" class="card filters"><div><label for="state">India / state / union territory</label><select id="state" name="state"><option value="">All recorded areas</option>@foreach($states as $option)<option value="{{ $option['state_code'] }}" @selected($state === $option['state_code'])>{{ $option['name'] }}</option>@endforeach</select></div>
<div><label for="year">Year as recorded</label><select id="year" name="year"><option value="">All historical years</option>@foreach($years as $option)<option value="{{ $option }}" @selected($year === $option)>{{ $option }}</option>@endforeach</select></div><button>Show records</button></form>
<section class="card"><h2>{{ $rows->count() }} source records</h2><div class="scroll"><table><thead><tr><th>Area</th><th>Year</th><th>Persons</th><th>Males</th><th>Females</th><th>Absolute change (source)</th><th>Percentage change (source)</th><th>Source row</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td>{{ $row['name'] }}</td><td>{{ $row['year_label'] }} @if($row['flags'])<a href="#note-{{ $row['source_row'] }}" aria-label="Read source notes">&#8224;</a>@endif</td>@foreach(['persons','males','females'] as $field)<td>{{ $row[$field] === null ? 'Not reported' : number_format($row[$field]) }}</td>@endforeach<td>{{ $row['variation'] }}</td><td>{{ $row['percentage'] }}</td><td>{{ $row['source_row'] }}</td></tr>@endforeach
</tbody></table></div>
<h3>&#8224; Notes for displayed records</h3><p>Source values are retained even when flagged. Missing values are not zero. Original year markers refer to the official footnotes below.</p>
@foreach($rows->filter(fn ($row) => count($row['flags']) > 0) as $row)<p id="note-{{ $row['source_row'] }}"><strong>{{ $row['name'] }}, {{ $row['year'] }} (row {{ $row['source_row'] }})</strong>: {{ implode(' ', $row['flags']) }}</p>@endforeach
</section>
<section class="card"><h2>Official source footnotes</h2>@foreach($data['notes'] as $note)<p>{{ $note['text'] }}</p>@endforeach</section>
<section class="card"><h2>Official evidence</h2><p><a href="{{ $data['source']['landing'] }}">Census catalogue</a> &middot; <a href="{{ $data['source']['url'] }}">Original Excel workbook</a> &middot; <a href="{{ config('census-sources.india-decadal-1901-2011-pdf.url') }}">Official PDF</a></p><p>Sheet {{ $data['sheet'] }}. Source retrieved {{ $data['source']['retrieved_at'] }}. Row references point to the original workbook.</p></section>
@endsection
