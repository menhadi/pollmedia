@extends($admin ? 'seo-layout' : 'geography-layout')
@section('title', $record->title)
@section('content')
<p><a href="{{ route($admin ? 'issues.queue' : 'issues.index') }}">Citizen issues</a></p><h1>{{ $record->title }}</h1>
<p class="notice">Citizen report · {{ \Illuminate\Support\Str::headline($record->status) }}. Publication is not official verification. Resolution is recorded by an administrator and is not citizen-confirmed.</p>
<section class="card"><p>{{ ucfirst($record->category) }} · Submitted {{ $record->created_at }} UTC</p>
@foreach($places as $area)<p><a href="{{ route('geography.show', $area->slug) }}">{{ $area->name }}</a> · {{ $area->country_code }} · {{ $area->type }}</p>@endforeach
<p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $record->description }}</p>
@if($record->evidence_url)<p><a href="{{ $record->evidence_url }}" rel="nofollow ugc noreferrer">Submitter's evidence link (external, unverified)</a></p>@endif
<p class="muted">Reference {{ $record->id }}</p></section>
<section class="card"><h2>Status history</h2>@forelse($events as $event)<div class="row"><p>{{ $event->created_at }} UTC · {{ \Illuminate\Support\Str::headline($event->to_status) }}</p><p style="white-space:pre-wrap">{{ $event->note }}</p></div>@empty<p>No review decisions yet.</p>@endforelse</section>
@if($admin)
<section class="card"><h2>Review decision</h2><p>Notes for published statuses are public. Rejection notes remain private. Reject reports that contain private information or need correction.</p>
<form method="post" action="{{ route('issues.moderate', $record->id) }}">@csrf<input type="hidden" name="revision" value="{{ $record->revision }}">
<p><label for="status">New status</label><select name="status" id="status">@foreach(['open' => 'Publish / reopen', 'in_progress' => 'In progress', 'resolved' => 'Resolved (admin reported)', 'rejected' => 'Reject / remove from public view'] as $value => $label)@if(in_array($value, $nextStatuses, true))<option value="{{ $value }}">{{ $label }}</option>@endif@endforeach</select></p>
<p><label for="note">Reason and supporting information</label><textarea name="note" id="note" rows="4" minlength="15" maxlength="2000" required>{{ old('note') }}</textarea></p><button>Save decision</button></form></section>
@endif
@endsection
