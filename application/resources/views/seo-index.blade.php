@extends('seo-layout')
@section('content')
<p class="muted">SELECT / DRAFT / REVIEW / APPLY</p><h1>Bulk SEO management</h1>
<p>Prepare page titles and descriptions using the available official-data catalog. Review your wording before applying it to the website.</p>
<p class="notice">Create template drafts or ask AI to improve wording from the public page catalog. Every draft must be reviewed before applying. Canonical URLs and source data stay managed by the platform.</p>
<form method="get" class="filters"><div><label for="type">Page type</label><select name="type" id="type">@foreach(['ac'=>'Assembly constituencies','pc'=>'Parliamentary constituencies','district'=>'Districts','village'=>'Villages'] as $value=>$label)<option value="{{ $value }}" @selected($type===$value)>{{ $label }}</option>@endforeach</select></div><div><label for="year">Census edition (villages)</label><select id="year" name="year"><option @selected($year==='2011')>2011</option><option @selected($year==='2001')>2001</option></select></div><button>Show pages</button></form>
<section class="card"><h2>Select pages</h2><p class="muted">{{ number_format($pages->total()) }} available pages. Up to 50 per draft. Selection applies to this page only.</p>
<form id="create-draft" method="post" action="{{ route('seo.create') }}">@csrf<input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="year" value="{{ $year }}">
<label class="row"><input type="checkbox" id="select-all">Select all on this page</label>
@foreach($pages as $page)
<div class="row"><label><input type="checkbox" name="paths[]" value="{{ $page['path'] }}" @checked(in_array($page['path'], old('paths', [])))>{{ $page['label'] }}</label><a class="url" href="{{ $page['path'] }}" target="_blank" rel="noopener">View page</a> <span class="muted">{{ ($current[$page['path']]->title ?? null) ? 'Custom metadata applied' : 'Default metadata' }}</span></div>
@endforeach
<div class="field"><label for="generation">Draft method</label><select id="generation" name="generation"><option value="template" @selected(old('generation', 'template')==='template')>Template - up to 50 pages</option><option value="ai"  @selected(old('generation')==='ai')>AI-assisted - up to 10 pages</option></select></div>
<div class="field"><label for="provider">AI provider (for AI-assisted drafts)</label><select name="provider" id="provider"><option value="">Choose later / select a provider</option>@foreach($aiProviders as $id=>$provider)<option value="{{ $id }}" @selected(old('provider')===$id)>{{ $provider['label'] }} - {{ $provider['configured'] ? 'Configured' : 'Setup needed' }}</option>@endforeach</select></div>
<p><a href="{{ route('ai.settings') }}">API keys and model settings</a></p>
<p class="muted">Only the selected provider receives the public page catalog. API usage is billed by that provider. No automatic provider switching. Limit: 10 requests per hour per administrator across all providers.</p>
<p id="generation-progress" role="status"></p><p><button id="create-button">Create draft from selected pages</button></p></form>
{{ $pages->links() }}</section>
<section class="card"><h2>Recent drafts</h2>
@forelse($batches as $batch)
<p><a href="{{ route('seo.draft', $batch->id) }}">{{ $batch->applied_at ? 'Applied' : 'Draft' }} - {{ \Carbon\Carbon::parse($batch->created_at)->timezone('Asia/Kolkata')->format('d M Y H:i') }} IST</a> <span class="muted">{{ count(json_decode($batch->items, true)) }} pages / {{ $batch->ai_generation ? 'AI-assisted' : 'Template' }}</span></p>
@empty
<p>No drafts yet.</p>
@endforelse
</section><section class="card history"><h2>Change history</h2><p class="muted">Restoring records a new revision. Dates are shown in India Standard Time.</p>
@forelse($history as $revision)
<details class="row"><summary>#{{ $revision->id }} - {{ $revision->path }} - {{ \Carbon\Carbon::parse($revision->created_at)->timezone('Asia/Kolkata')->format('d M Y H:i') }} IST</summary>
<p>{{ $revision->action }} <span class="muted">/ {{ $revision->user_id ? "Administrator #".$revision->user_id : "Earlier local session" }}</span></p><p><strong>Applied title:</strong> {{ $revision->title ?? 'Platform default' }}</p><p>{{ $revision->description ?? 'Platform default description' }}</p>
<p><strong>State before this change:</strong> {{ $revision->before_title ?? 'Platform default' }}</p><p>{{ $revision->before_description ?? 'Platform default description' }}</p>
<form method="post" action="{{ route('seo.restore', $revision->id) }}">@csrf<input type="hidden" name="expected_revision" value="{{ $current[$revision->path]->revision_id }}"><button class="secondary">Restore state before #{{ $revision->id }}</button></form></details>
@empty
<p>No applied changes yet.</p>
@endforelse
{{ $history->withQueryString()->links() }}</section>
<script>document.getElementById('select-all').addEventListener('change',function(){document.querySelectorAll('input[name="paths[]"]').forEach(input=>input.checked=this.checked)});document.getElementById('create-draft').addEventListener('submit',()=>{document.getElementById('create-button').disabled=true;document.getElementById('generation-progress').textContent='Preparing your draft. Please wait for the result before submitting again.'});</script>
@endsection
