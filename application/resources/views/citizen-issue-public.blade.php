@php
$locationRow = DB::table('citizen_issue_locations')->where('issue_id',$record->id)->first();
$locationSnapshot = $locationRow ? json_decode($locationRow->snapshot,true) : null;
$issueMedia = DB::table('citizen_issue_media')->where('issue_id',$record->id)->get();
$supports = DB::table('citizen_issue_supports')->where('issue_id',$record->id)->where('direction',1)->count();
$publicUpdates = DB::table('citizen_issue_contributions')->where('issue_id',$record->id)->where('status','published')->where('kind','!=','flag')->orderByDesc('id')->get();
$officialUpdates = $publicUpdates->where('kind','official_update');
$communityUpdates = $publicUpdates->where('kind','!=','official_update');
$sampleReport = in_array($record->id, ['01M5T4FFN9DYE9RE24H7P6AZ61','01K8A000000000000000000001'], true);
$scopeState = collect($locationSnapshot['scopes'] ?? [])->firstWhere('kind', 'state')['name'] ?? null;
$scopeUrl = function (array $scope) use ($scopeState) {
    $kind = $scope['kind'] ?? '';
    if ($kind === 'village' && preg_match('/^village:([0-9]{1,8})$/', $scope['key'], $match)) {
        return route('electoral-villages.village', $match[1]);
    }
    if ($kind === 'state') {
        return route('states.show', Str::slug($scope['name']));
    }
    if ($scopeState && $kind === 'district') {
        return route('district-dashboard.named', ['state' => Str::slug($scopeState), 'district' => Str::slug($scope['name'])]);
    }
    if ($scopeState && in_array($kind, ['ac', 'pc'], true)) {
        return route('constituency.overview', ['kind' => $kind, 'state' => $scopeState, 'name' => $scope['name']]);
    }
    return null;
};
app(\App\Services\IssuePostCards::class)->prepare([$record]);
$issueAreaKey = DB::table('citizen_issue_scopes')->where('issue_id', $record->id)->orderByRaw("CASE kind WHEN 'village' THEN 0 WHEN 'locality' THEN 1 WHEN 'district' THEN 2 WHEN 'state' THEN 3 ELSE 4 END")->value('area_key');
$participant = session('issue_participant');
$isSupporting = $participant && DB::table('citizen_issue_supports')->where('issue_id',$record->id)->where('participant_hash',hash_hmac('sha256',$participant,config('app.key')))->exists();
@endphp
<link rel="stylesheet" href="{{ asset('css/citizen-issue-public.css') }}?v={{ substr(hash_file('sha256',public_path('css/citizen-issue-public.css')),0,12) }}">
<link rel="stylesheet" href="{{ asset('css/issue-post.css') }}?v={{ substr(hash_file('sha256', public_path('css/issue-post.css')),0,12) }}"><script src="{{ asset('js/issue-post.js') }}" defer></script>
<div class="issue-page">
<a class="issue-back" href="{{ route('issues.index', ['area'=>$issueAreaKey, 'reports'=>1, 'lang'=>app()->getLocale()]) }}">← Community issues</a>
<div class="issue-intro place-page-heading">@include('location-breadcrumbs',['locationTrail'=>app(\App\Services\PlaceBreadcrumbs::class)->issue($locationSnapshot['scopes']??[])])<p class="issue-eyebrow">{{ ucfirst($record->category) }} <span>· {{ Str::headline($record->status) }}</span></p><h1>{{ $record->id === '01M5T4FFN9DYE9RE24H7P6AZ61' ? 'Repair the damaged village road' : $record->title }}</h1><p class="issue-muted">{{ $locationSnapshot['name'] ?? 'Reported problem' }} · {{ substr($record->created_at,0,10) }}</p></div>
@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="notice">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div class="issue-layout"><div class="issue-main">
<section class="card issue-post issue-problem" aria-labelledby="problem-heading">@include('issue-post-author', ['post'=>$record])
<div class="post-places" aria-label="Problem places">@foreach($record->placeLinks as $link)<a href="{{ $link['url'] }}">#{{ ucfirst($link['kind']) }}_{{ preg_replace('/\s+/u', '', $link['name']) }}</a>@endforeach</div>
<h2 id="problem-heading">The problem</h2><p class="issue-description" dir="auto">{{ $record->id === '01M5T4FFN9DYE9RE24H7P6AZ61' ? 'The damaged road makes daily travel difficult, especially for children and older residents. Please repair the surface and improve drainage before the next rains.' : $record->description }}</p>
@if($record->evidence_url)<a href="{{ $record->evidence_url }}" rel="ugc nofollow noopener noreferrer" target="_blank">Reporter’s evidence ↗ (unverified)</a>@endif
<h3>Photos & videos</h3><div class="issue-media">@if($record->id === '01M5T4FFN9DYE9RE24H7P6AZ61' && is_file(public_path('media/road-preview.mp4')))<video controls muted playsinline preload="metadata" aria-label="Road improvement animation" src="{{ asset('media/road-preview.mp4') }}"></video>@else @forelse($issueMedia as $media)
@if(str_starts_with($media->mime,'image/'))<a href="{{ route('issues.media',[$record->id,$media->id]) }}" target="_blank" rel="noopener"><img src="{{ route('issues.media',[$record->id,$media->id]) }}" alt="{{ $sampleReport ? 'Illustrative sample image, not evidence of this location' : 'Photo supplied by the reporter' }}" loading="lazy"></a>@else<video controls preload="metadata" src="{{ route('issues.media',[$record->id,$media->id]) }}"></video>@endif
@empty<p class="issue-empty">No photos or videos attached. You can add an evidence link below.</p>@endforelse @endif</div>

