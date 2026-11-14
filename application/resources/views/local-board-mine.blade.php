@extends('local-board-layout')
@section('title', 'My jobs & services')
@section('board-content')
<h1>My listings</h1>
@forelse($listings as $listing)<article class="board-row"><div><h2><a href="{{ route('local-board.show', $listing->id) }}">{{ $listing->title }}</a></h2><p>{{ $listing->area_name }} · {{ $listing->kind === 'job' ? 'Job' : 'Service' }}</p></div><div>@if($listing->review_message)<p>{{ $listing->review_message }}</p>@endif<strong>{{ \Illuminate\Support\Carbon::parse($listing->expires_at)->isPast() ? 'Expired' : ucfirst($listing->status) }}</strong><p>Expires {{ \Illuminate\Support\Carbon::parse($listing->expires_at)->setTimezone('Asia/Kolkata')->format('d M Y') }}</p></div><div><a href="{{ route('local-board.edit', $listing->id) }}">Edit / renew</a>@if($listing->status !== 'closed')<form method="post" action="{{ route('local-board.close', $listing->id) }}">@csrf<input type="hidden" name="revision" value="{{ $listing->revision }}"><button class="board-secondary" type="submit">Close listing</button></form>@endif</div></article>@empty<div class="board-panel"><p>You have not posted any listings yet.</p><a class="board-button" href="{{ route('local-board.create') }}">Post a job or service</a></div>@endforelse
@include('local-board-pagination')
@endsection
