@extends('seo-layout')
@section('content')
<a href="{{ route('seo.index') }}">Back to page selection and history</a><h1>{{ $draft->applied_at ? 'Applied metadata' : 'Review SEO draft' }}</h1>
<p>{{ count($items) }} pages / version {{ $draft->version }}. Saved previews are illustrative; search engines may display different wording.</p>
@if($draft->ai_generation)
@php($generation = json_decode($draft->ai_generation, true))
<p class="notice">AI-assisted draft / {{ $generation['provider'] }} / {{ $generation['model'] }}. Review names, coverage and reference years against the page before applying. AI wording is not independent fact verification.</p>
<p class="muted">API usage: {{ $generation['input_tokens'] ?? 'Unavailable' }} input tokens / {{ $generation['output_tokens'] ?? 'Unavailable' }} output tokens.</p>
<details><summary>Public catalog information used for generation</summary>@foreach($generation['catalog_snapshot'] as $source)<p><strong>{{ $source['page'] }}</strong><br>{{ $source['description'] }}</p>@endforeach</details>
@endif
@if(!$draft->applied_at)
<p class="notice">Edit the fields and save first. Applying uses only the saved version shown in the previews. Keep Census years and coverage limitations accurate.</p>
@endif
<form id="edit-draft" method="post" action="{{ route('seo.save', $draft->id) }}">@csrf<input type="hidden" name="version" value="{{ $draft->version }}">
@foreach($items as $index=>$item)
<section class="card"><h2>{{ $item['label'] }}</h2><a class="url" href="{{ $item['path'] }}" target="_blank" rel="noopener">{{ $item['path'] }}</a>
<details><summary>Metadata before this draft</summary><p>{{ $item['previous_title'] ?? 'Platform-generated title (no custom override)' }}</p><p>{{ $item['previous_description'] ?? 'Platform-generated description (no custom override)' }}</p></details>
<div class="preview"><span class="muted">Saved preview</span><strong>{{ $item['title'] }}</strong><span class="url">{{ url($item['path']) }}</span><p>{{ $item['description'] }}</p></div>
@if(!$draft->applied_at)
<div class="field"><label for="title-{{ $index }}">Title</label><input type="text" id="title-{{ $index }}" name="items[{{ $index }}][title]" maxlength="180" required value="{{ old('items.'.$index.'.title', $item['title']) }}"><small class="muted">{{ mb_strlen($item['title']) }} characters saved. Aim for a concise, accurate title.</small></div>
<div class="field"><label for="description-{{ $index }}">Description</label><textarea id="description-{{ $index }}" name="items[{{ $index }}][description]" maxlength="500" rows="3" required>{{ old('items.'.$index.'.description', $item['description']) }}</textarea><small class="muted">{{ mb_strlen($item['description']) }} characters saved. Display length varies by device.</small></div>
@endif
</section>
@endforeach
@if(!$draft->applied_at)
<button>Save edits and refresh previews</button>
@endif
</form>
@if(!$draft->applied_at)
<form method="post" action="{{ route('seo.apply', $draft->id) }}" class="card">@csrf<input type="hidden" name="version" value="{{ $draft->version }}"><h2>Apply reviewed metadata</h2><p id="apply-note">Apply the saved titles and descriptions to these {{ count($items) }} pages. You can restore previous metadata from history.</p><button id="apply-button">Apply saved metadata to {{ count($items) }} pages</button></form>
<script>document.getElementById('edit-draft').addEventListener('input',()=>{document.getElementById('apply-button').disabled=true;document.getElementById('apply-note').textContent='You have unsaved edits. Save them above, then review the refreshed previews.'});</script>
@endif
@endsection