@include('issue-post-actions', ['post'=>$record])

<details class="post-disclaimer"><summary>About this report</summary><p>{{ $supports }} support signals · counted once per browser session, not verified people.</p><p>Citizen report, not official verification. Resolution is administrator-recorded and not citizen-confirmed. Publication does not send a government complaint.</p>@if($sampleReport)<p>Layout test only, not a real complaint. Media is illustrative and does not depict or document this location.</p>@endif</details></section>
<nav class="issue-primary-actions" aria-label="Problem actions"><a class="issue-button" href="#add-evidence">Add evidence</a><a class="issue-button secondary" href="{{ route('issues.index') }}">Add another problem</a><a class="issue-button secondary" href="#government-timeline">Government status ↓</a></nav>
<section class="card" id="government-timeline"><div class="issue-section-heading"><h2>Government status timeline</h2><a href="#government-details">Add official update ↓</a></div><p class="issue-muted">Manually recorded after review. Automatic government submission and status sync are not connected.</p>
<ol class="issue-timeline">
<li><strong>Published on Pollmedia</strong><time>{{ substr($record->created_at,0,10) }}</time><p>Publication alone does not lodge a government grievance.</p></li>
@forelse($officialUpdates->sortBy('observed_on') as $entry)<li><strong>{{ config('grievance_portals.'.$entry->portal.'.name') }} · {{ $entry->official_status }}</strong><time>{{ $entry->observed_on }}</time><p class="issue-description" dir="auto">{{ $entry->body }}</p><p class="issue-muted">Official record reviewed by Pollmedia; manually recorded, not automatically synced.</p>@if($entry->evidence_url)<a href="{{ $entry->evidence_url }}" target="_blank" rel="ugc nofollow noopener noreferrer">Supporting evidence ↗</a>@endif</li>
@empty<li class="issue-pending"><strong>No verified government status yet</strong><p>Submit through the appropriate portal, then add the acknowledgement and status below. Reference numbers stay private.</p></li>@endforelse
@foreach($responses as $response)<li><strong>{{ $response->title }}</strong><time>{{ $response->responded_on }}</time><p class="issue-description">{{ $response->summary }}</p><a href="{{ $response->source_url }}" target="_blank" rel="noopener noreferrer">Official response source ↗</a><p class="issue-muted">These are administrator-written summaries, not messages from authenticated officials.</p></li>@endforeach
</ol><p class="issue-muted">An official “disposed” status does not mean residents confirm the problem is fixed.</p>
<details class="issue-disclosure"><summary>How to submit to the government</summary><p>Copy this text, submit it on the relevant official portal, then return to link its reference and updates.</p><textarea readonly rows="5" aria-label="Complaint text for government portal">{{ $record->title }}
{{ $record->id === '01M5T4FFN9DYE9RE24H7P6AZ61' ? 'The damaged road makes daily travel difficult, especially for children and older residents. Please repair the surface and improve drainage before the next rains.' : $record->description }}
@foreach($locationSnapshot['scopes'] ?? [] as $scope){{ strtoupper($scope['kind']) }}: {{ $scope['name'] }}
@endforeach
Public report: {{ route('issues.show',$record->id) }}</textarea><div class="issue-portal-links">@foreach(config('grievance_portals') as $portal)<p><a href="{{ $portal['url'] }}" target="_blank" rel="noopener noreferrer">Open {{ $portal['name'] }} ↗</a> <small>{{ $portal['scope'] }}</small></p>@endforeach</div></details></section>
<section class="card"><h2>Community evidence & updates</h2>@forelse($communityUpdates as $entry)<article class="issue-update"><p class="issue-eyebrow">{{ Str::headline($entry->kind) }} · {{ substr($entry->created_at,0,10) }}</p><p class="issue-description" dir="auto">{{ $entry->body }}</p>@if($entry->evidence_url)<a href="{{ $entry->evidence_url }}" target="_blank" rel="ugc nofollow noopener noreferrer">Supporting evidence ↗</a>@endif</article>@empty<p class="issue-muted">No reviewed public contributions yet.</p>@endforelse</section>
<section class="card" id="add-evidence"><h2>Add evidence or an update</h2><p class="issue-muted">Help others understand the problem. Contributions are reviewed before appearing publicly.</p>
<form method="post" action="{{ route('issues.contribute',$record->id) }}">@csrf
<span id="report-concern"></span><label>Contribution type<select name="kind"><option value="evidence" @selected(old('kind')==='evidence')>Add evidence</option><option value="update" @selected(old('kind')==='update')>Community update</option><option value="official_update" @selected(old('kind')==='official_update')>Link official complaint / status update</option><option value="flag" @selected(old('kind')==='flag')>Report misuse or duplicate (private)</option></select></label>
<label>Details<textarea name="body" rows="4" minlength="20" maxlength="3000" required dir="auto">{{ old('body') }}</textarea></label><label>Public evidence link (HTTPS, optional)<input type="url" name="evidence_url" value="{{ old('evidence_url') }}"></label>
<details id="government-details" class="issue-disclosure" @if(old('kind')==='official_update') open @endif><summary>Official complaint details</summary><p>Choose “Link official complaint / status update” above and enter the details exactly as shown on the portal.</p><label>Government portal<select name="portal"><option value="">Choose portal</option>@foreach(config('grievance_portals') as $key=>$portal)<option value="{{ $key }}" @selected(old('portal')===$key)>{{ $portal['name'] }}</option>@endforeach</select></label><label>Reference number (private)<input name="reference" maxlength="160" autocomplete="off"></label><label>Exact official status<input name="official_status" maxlength="120" value="{{ old('official_status') }}"></label><label>Date shown on official record<input type="date" name="observed_on" max="{{ now()->toDateString() }}" value="{{ old('observed_on') }}"></label></details>
<label class="issue-consent"><input type="checkbox" name="consent" value="1" required> My text and evidence link may be published after review. References and misuse reports stay private.</label><button>Submit contribution for review</button></form></section>
<details class="card"><summary>Pollmedia review history & report reference</summary><p class="issue-muted">Reference {{ $record->id }}</p>@forelse($events as $event)<article><p>{{ $event->created_at }} UTC · {{ Str::headline($event->to_status) }}</p><p>{{ $event->note }}</p></article>@empty<p>No review decisions yet.</p>@endforelse</details>
</div><aside class="issue-sidebar" aria-label="Concerned authorities and location"><section class="card"><h2>Concerned authorities</h2>@forelse($authorities as $authority)<article class="issue-office"><h3>{{ $authority->title }}</h3><p>{{ $authority->reason }}</p>@if($availableOffices->contains('id',$authority->office_id))<a href="{{ $availableOffices->firstWhere('id',$authority->office_id)->url }}" target="_blank" rel="noopener">Official jurisdiction source ↗</a>@forelse($holders->get($authority->office_id,collect()) as $holder)<p>{{ $holder->display_name }}<small>Last verified {{ $holder->verified_at }} UTC · <a href="{{ $holder->url }}">Officeholder source</a></small></p>@empty<p class="issue-muted">No current verified officeholder recorded.</p>@endforelse @else<p class="notice">This office link needs review: no current accepted jurisdiction matches the report location.</p>@endif</article>@empty
@endforelse
@include('citizen-issue-directory-contacts')
</section>
<section class="card"><h2>Problem location</h2><strong>{{ $locationSnapshot['name'] ?? 'See linked places' }}</strong><div class="issue-area-links">@foreach($locationSnapshot['scopes'] ?? [] as $scope)@if($scopeUrl($scope))<a href="{{ $scopeUrl($scope) }}"><small>{{ strtoupper($scope['kind']) }}</small>{{ $scope['name'] }} →</a>@else<span><small>{{ strtoupper($scope['kind']) }}</small>{{ $scope['name'] }}</span>@endif @endforeach @foreach($places as $area)<a href="{{ route('geography.show',$area->slug) }}">{{ $area->name }}</a>@endforeach</div><p class="issue-muted">Selected or confirmed by the reporter. Photos and videos belong to this problem location.</p><details class="issue-disclosure"><summary>Reported from & location notes</summary><h3>Reported from</h3><p>{{ $locationSnapshot['reported_from']['name'] ?? 'Reporting location was not available or was not shared.' }}</p>@foreach($locationSnapshot['notes'] ?? [] as $note)<p>{{ $note }}</p>@endforeach</details></section></aside></div></div>
<script src="{{ asset('js/citizen-issue-public.js') }}" defer></script>
