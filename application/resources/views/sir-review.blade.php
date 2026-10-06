@extends('seo-layout')
@section('content')
<h1>SIR extraction review</h1>
<p>Review doubtful voter cards against their original PDF. Vision suggestions remain separate until you verify and apply them.</p>
<p class="notice">Select DeepSeek or OpenAI using the API key from <a href="{{ route('ai.settings') }}">AI Settings</a>. DeepSeek reads an image of the selected PDF page; OpenAI reads the original PDF. Requests may incur API charges. Suggestions need your verification before publication. For DeepSeek, use <code>deepseek-flash</code>.</p>
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
<form method="post" enctype="multipart/form-data" action="{{ route('sir.review.extract',['record'=>$record->id]) }}" data-manual-submit data-vision-request>@csrf
<div class="filters"><div class="field"><label for="vision-provider-{{ $record->id }}">Vision provider</label><select id="vision-provider-{{ $record->id }}" name="provider" class="vision-provider">@foreach(['deepseek','openai'] as $provider)<option value="{{ $provider }}" data-model="{{ $providerOptions[$provider]['model'] ?: ($provider==='deepseek'?'deepseek-flash':'') }}" data-key="{{ $providerOptions[$provider]['has_key']?'1':'0' }}">{{ $providerOptions[$provider]['label'] }}{{ $providerOptions[$provider]['has_key']?'':' — API key needed' }}</option>@endforeach</select></div>
<div class="field"><label for="vision-model-{{ $record->id }}">Vision model ID</label><input id="vision-model-{{ $record->id }}" name="model" maxlength="120" value="{{ $providerOptions['deepseek']['model'] ?: 'deepseek-flash' }}" required><small class="muted">Uses your saved provider model. You may override it for this request.</small></div></div>
<div class="field"><label for="vision-image-{{ $record->id }}">PDF page or card image (optional for DeepSeek)</label><input id="vision-image-{{ $record->id }}" name="page_image" type="file" accept="image/png,image/jpeg,image/webp"><small class="muted">The server normally creates this image from the original PDF. If rendering is unavailable, attach the matching page/card image (PNG, JPEG or WebP, below 8 MB). Include the sequence number.</small></div>
<button @disabled(!$providerOptions['deepseek']['has_key'] || !$record->pdf_sha256) data-has-pdf="{{ $record->pdf_sha256?'1':'0' }}">Extract card with Vision</button></form>
@endif
@if($pending)
@php($suggestion=json_decode($pending->suggestion,true))
<div class="preview"><h3>Pending Vision suggestion</h3>@if($pending->image_sha256)<p><a href="{{ route('sir.review.image',['review'=>$pending->id]) }}" target="_blank" rel="noopener">View the exact image sent to Vision</a> ({{ $pending->image_source==='original_pdf'?'rendered from the original PDF':'uploaded by administrator — verify source' }})</p>@endif<p>{{ $suggestion['notes'] ?? '' }}</p><p class="muted">{{ ucfirst($pending->provider) }} · {{ $pending->model }} · requested {{ $pending->created_at }}. Empty/illegible suggestions retain the current text for manual checking.</p>
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
@if($history->isNotEmpty())<details><summary>Review history ({{ $history->count() }})</summary>@foreach($history as $item)<p class="muted">{{ $item->created_at }} · {{ ucfirst($item->provider) }} · {{ $item->model }} · {{ $item->status }}</p>@endforeach</details>@endif
</section>
@empty<p class="notice">No records match. Choose “All records” to check age, house number or other OCR fields.</p>@endforelse
{{ $records->links() }}
<script>
document.querySelectorAll('form[data-vision-request]').forEach(form=>{
    const provider=form.querySelector('.vision-provider'),model=form.querySelector('[name=model]'),button=form.querySelector('button');
    provider.addEventListener('change',()=>{const choice=provider.selectedOptions[0];model.value=choice.dataset.model;button.disabled=choice.dataset.key!=='1'||button.dataset.hasPdf!=='1';});
    form.addEventListener('submit',()=>{button.disabled=true;button.textContent='Reading original source…';});
});
</script>
@endsection
