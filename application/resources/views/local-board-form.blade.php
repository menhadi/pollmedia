@extends('local-board-layout')
@section('title', $listing ? 'Edit listing' : 'Post a job or service')
@section('board-content')
<h1>{{ $listing ? 'Edit listing' : 'Post a job or service' }}</h1>
<p>Confirm your location and contact details. Publication follows review.</p>
<form method="post" action="{{ $listing ? route('local-board.update', $listing->id) : route('local-board.store') }}" class="board-panel">
@csrf
@if($listing)<input type="hidden" name="revision" value="{{ $listing->revision }}">@endif
@include('local-board-picker')
<div class="board-fields">
<label>Listing type<select name="kind" required><option value="job" @selected(old('kind', $listing->kind ?? '') === 'job')>Job vacancy</option><option value="service" @selected(old('kind', $listing->kind ?? '') === 'service')>Service offered</option></select></label>
<label>Category<select name="category" required>@foreach(\App\Services\LocalBoard::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(old('category', $listing->category ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
</div>
<label>Title<input name="title" value="{{ old('title', $listing->title ?? '') }}" minlength="5" maxlength="140" required placeholder="For example: Farm workers needed in Jatipur"></label>
<label>Employer or service provider<input name="organisation" value="{{ old('organisation', $listing->organisation ?? '') }}" minlength="2" maxlength="120" required></label>
<label>Description<textarea name="description" rows="6" minlength="20" maxlength="3000" required placeholder="Describe the work or service, skills, working hours and location.">{{ old('description', $listing->description ?? '') }}</textarea></label>
<label>Pay / price and payment period<input name="pay_text" data-board-pay value="{{ old('pay_text', $listing->pay_text ?? '') }}" maxlength="120" placeholder="For example: ₹600 per day; paid weekly"></label>
<p class="board-hint">Required for job vacancies. State the amount, payment period or negotiable terms.</p>
<div class="board-fields">
<label>Contact name<input name="contact_name" value="{{ old('contact_name', $listing->contact_name ?? '') }}" minlength="2" maxlength="80" required></label>
<label>Phone<input name="contact_phone" value="{{ old('contact_phone', $listing->contact_phone ?? '') }}" type="tel" maxlength="24"></label>
<label>Contact email<input name="contact_email" value="{{ old('contact_email', $listing->contact_email ?? '') }}" type="email" maxlength="254"></label>
<label>Expiry date<input name="expires_on" value="{{ old('expires_on', $listing ? \Illuminate\Support\Carbon::parse($listing->expires_at)->setTimezone('Asia/Kolkata')->toDateString() : now('Asia/Kolkata')->addDays(30)->toDateString()) }}" type="date" min="{{ now('Asia/Kolkata')->toDateString() }}" max="{{ now('Asia/Kolkata')->addDays(60)->toDateString() }}" required></label>
</div>
<p class="board-hint">Provide at least one phone number or email address. Listings can run for up to 60 days.</p>
<label class="board-check"><input type="checkbox" name="contact_consent" value="1" required @checked(old('contact_consent'))> I am authorised to post this listing and agree that the contact name, phone and email entered above will be public after approval.</label>
<button type="submit">{{ $listing ? 'Save & send for review' : 'Submit for review' }}</button>
</form>
@endsection
