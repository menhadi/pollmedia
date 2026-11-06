@php
$supports = DB::table('citizen_issue_supports')->where('issue_id', $record->id)->where('direction',1)->count();
$contributions = DB::table('citizen_issue_contributions')->where('issue_id', $record->id)->when(!$admin, fn($q)=>$q->where('status','published')->where('kind','!=','flag'))->orderByDesc('id')->paginate(20);
@endphp
@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="card"><h2>Support this issue</h2><p>{{ $supports }} support signals · counted once per browser session, not verified people.</p>
@if(!$admin)<form method="post" action="{{ route('issues.support',$record->id) }}">@csrf<button name="support" value="1">I face this too / support this issue</button> <button name="support" value="0">Remove my support</button></form>@endif
</section>
<section class="card"><h2>Connect a government complaint</h2><p>Keep one shared issue here. Submit through the appropriate official portal, then add its acknowledgement and updates below. Supporting this issue does not send another government complaint. Automatic government submission and status sync are not connected.</p>
@foreach(config('grievance_portals') as $portal)<p><strong><a href="{{ $portal['url'] }}" target="_blank" rel="noopener noreferrer">{{ $portal['name'] }} ↗</a></strong> — {{ $portal['scope'] }}</p>@endforeach
<p>Do not post phone numbers, OTPs or private documents. Complaint reference numbers are encrypted and visible only to reviewers. An official “disposed” status does not mean residents confirm the problem is fixed.</p></section>
<section class="card"><h2>Community evidence and official updates</h2>
@forelse($contributions as $entry)<article class="row"><p><strong>{{ Str::headline($entry->kind) }}</strong> · {{ $entry->created_at }} UTC</p>
@if($entry->kind==='official_update')<p>{{ config('grievance_portals.'.$entry->portal.'.name') }} · {{ $entry->official_status }} · observed {{ $entry->observed_on }}.</p><p>{{ $entry->status==='published'?'Official record reviewed by Pollmedia; manually recorded, not automatically synced.':'Submitted claim awaiting verification.' }}</p>@endif
<p style="white-space:pre-wrap">{{ $entry->body }}</p>
@if($entry->evidence_url)<a href="{{ $entry->evidence_url }}" target="_blank" rel="ugc nofollow noopener noreferrer">Supporting evidence (external)</a>@endif
@if($admin)<p>Review state: {{ $entry->status }}</p>@if($entry->private_reference)<p>Private reference: {{ Crypt::decryptString($entry->private_reference) }}</p>@endif
<form method="post" action="{{ route('issues.contribution.review',[$record->id,$entry->id]) }}">@csrf
@if($entry->kind==='official_update')<label><input type="checkbox" name="verified" value="1"> I checked this reference, date and status against the official record and checked the public text for private information.</label>@endif
@if($entry->kind!=='flag')<button name="decision" value="published">Publish reviewed contribution</button>@endif <button name="decision" value="rejected">Reject / remove</button></form>@endif</article>
@empty<p>No {{ $admin?'submitted':'reviewed public' }} contributions yet.</p>@endforelse{{ $contributions->links() }}</section>
@if(!$admin)<section class="card"><h2>Add evidence or an update</h2><form method="post" action="{{ route('issues.contribute',$record->id) }}">@csrf
<p><label>Contribution type <select name="kind"><option value="evidence">Add evidence</option><option value="update">Community update</option><option value="official_update">Link official complaint / status update</option><option value="flag">Report misuse or duplicate (private)</option></select></label></p>
<p><label>Details <textarea name="body" minlength="20" maxlength="3000" required>{{ old('body') }}</textarea></label></p>
<p><label>Public evidence link (HTTPS) <input type="url" name="evidence_url" value="{{ old('evidence_url') }}"></label></p>
<details><summary>Official complaint details — required for an official update</summary>
<label>Government portal <select name="portal"><option value="">Choose portal</option>@foreach(config('grievance_portals') as $key=>$portal)<option value="{{ $key }}">{{ $portal['name'] }}</option>@endforeach</select></label>
<label>Reference number (private) <input name="reference" maxlength="160" autocomplete="off"></label>
<label>Exact official status <input name="official_status" maxlength="120"></label>
<label>Date shown on official record <input type="date" name="observed_on" max="{{ now()->toDateString() }}"></label></details>
<p><label><input type="checkbox" name="consent" value="1" required> My text and evidence link may be published after review; misuse reports and reference numbers stay private.</label></p><button>Submit contribution for review</button></form></section>@endif
