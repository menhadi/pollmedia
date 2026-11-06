@extends('geography-layout')
@section('title', 'Report a local issue')
@section('content')
<div class="issue-compose-page"><h1>Report a problem</h1>
<p>We’ll detect your location in the background. Report the problem there, or choose “Change location” if it is elsewhere.</p>
@if($errors->any())<div role="alert" class="notice">{{ $errors->first() }} Please confirm your location again; files must be reselected.</div>@endif
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
<section class="card report-unified">
<form method="post" enctype="multipart/form-data" action="{{ route('issues.located.store') }}">@csrf
<div id="issue-location"><h2>Report location</h2>
<p>Your detected place is used automatically. Your browser may ask permission. “Reported from” and “Problem location” will be public; exact coordinates stay private.</p>

<p role="status" id="issue-location-status"></p><div id="issue-location-choices"></div>
</div>
<div class="inline-problem-location"><div class="location-line"><p id="issue-problem-label">Reported from: detecting your location…</p><button type="button" id="issue-gps" hidden>Retry location</button></div>

<div class="location-change"><span>Want to report from somewhere else?</span><button type="button" id="issue-change-location" aria-controls="issue-other-location" aria-expanded="false">Change location</button></div><details id="issue-other-location"><summary class="location-summary">Choose the problem location</summary>
<p><label>State <select id="issue-state"><option value="">Choose a state</option></select></label></p>
<p><label>District <select id="issue-district" disabled><option value="">Choose a district</option></select></label></p>
<p><label>Village <select id="issue-village" disabled><option value="">Choose a village</option></select></label></p>
<p>Only places present in the imported directory are listed. An unavailable town or locality is not assigned to a nearby village.</p>
<button type="button" id="issue-confirm-problem" disabled>Confirm problem location</button><p role="status" id="issue-problem-status"></p></details>
</div>
<div id="issue-location-warning" hidden role="alert"><h2>The locations are different</h2><p id="issue-location-warning-text"></p><p>Both places will appear publicly. Do you want to proceed?</p><button type="button" id="issue-proceed-different">Yes, proceed with this problem location</button><button type="button" id="issue-cancel-different">Cancel — use my detected location</button></div>
<div id="issue-compose"><h2>Describe the issue</h2><p id="issue-confirmed-label"></p>

