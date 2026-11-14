@extends('local-board-layout')
@section('title', ($area ? $area['name'].' · ' : '').'Local jobs & services')
@section('board-content')
<header class="board-heading"><h1>{{ $area ? $area['name'].' · ' : '' }}Jobs & services</h1>@if($area)<a href="{{ $area['url'] }}">Place overview →</a>@endif</header>
<form method="get" action="{{ route('local-board.index') }}" class="board-panel">
@include('local-board-picker')
<div class="board-fields">
<label>Listing type<select name="kind"><option value="">Jobs & services</option><option value="job" @selected(request('kind') === 'job')>Jobs</option><option value="service" @selected(request('kind') === 'service')>Services</option></select></label>
<label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Services\LocalBoard::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(request('category') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Search<input name="q" value="{{ request('q') }}" maxlength="100" placeholder="Job, skill or service"></label>
<button type="submit">Show listings</button>
</div>
</form>
<div class="board-section-heading"><h2>{{ $listings->total() }} active {{ \Illuminate\Support\Str::plural('listing', $listings->total()) }}</h2><a href="{{ route('local-board.create', $area ? ['area' => $area['key']] : []) }}">Post a listing →</a></div>
@forelse($listings as $listing)
<article class="board-row">
<div><span class="board-kind">{{ $listing->kind === 'job' ? 'Job' : 'Service' }}</span><h3><a href="{{ route('local-board.show', $listing->id) }}">{{ $listing->title }}</a></h3><p>{{ $listing->organisation }}</p><p><a href="{{ route('local-board.index', ['area' => $listing->primary_area_key]) }}">{{ $listing->area_name }}</a> · {{ $listing->area_kind === 'gp' ? 'Gram Panchayat' : ucfirst($listing->area_kind) }}</p></div>
<div><span class="board-label">{{ $listing->kind === 'job' ? 'Pay' : 'Price / terms' }}</span><strong>{{ $listing->pay_text ?: 'Contact for details' }}</strong></div>
<div><span class="board-badge">Reviewed listing</span><span class="board-label">Expires {{ \Illuminate\Support\Carbon::parse($listing->expires_at)->setTimezone('Asia/Kolkata')->format('d M Y') }}</span><a href="{{ route('local-board.show', $listing->id) }}">Details & contact →</a></div>
</article>
@empty<div class="board-panel board-empty"><h2>No active listings here yet</h2><p>Be the first to post a local job or service. New submissions are reviewed before publication.</p><a class="board-button" href="{{ route('local-board.create', $area ? ['area' => $area['key']] : []) }}">Post a job or service</a></div>@endforelse
@include('local-board-pagination')
<details class="board-panel board-notes"><summary>Verification & expiry</summary><p>The poster must confirm their email, consent to public contact details and submit the listing for administrator review. “Reviewed listing” describes the listing review, not a government endorsement or a guarantee of employment.</p><p>Only approved, unexpired listings appear publicly. A village board can include whole-Panchayat and whole-district opportunities; each listing shows its actual service area. Editing a listing requires a fresh review. Community issues remain separate.</p></details>
@endsection
