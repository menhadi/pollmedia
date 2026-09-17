@extends('seo-layout')
@section('content')
<a href="{{ route('authorities.index') }}">Back to authority review</a>
<h1>Officeholder history</h1>
<p>Published assignments and their official evidence. Replacement dates record when an observation was superseded; they are not inferred tenure end dates. All timestamps are UTC.</p>
<form method="get" class="card filters">
<div><label for="office">Office</label><select id="office" name="office"><option value="">All offices</option>
@foreach($offices as $item)<option value="{{ $item->id }}" @selected((string) $office === (string) $item->id)>{{ $item->title }} — {{ \Illuminate\Support\Str::headline($item->key) }}</option>@endforeach
</select></div><button>Filter history</button><a href="{{ route('authorities.history') }}">Clear</a></form>
<p>{{ number_format($history->total()) }} recorded assignments.</p>
@forelse($history as $entry)
<article class="card"><h2>{{ $entry->title }}</h2><p class="muted">{{ \Illuminate\Support\Str::headline($entry->office_key) }}</p>
<h3>{{ $entry->display_name ?? 'No named officeholder' }}</h3>
<p><strong>{{ $entry->superseded_at ? 'Historical observation' : 'Currently published observation' }}</strong> · {{ ucfirst(str_replace('_', ' ', $entry->status)) }}</p>
<p>Verified {{ $entry->verified_at }}@if($entry->superseded_at) · Superseded {{ $entry->superseded_at }}@endif</p>
<p>Tenure start: {{ $entry->effective_from ?? 'Not recorded' }} · Tenure end: {{ $entry->effective_to ?? 'Not recorded' }}</p>
<a href="{{ $entry->url }}" target="_blank" rel="noopener noreferrer">Official evidence ↗</a>
@if($entry->sha256)<details><summary>Evidence fingerprint</summary><p class="url">SHA-256: {{ $entry->sha256 }}</p></details>@endif
@if($entry->reviewed_at)<section><h3>Publication review</h3><p>{{ $entry->reviewer_name }} · {{ $entry->reviewed_at }} UTC</p><p style="white-space:pre-wrap">{{ $entry->note }}</p></section>
@else<p class="muted">Imported evidence; no publication review was recorded through this workflow.</p>@endif
</article>
@empty<p>No assignments match this office.</p>@endforelse
{{ $history->links() }}
@endsection
