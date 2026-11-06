@extends('geography-layout')
@section('title','Edit '.$listing->name)
@section('head')<meta name="robots" content="noindex,nofollow">@endsection
@section('content')
@include('community-style')
<h1>Suggest corrections: {{ $listing->name }}</h1><p>Edit a value below and explain the discrepancy. An administrator can approve, amend or reject your suggestion. Follow every decision in <a href="{{ route('community.account') }}">My contributions</a>.</p>
@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($census)<p><a data-edit-related class="data-button primary" href="{{ route('community.record',$census) }}">Edit all Census parameters for this place →</a></p>@endif
<div class="scroll"><table><thead><tr><th>Field</th><th>Published value</th><th>Suggest correction</th></tr></thead><tbody>
@foreach($documents as $document)<tr data-source-kind="{{ $document['table'] }}" data-source-record="{{ $document['id'] }}" data-source-field="{{ $document['field'] }}"><td>{{ $document['label'] }}@if($document['source'])<br><a href="{{ $document['source'] }}" rel="noopener noreferrer" target="_blank">Source ↗</a>@endif</td><td>{{ $document['value'] ?? 'Unavailable' }}</td><td><details><summary>Edit this value</summary><form method="post" action="{{ route('community.listing.propose',$listing->id) }}">@csrf<input type="hidden" name="table" value="{{ $document['table'] }}"><input type="hidden" name="id" value="{{ $document['id'] }}"><input type="hidden" name="expected" value="{{ $document['expected'] }}"><label>Suggested value<input name="value" value="{{ $document['value'] }}" maxlength="1000"></label><label>Reason<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label><label>Supporting reference URL<input name="reference_url" type="url" maxlength="2000"></label><button class="data-button primary">Submit for review</button></form></details></td></tr>@endforeach
</tbody></table></div><p class="data-note">Official identifiers and source files stay unchanged. Calculated statistics are corrected through their underlying counts.</p>
@include('community-changes')
@endsection
