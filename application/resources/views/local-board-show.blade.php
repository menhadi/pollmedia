@extends('local-board-layout')
@section('title', $entry->title)
@section('board-content')
<article class="board-panel">
<p class="board-kind">{{ $entry->kind === 'job' ? 'Job vacancy' : 'Service offered' }}</p><h1>{{ $entry->title }}</h1>
<p>{{ $entry->organisation }} · {{ \App\Services\LocalBoard::CATEGORIES[$entry->category] }}</p>
@if($active)<p><span class="board-badge">Reviewed listing</span> · Poster email confirmed</p>@else<p class="board-notice">{{ \Illuminate\Support\Carbon::parse($entry->expires_at)->isPast() ? 'Expired' : ucfirst($entry->status) }} · Not publicly listed</p>@endif
<div class="board-description">{{ $entry->description }}</div>
<dl class="board-facts"><div><dt>Location</dt><dd><a href="{{ route('local-board.index', ['area' => $entry->primary_area_key]) }}">{{ $entry->area_name }}</a></dd></div><div><dt>{{ $entry->kind === 'job' ? 'Pay' : 'Price / terms' }}</dt><dd>{{ $entry->pay_text ?: 'Contact for details' }}</dd></div><div><dt>Expires</dt><dd>{{ \Illuminate\Support\Carbon::parse($entry->expires_at)->setTimezone('Asia/Kolkata')->format('d M Y') }}</dd></div></dl>
<section class="board-contact"><h2>Contact</h2><p>{{ $entry->contact_name }}</p>@if($entry->contact_phone)<a class="board-button" href="tel:{{ preg_replace('/[^+0-9]/', '', $entry->contact_phone) }}">Call {{ $entry->contact_phone }}</a>@endif @if($entry->contact_email)<a class="board-button board-secondary" href="mailto:{{ $entry->contact_email }}">{{ $entry->contact_email }}</a>@endif</section>
@if($owner && $entry->review_message)<p class="board-notice">{{ $entry->review_message }}</p>@endif
@if($owner)<nav class="board-actions" aria-label="Manage listing"><a href="{{ route('local-board.edit', $entry->id) }}">Edit / renew</a>@if($entry->status !== 'closed')<form method="post" action="{{ route('local-board.close', $entry->id) }}">@csrf<input type="hidden" name="revision" value="{{ $entry->revision }}"><button type="submit">Close listing</button></form>@endif</nav>@endif
</article>
<section class="board-panel"><h2>Linked places</h2><ul class="board-place-links">@foreach($scopes as $scope)<li><a href="{{ route('local-board.index', ['area' => $scope->area_key]) }}">{{ $scope->name }}</a> · {{ $scope->kind === 'gp' ? 'Gram Panchayat' : ucfirst($scope->kind) }}</li>@endforeach</ul></section>
<details class="board-panel board-notes"><summary>About this listing</summary><p>Details and terms are provided by the poster. Contact them directly to confirm availability and terms. Listing review is not government approval. It expires automatically and can be closed earlier by the poster.</p><a href="{{ route('feedback.create', ['path' => route('local-board.show', $entry->id, false)], false) }}">Report a concern</a></details>
@endsection
