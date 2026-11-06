@extends('geography-layout')
@section('title', \App\Services\PublicLanguage::text('Published reports'))
@section('content')
@php $hi=app()->getLocale()==='hi'; @endphp
<div class="published-issues-page">
<header class="place-page-heading"><p class="kicker">{{ $hi?'स्थानीय समस्याएँ':'Local issues' }}</p><h1>{{ $hi?'प्रकाशित समस्याएँ':'Posted problems' }}</h1>@if($areaName)<p>{{ $areaName }}</p>@endif</header>
<div class="public-page-actions"><a class="place-button" href="{{ route('issues.index',['area'=>$area,'lang'=>app()->getLocale()]) }}">{{ $hi?'समस्या बताएँ':'Report a problem' }} →</a></div>
@include('issue-location-feed')
</div>
@endsection
