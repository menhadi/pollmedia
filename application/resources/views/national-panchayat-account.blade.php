@php($hi=false)
@php($canonicalUrl=route('panchayat-accounts.show',['state'=>$sourceRecord['state'],'gp'=>$sourceRecord['gp']]))
@php($title=$sourceRecord['name'].' · Monthly accounts')
@extends('gram-panchayat-layout',compact('hi','title'))
@section('directory-content')
<p><a href="{{ route('panchayat-accounts.index') }}">Panchayat accounts</a> › <a href="{{ route('panchayat-accounts.index',['state'=>$sourceRecord['state']]) }}">{{ $coverage['states'][$sourceRecord['state']]['name'] }}</a></p>
<h1>{{ $sourceRecord['name'] }}</h1>
@if($sourceRecord['summary'])
@include('national-panchayat-monthly',['nationalSummary'=>$sourceRecord])
@else
<p>Monthly summary pending import.</p>
@endif
@if($sourceRecord['native_name'] && $sourceRecord['identity_match'])<p><a href="{{ app(\App\Services\GramPanchayatUrls::class)->link($sourceRecord['state'],$sourceRecord['native_name'],$sourceRecord['gp']) }}">Panchayat profile →</a></p>@elseif($sourceRecord['native_name'])<p class="place-note">Source-name correspondence pending.</p>@else<p class="place-note">Village correspondence pending.</p>@endif
@endsection
