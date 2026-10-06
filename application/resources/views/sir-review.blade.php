@extends('seo-layout')
@section('content')
<h1>SIR extraction review</h1>
<p>Review doubtful voter cards against their original PDF. Vision suggestions remain separate until you verify and apply them.</p>
<p class="notice">Requesting Vision sends the preserved original part PDF to OpenAI using your configured API account and may incur API charges. It reads one requested card; it does not publish corrections automatically. <a href="{{ route('ai.settings') }}">AI provider settings</a>. Model: {{ config('seo-ai.sir_vision_model') ?: $settings['model'] }}.</p>
<form method="get" class="filters card" data-manual-submit>
<div><label for="review-status">Show</label><select id="review-status" name="status"><option value="uncertain" @selected(request('status','uncertain')==='uncertain')>Uncertain names</option><option value="age" @selected(request('status')==='age')>Doubtful ages (missing / below 18)</option><option value="all" @selected(request('status')==='all')>All records / other OCR fields</option></select></div>
<div><label for="review-edition">Edition</label><select id="review-edition" name="edition_key"><option value="">All imported editions</option>@foreach($editions as $edition)<option value="{{ $edition->edition_key }}" @selected(request('edition_key')===$edition->edition_key)>AC {{ $edition->ac_code }} / {{ $edition->edition }}</option>@endforeach</select></div>
<div><label for="review-part">Part</label><input id="review-part" name="part" type="number" min="1" value="{{ request('part') }}"></div><div><label for="review-serial">Sequence number</label><input id="review-serial" name="serial" type="number" min="1" value="{{ request('serial') }}"></div><button>Filter review queue</button></form>
<p>{{ number_format($records->total()) }} records match this review queue.</p>
@forelse($records as $record)
<section class="card"><h2>{{ $record->name }}</h2><p class="muted">AC {{ $record->ac_code }} / Part {{ $record->part }} / Sequence {{ $record->serial }} / {{ $record->year }} / {{ $record->extraction_status }}</p>
@if($record->pdf_sha256)<a class="button secondary" href="{{ route('sir.document',['hash'=>$record->pdf_sha256]) }}#page={{ $record->pdf_page }}" target="_blank" rel="noopener">Compare PDF page {{ $record->pdf_page }}</a>@endif
<p class="muted">{{ $record->extraction_note }} {{ $record->field_notes }}</p>
@php($history=$reviews->get($record->id,collect()))
@php($pending=$history->firstWhere('status','pending'))
@if(!$pending)
<form method="post" action="{{ route('sir.review.extract',['record'=>$record->id]) }}" data-manual-submit data-vision-request>@csrf<div class="field"><label for="vision-model-{{ $record->id }}">Vision model ID</label><input id="vision-model-{{ $record->id }}" name="model" maxlength="120" value="{{ config('seo-ai.sir_vision_model') ?: $settings['model'] }}" required><small class="muted">Choose a PDF-capable model available to your OpenAI account. This does not change your SEO model.</small></div><button @disabled(!$settings['has_key'] || !$record->pdf_sha256)>Extract card with Vision</button></form>
@endif
@if($pending)
@php($suggestion=json_decode($pending->suggestion,true))
<div class="preview"><h3>Pending Vision suggestion</h3><p>{{ $suggestion['notes'] ?? '' }}</p><p class="muted">{{ $pending->model }} · requested {{ $pending->created_at }}. Empty/illegible suggestions retain the current text for manual checking.</p>
<form method="post" action="{{ route('sir.review.decide',['review'=>$pending->id]) }}" data-manual-submit>@csrf<input type="hidden" name="decision" value="approve">
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
@foreach(['name'=>'Elector name (original script)','relative_name'=>'Relative name (original script)','relationship'=>'Relationship','house_number'=>'House number','age'=>'Age','gender'=>'Gender (as printed)','section_number'=>'Section number','section_name'=>'Section name','ward_number'=>'Ward number (blank if absent)','elector_id'=>'Voter ID'] as $field=>$label)
<div class="field"><label for="{{ $field }}-{{ $pending->id }}">{{ $label }}</label>
@if($field==='relationship')<select id="{{ $field }}-{{ $pending->id }}" name="{{ $field }}">@foreach(['Father','Mother','Husband','Wife','Other'] as $relationship)<option @selected(($suggestion[$field] ?? $record->$field)===$relationship)>{{ $relationship }}</option>@endforeach</select>
@else<input id="{{ $field }}-{{ $pending->id }}" name="{{ $field }}" type="{{ $field==='age'?'number':'text' }}" value="{{ $suggestion[$field] ?? $record->$field }}" @if($field==='age') min="0" max="120" @endif @if(in_array($field,['name','relative_name'])) required @endif>
@endif<small class="muted">Current: {{ $record->$field ?? 'Not available' }}</small></div>
@endforeach</div>
<label><input type="checkbox" name="verified" value="1" required>I checked the correct part, sequence and all submitted fields against the original PDF.</label><p><button>Apply verified correction</button></p></form>
<form method="post" action="{{ route('sir.review.decide',['review'=>$pending->id]) }}" data-manual-submit>@csrf<input type="hidden" name="decision" value="reject"><button class="secondary">Reject suggestion</button></form></div>
@endif
@if($history->isNotEmpty())<details><summary>Review history ({{ $history->count() }})</summary>@foreach($history as $item)<p class="muted">{{ $item->created_at }} · {{ $item->model }} · {{ $item->status }}</p>@endforeach</details>@endif
</section>
@empty<p class="notice">No records match. Choose “All records” to check age, house number or other OCR fields.</p>@endforelse
{{ $records->links() }}
<script>document.querySelectorAll('form[data-vision-request]').forEach(form=>form.addEventListener('submit',()=>{const button=form.querySelector('button');button.disabled=true;button.textContent='Reading original PDF…';}));</script>
@endsection
