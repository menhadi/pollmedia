@extends('geography-layout')
@section('title','Edit data')
@section('head')<meta name="robots" content="noindex,nofollow">@endsection
@section('content')
@include('community-style')
<h1>Edit data</h1><p>All available source years and population groups for this place. Edit a value directly below; administrators review every suggestion. <a href="{{ route('community.account') }}">Track your contributions →</a></p>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@forelse($rows as $item)@php $record=$data->record($item->id); $values=json_decode($record->values,true);$values['listing_name']=$record->name;$fields=$data->fields($record); @endphp
<section class="card"><h2>{{ $item->name }} · {{ $item->year }} · {{ $item->residence }}</h2><p>{{ $item->edition_name }} · <a href="{{ $record->source_url }}" rel="noopener noreferrer" target="_blank">Official source ↗</a> · <a href="{{ route('community.record',$item->id) }}">Full record & change history →</a></p><div class="scroll"><table class="data-field-table"><thead><tr><th>Field</th><th>Published value</th><th>Correction</th></tr></thead><tbody>
@foreach($fields as $key=>$descriptor)@php $value=$data->value($values,$key); @endphp<tr data-source-kind="census" data-source-record="{{ $item->id }}" data-source-year="{{ $item->year }}" data-source-residence="{{ $item->residence }}" data-source-field="{{ $key }}"><th>{{ $descriptor['label'] }}</th><td>{{ $value ?? 'Unavailable' }}</td><td>@if($descriptor['editable'])@include('community-inline-field',['field'=>$key,'value'=>$value,'expected'=>$data->fingerprint($record),'action'=>route('community.propose',$item->id)])
@else<span class="data-note">{{ $descriptor['derived'] ? 'Recalculated when its source counts change.' : 'Official identifier; preserved with the original source.' }}</span>@endif</td></tr>@endforeach
</tbody></table></div></section>
@empty<p>No published source record is linked to this exact dated identity. No value or identity is inferred.</p>@endforelse
{{ $rows->links() }}
@if(isset($electionEntries) && $electionEntries->isNotEmpty())<section class="card"><h2>Election source records for this state</h2><p>State election totals, vote shares and turnout are calculated from these records. Correct the underlying source values to update the state’s facts and maps. Historical Census gaps remain unavailable.</p><div class="scroll"><table><thead><tr><th>Constituency</th><th>Year</th><th>Type</th><th>Correction</th></tr></thead><tbody>@foreach($electionEntries as $entry)<tr><td>{{ $entry->constituency_name }}</td><td>{{ $entry->year }}</td><td>{{ strtoupper($entry->kind) }}</td><td><a href="{{ route('community.election',$entry->id) }}">Edit source values →</a></td></tr>@endforeach</tbody></table></div>{{ $electionEntries->links() }}</section>@endif
@endsection
