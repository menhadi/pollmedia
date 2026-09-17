@extends('geography-layout')
@section('title', $indiaContext ? 'India historical data' : 'Historical data')
@section('content')
<h1>{{ $indiaContext ? 'India historical data' : 'Historical data' }}</h1>
<p>Browse available measurements with their original periods, units and official sources. Dropdowns show available data only.</p>
<form method="get" class="filters card">
@if(!$indiaContext)<div><label for="country">Country</label><select id="country" name="country"><option value="">All recorded countries</option>@foreach($countries as $value)<option @selected($country === $value)>{{ $value }}</option>@endforeach</select></div>@endif
<div><label for="place">Place</label><select id="place" name="place"><option value="">All available places</option>@foreach($places as $item)<option value="{{ $item->id }}" @selected((string) $place === (string) $item->id)>{{ $item->name }} · {{ $item->type }}</option>@endforeach</select></div>
<div><label for="indicator">Indicator</label><select id="indicator" name="indicator"><option value="">All indicators</option>@foreach($indicators as $item)<option value="{{ $item->id }}" @selected((string) $indicator === (string) $item->id)>{{ $item->label }} ({{ $item->unit }})</option>@endforeach</select></div>
<div><label for="period">Source period</label><select id="period" name="period"><option value="">All available periods</option>@foreach($periods as $value)<option @selected($period === $value)>{{ $value }}</option>@endforeach</select></div>
<button>Show data</button><a href="{{ route($indiaContext ? 'indicators.india' : 'indicators.index') }}">Clear</a>
</form>
<p class="notice">Figures from different boundaries, definitions or source editions may not be comparable. This browser does not add overlapping places or turn missing values into zero. An older source period is not a current estimate.</p>
<p>{{ number_format($measurements->total()) }} measurement records.</p>
<div class="card scroll"><table><thead><tr><th>Place</th><th>Indicator</th><th>Period</th><th>Value</th><th>Official evidence</th></tr></thead><tbody>
@forelse($measurements as $row)<tr><td><a href="{{ route('geography.show', $row->slug) }}">{{ $row->name }}</a><br>{{ $row->country_code }} · {{ $row->type }}</td>
<td>{{ $row->label }}<details><summary>Definition</summary>{{ $row->definition }}<p>{{ \Illuminate\Support\Str::headline($row->evidence_class) }}</p></details></td>
<td>{{ $row->period }}</td><td>{{ $row->value === null ? 'Not available' : $row->value }}@if($row->value !== null) {{ $row->unit }}@endif @if($row->release_status !== 'accepted' || $row->observation_status !== 'reported')<a href="#note-{{ $row->id }}" aria-label="Data note">&#8224;</a>@endif</td>
<td><a href="{{ $row->url }}">{{ $row->publisher }}</a><br>{{ $row->source_locator }}<br>Release {{ $row->release_id }}<br>Retrieved {{ $row->retrieved_at }} UTC</td></tr>
@empty<tr><td colspan="5">No measurements match these filters. Additional historical periods appear when official data is extracted.</td></tr>@endforelse
</tbody></table></div>{{ $measurements->links() }}
<section class="card"><h2>&#8224; Source and review notes</h2>
@foreach($measurements as $row)
@if($row->release_status !== 'accepted' || $row->observation_status !== 'reported')<p id="note-{{ $row->id }}"><strong>{{ $row->name }} / {{ $row->label }} / {{ $row->period }}:</strong>
@if($row->release_status === 'superseded')Earlier source edition, retained as historical evidence.@elseif($row->release_status !== 'accepted')Source edition awaiting review; the recorded value is displayed unchanged.@endif
@if($row->observation_status !== 'reported')This measurement requires review.@endif
Release {{ $row->release_id }}. Refer to the official evidence in the table.</p>@endif
@endforeach
<p>Missing values are not zero. Review status does not by itself establish that a source value is incorrect.</p></section>
@endsection
