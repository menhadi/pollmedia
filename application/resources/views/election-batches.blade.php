@extends('seo-layout')
@section('content')
<a href="{{ route('election-imports.index') }}">Back to election imports</a>
<h1>Statewide election imports</h1>
<p>Process a complete official report pair as one background batch. Candidate tables, elector counts and voting totals are checked for every constituency before review.</p>
<section class="card"><h2>Start a state batch</h2><form method="post" action="{{ route('election-batches.store') }}">@csrf
<div class="field"><label for="state">State</label><select id="state" name="state"><option value="uttar-pradesh">Uttar Pradesh</option></select></div>
<div class="field"><label for="year">Election edition</label><select id="year" name="year"><option value="2022">2022 Assembly election / 403 constituencies</option><option value="2017">2017 Assembly election / partial validated coverage</option><option value="2012">2012 Assembly election / PDF report</option></select></div>
<p>Uses the archived ECI detailed-results and constituency-summary workbooks. Their checksums must match the verified source edition. Repeating this request opens the existing batch.</p>
<button>Queue statewide extraction</button></form><p class="muted">Other states and years will appear as their source adapters become available. New constituency mappings need review before results can be published.</p></section>
<section class="card"><h2>Batch history</h2>@forelse($batches as $batch)<div class="row"><a href="{{ route('election-batches.show',$batch->id) }}">{{ $batch->state }} / {{ $batch->year }}</a> — {{ str_replace('_',' ',$batch->status) }}<p>{{ $batch->ready_count }} validated / {{ $batch->invalid_count }} need correction · {{ $batch->created_at }} UTC</p></div>@empty<p>No statewide batches yet.</p>@endforelse
{{ $batches->links() }}</section>
@endsection
