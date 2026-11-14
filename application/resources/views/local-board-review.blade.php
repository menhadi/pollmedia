@extends('seo-layout')
@section('title', 'Review local jobs & services')
@section('content')
<h1>Jobs & services · review</h1>
@if($errors->any())<div role="alert" class="notice error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif<p>Check the poster, location, contact and terms before approving. Review notes remain private.</p>
<nav class="actions">@foreach(['pending', 'approved', 'rejected', 'closed', 'expired'] as $option)<a class="button" href="{{ route('local-board.review', ['status' => $option]) }}">{{ ucfirst($option) }}</a>@endforeach</nav>
@forelse($listings as $listing)<article class="card">
<h2>{{ $listing->title }}</h2><p>{{ $listing->kind }} · {{ $listing->organisation }} · {{ $listing->area_name }}</p><p style="white-space:pre-wrap">{{ $listing->description }}</p><p>Terms: {{ $listing->pay_text ?: 'Not stated' }}</p><p>Poster account: {{ $listing->poster_email }} · {{ $listing->poster_verified_at ? 'Email confirmed' : 'Email not confirmed' }}</p><p>Public contact: {{ $listing->contact_name }} · {{ $listing->contact_phone }} · {{ $listing->contact_email }}</p><p>Expires: {{ $listing->expires_at }} UTC · Revision {{ $listing->revision }}</p>
@if($listing->status === 'pending' && \Illuminate\Support\Carbon::parse($listing->expires_at)->isFuture())
<form method="post" action="{{ route('local-board.decide', $listing->id) }}">@csrf<input type="hidden" name="revision" value="{{ $listing->revision }}">
<label>Verification method<select name="verification_method"><option value="">Choose a method for approval</option>@foreach(\App\Services\LocalBoard::METHODS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
<label>Message to poster (required for rejection)<textarea name="review_message" minlength="10" maxlength="500"></textarea></label>
<label>Private review note<textarea name="review_note" minlength="10" maxlength="2000" required></textarea></label><div class="actions"><button name="decision" value="approved">Approve & publish</button><button class="secondary" name="decision" value="rejected">Reject</button></div></form>
@else<p>Status: {{ $listing->status }}</p>@endif
<details><summary>Review history</summary>@foreach(\Illuminate\Support\Facades\DB::table('local_board_events')->where('listing_id', $listing->id)->orderBy('id')->get() as $event)<p>{{ $event->created_at }} UTC · {{ $event->action }} · {{ $event->from_status }} → {{ $event->to_status }}<br>{{ $event->verification_method }} · {{ $event->note }}</p>@endforeach</details>
</article>@empty<p>No {{ $status }} listings.</p>@endforelse
{{ $listings->links() }}
@endsection
