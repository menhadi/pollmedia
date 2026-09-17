@extends('seo-layout')
@section('content')
<p class="muted">POLLMEDIA ADMINISTRATION</p><h1>Dashboard</h1>
<p>Manage official data, review updates and maintain your public pages.</p>
<div class="dashboard-grid">
@foreach($counts as $label => $count)
<section class="card"><h2>{{ number_format($count) }}</h2><p>{{ $label }}</p></section>
@endforeach
</div>
<section class="card"><h2>Manage Pollmedia</h2><div class="actions">
<a class="button" href="{{ route('imports.index') }}">Data imports</a>
<a class="button" href="{{ route('election-imports.index') }}">Election archives</a>
<a class="button" href="{{ route('census.archive') }}">Census archives</a>
<a href="{{ route('seo.index') }}">Page SEO</a><a href="{{ route('ai.settings') }}">AI settings</a><a href="{{ route('admin.account') }}">Your account</a>
</div></section>
<section class="card"><h2>Official directory checks</h2>
<p class="muted">Latest recorded checks for monitored directories. Changes require review before published officeholders are replaced. Dates are UTC.</p>
@forelse($checks as $check)
<div class="row"><strong>{{ \Illuminate\Support\Str::headline($check->name) }}</strong><p>{{ ['baseline' => 'Baseline recorded', 'unchanged' => 'No change from baseline', 'changed' => 'Changed — review needed', 'failed' => 'Check failed — retry needed'][$check->status] ?? 'Not checked yet' }} · {{ $check->checked_at ?? 'No check recorded' }}</p>
<a href="{{ $check->url }}" target="_blank" rel="noopener noreferrer">Official source ↗</a></div>
@empty<p>No monitored directories are registered yet.</p>@endforelse
<p><a href="{{ route('sources.index') }}">Source evidence and update history</a></p></section>
<section class="card"><h2>Recent imports</h2>
@forelse($runs as $run)
<div class="row"><a href="{{ route('imports.run', $run->id) }}">{{ $run->name }}</a><p>{{ ucfirst(str_replace('_', ' ', $run->status)) }} · {{ $run->created_at }} UTC</p></div>
@empty<p>No import runs yet. <a href="{{ route('imports.index') }}">Add an official source</a> to begin.</p>@endforelse
</section>
@endsection