<input type="hidden" name="location_token" id="issue-location-token"><input type="hidden" name="location_confirmed" value="1">
<input type="hidden" name="reporter_token" id="issue-reporter-token">
<input type="hidden" name="different_location_confirmed" id="issue-different-confirmed" value="">
<p><label>Topic <select name="category">@foreach(['water','roads','sanitation','electricity','health','education','revenue','agriculture','welfare','transport','environment','other'] as $category)<option value="{{ $category }}" @selected(old('category') === $category)>{{ ucfirst($category) }}</option>@endforeach</select></label></p>
<p><label>Public display name (optional)<input name="display_name" maxlength="60" autocomplete="nickname" value="{{ old('display_name') }}" placeholder="Leave blank to post anonymously"></label></p>
<p><label>Short title — any language <input placeholder="A short, clear description of the issue" name="title" minlength="3" maxlength="160" required dir="auto" value="{{ old('title') }}"></label></p>
<p><label>What is happening? <textarea placeholder="What happened, where is it, and how does it affect people?" name="description" rows="5" maxlength="5000" dir="auto">{{ old('description') }}</textarea></label></p>
<p><label>Photos or video <input type="file" name="media[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"></label> Up to 3 files, 2 MB each. JPEG, PNG, WebP, MP4 or WebM. Add text or at least one file.</p>
<p><label><input type="checkbox" name="consent" value="1" required> I agree to publish the place names, report and media. I have permission to share this content and have removed private information.</label></p>
<p>Reports are citizen accounts, not verified findings. Do not include identity documents, private phone numbers or private addresses. Government submission is a separate step.</p>
<p id="issue-submit-status" role="alert" tabindex="-1" hidden></p><button type="button" id="issue-submit-location" hidden>Review report location</button><button type="submit" class="publish-report">Post report</button></div></form></section>
<link rel="stylesheet" href="{{ asset('css/issue-post.css') }}?v={{ substr(hash_file('sha256', public_path('css/issue-post.css')),0,12) }}"><script src="{{ asset('js/issue-post.js') }}" defer></script>
@include('issue-location-feed')
<script type="module" src="{{ asset('js/issue-location.js') }}?v={{ substr(hash_file('sha256', public_path('js/issue-location.js')),0,12) }}"></script>
<meta name="issue-locate-url" content="{{ route('issues.locate') }}">
<meta name="issue-options-url" content="{{ route('issues.location-options') }}">
<meta name="issue-problem-url" content="{{ route('issues.problem-location') }}">
</div>
<style>
.issue-compose-page{max-width:760px;margin:24px auto 48px;font:15px/20px Arial,Helvetica,sans-serif;color:#0f1419}.issue-compose-page h1{font:700 26px/32px Arial,Helvetica,sans-serif;margin:0 0 8px}.issue-compose-page h2{font:700 19px/24px Arial,Helvetica,sans-serif;margin:0 0 14px}.issue-compose-page p,.issue-compose-page label,.issue-compose-page summary{font:400 15px/20px Arial,Helvetica,sans-serif}.issue-compose-page .card{padding:22px;margin:18px 0;border:1px solid #eff3f4;border-radius:16px;box-shadow:none;background:#fff}.issue-compose-page input:not([type=checkbox]):not([type=hidden]),.issue-compose-page select,.issue-compose-page textarea{display:block;width:100%;box-sizing:border-box;margin-top:7px;padding:12px;border:1px solid #cfd9de;border-radius:8px;font:15px/20px Arial,Helvetica,sans-serif;background:#fff;color:#0f1419}.issue-compose-page input:focus,.issue-compose-page select:focus,.issue-compose-page textarea:focus{outline:2px solid #1d9bf0;outline-offset:1px}.issue-compose-page button{font:700 15px/20px Arial,Helvetica,sans-serif;border-radius:24px;padding:10px 18px}.issue-compose-page .publish-report{background:#0f1419;color:white;border:0;min-width:120px}.issue-compose-page #issue-confirmed-label,.issue-compose-page #issue-problem-label{background:#f7f9f9;padding:12px;border-radius:8px}.issue-compose-page #issue-selected-feed{padding:18px 0}.issue-compose-page #issue-selected-feed>h2,.issue-compose-page #issue-selected-feed>p{margin-left:20px;margin-right:20px}.issue-compose-page [hidden]{display:none!important}@media(max-width:600px){.issue-compose-page{margin:16px 0}.issue-compose-page .card{padding:16px}}
.report-unified #issue-location{margin-bottom:20px}.report-unified .inline-problem-location{padding:18px 0;border-top:1px solid #eff3f4;border-bottom:1px solid #eff3f4}.report-unified #issue-compose{padding-top:22px}.report-unified #issue-location-warning{padding:16px 0}.report-unified #issue-location h2{font-size:17px}.report-unified #issue-same-location{margin-bottom:10px}.report-unified{border-top:4px solid #087f70!important;box-shadow:0 12px 35px #173d370b!important;padding:28px!important}.issue-compose-page #issue-location{margin-bottom:14px}.issue-compose-page #issue-location>p{color:#536471;font-size:13px;line-height:19px}.location-line{display:flex;align-items:center;gap:14px;background:#eef7f4;border:1px solid #d7eae3;border-radius:12px;padding:8px 12px}.issue-compose-page .location-line #issue-problem-label{flex:1;background:none;padding:4px;font-weight:600}.issue-compose-page .location-line button{flex-shrink:0;padding:8px 12px;font-size:13px;background:white;color:#176c55;border:1px solid #b5d6cb}.location-change{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px;color:#536471}.issue-compose-page #issue-change-location{background:white;color:#176c55;border:1px solid #b5d6cb;font-size:13px;padding:8px 13px}.location-summary{display:none}.issue-compose-page #issue-same-location{margin-top:12px;font-size:13px;padding:8px 13px}.issue-compose-page #issue-compose h2{margin-top:4px}.issue-compose-page #issue-compose label{font-weight:600}.issue-compose-page #issue-compose>p{margin:0 0 22px}.issue-compose-page input:not([type=checkbox]):not([type=hidden]),.issue-compose-page textarea,.issue-compose-page select{background:#fafcfb;border-radius:10px;padding:13px}.issue-compose-page input::placeholder,.issue-compose-page textarea::placeholder{color:#89958e}.issue-compose-page .publish-report{background:#087f70;box-shadow:0 4px 12px #087f7022;padding:12px 24px}.issue-compose-page #issue-confirmed-label{font-size:12px;line-height:18px;color:#536471;background:none;padding:0}@media(max-width:600px){.report-unified{padding:18px!important}.location-line,.location-change{flex-wrap:wrap}.location-line #issue-problem-label{min-width:180px}}
</style>
@endsection
