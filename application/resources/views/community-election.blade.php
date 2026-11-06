@extends('geography-layout')
@section('title','Edit '.$document['entry']->constituency_name)
@section('head')<meta name="robots" content="noindex,nofollow">@endsection
@section('content')
@include('community-style')
<h1>Edit {{ $document['entry']->constituency_name }} · {{ $document['entry']->year }}</h1><p>Suggest corrections directly in the source table. Candidate totals must reconcile before approval. Winner, margin, turnout and other calculated facts update from the approved counts. <a href="{{ route('community.account') }}">My contributions →</a></p><p><a href="{{ $document['source']['source_url'] }}" rel="noopener noreferrer" target="_blank">Original election source ↗</a></p>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div class="scroll"><table><thead><tr><th>Field</th><th>Published value</th><th>Correction</th></tr></thead><tbody>
@foreach($document['fields'] as $key=>$descriptor)<tr data-source-kind="election" data-source-record="{{ $document['entry']->id }}" data-source-year="{{ $document['entry']->year }}" data-source-field="{{ $key }}"><th>{{ $descriptor['label'] }}</th><td>{{ $descriptor['value'] ?? 'Unavailable' }}</td><td>@if($descriptor['editable'])@include('community-inline-field',['field'=>$key,'value'=>$descriptor['value'],'expected'=>$document['expected'],'action'=>route('community.election.propose',$document['entry']->id)])
@else<span class="data-note">Source identity or calculated field; kept consistent with the underlying source counts.</span>@endif</td></tr>@endforeach
</tbody></table></div>
@include('community-changes')
@endsection
