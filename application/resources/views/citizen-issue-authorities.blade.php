<section class="card"><h2>Linked offices</h2><p>Administrators select relevant offices using recorded jurisdictions. An office link does not mean the office has accepted responsibility or received this report.</p>
@forelse($authorities as $authority)
<div class="row"><h3>{{ $authority->title }} @if(!$authority->active)(link removed)@endif</h3><p>{{ $authority->reason }}</p>
@if($availableOffices->contains('id', $authority->office_id))
<p><a href="{{ $availableOffices->firstWhere('id', $authority->office_id)->url }}">Official jurisdiction source</a></p>
@forelse($holders->get($authority->office_id, collect()) as $holder)<p>{{ $holder->display_name }} · Last verified {{ $holder->verified_at }} UTC · <a href="{{ $holder->url }}">Officeholder source</a></p>@empty<p>No current verified officeholder is recorded.</p>@endforelse
@else<p class="notice">This office link needs review: no current accepted jurisdiction matches the report location.</p>@endif
@if($admin && $authority->active)
<form method="post" action="{{ route('issues.authority', $record->id) }}">@csrf<input type="hidden" name="office_id" value="{{ $authority->office_id }}"><input type="hidden" name="revision" value="{{ $record->revision }}"><input type="hidden" name="active" value="0"><label for="remove-{{ $authority->id }}">Reason for removing this link</label><input id="remove-{{ $authority->id }}" name="reason" minlength="20" maxlength="2000" required><button>Remove office link</button></form>
@endif
</div>
@empty<p>No office has been linked to this report.</p>@endforelse
@if($admin)
<h3>Link an office</h3><p>Only offices with a current accepted jurisdiction for the exact reported place are offered. Missing mappings must be imported and reviewed first.</p>
<form method="post" action="{{ route('issues.authority', $record->id) }}">@csrf<input type="hidden" name="revision" value="{{ $record->revision }}"><input type="hidden" name="active" value="1">
<p><label for="office_id">Office</label><select id="office_id" name="office_id" required>@foreach($availableOffices->unique('id') as $office)<option value="{{ $office->id }}">{{ $office->title }} · {{ $office->kind }}</option>@endforeach</select></p>
<p><label for="reason">Public explanation of relevance</label><textarea name="reason" id="reason" minlength="20" maxlength="2000" required>{{ old('reason') }}</textarea></p><button @disabled($availableOffices->isEmpty())>Save office link</button></form>
@endif
</section>
<section class="card"><h2>Recorded responses</h2><p>These are administrator-written summaries linked to official sources, not messages submitted by authenticated officials. They do not automatically resolve the report.</p>
@forelse($responses as $response)
<div class="row"><h3>{{ $response->title }}</h3><p>Response dated {{ $response->responded_on }} · Recorded {{ $response->created_at }} UTC @if(!$response->visible) · Hidden @endif</p><p style="white-space:pre-wrap">{{ $response->summary }}</p><p><a href="{{ $response->source_url }}" rel="nofollow noreferrer">Official response source</a></p>
@if($admin && $response->visible)<form method="post" action="{{ route('issues.response.hide', [$record->id, $response->id]) }}">@csrf<button>Hide response</button></form>@endif
</div>
@empty<p>No response has been recorded.</p>@endforelse
@if($admin)
<h3>Record a response from an official source</h3>
<form method="post" action="{{ route('issues.response', $record->id) }}">@csrf<input type="hidden" name="revision" value="{{ $record->revision }}">
<p><label for="authority_id">Linked office</label><select id="authority_id" name="authority_id" required>@foreach($authorities->where('active', true) as $authority)<option value="{{ $authority->id }}">{{ $authority->title }}</option>@endforeach</select></p>
<p><label for="responded_on">Response date</label><input type="date" id="responded_on" name="responded_on" max="{{ today()->toDateString() }}" value="{{ old('responded_on') }}" required></p>
<p><label for="source_url">Official source URL</label><input type="url" id="source_url" name="source_url" value="{{ old('source_url') }}" maxlength="2048" required></p>
<p><label for="summary">Public summary (include only information you checked against the source)</label><textarea id="summary" name="summary" rows="4" minlength="20" maxlength="3000" required>{{ old('summary') }}</textarea></p><button @disabled($authorities->where('active', true)->isEmpty())>Record response</button></form>
@endif
</section>
