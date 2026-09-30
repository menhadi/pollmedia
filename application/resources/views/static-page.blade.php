@extends('geography-layout')
@section('title',$page['title'])
@section('content')
<div class="static-page-layout"><article class="card policy-copy"><p class="muted">{{ app(\App\Services\SiteSettings::class)->appearance()['name'] }} · Information</p><h1>{{ $page['title'] }}</h1><p class="policy-summary">{{ $page['summary'] }}</p>@if(isset($page['updated_at']))<p class="muted">Updated {{ \Carbon\Carbon::parse($page['updated_at'])->format('j F Y') }}</p>@endif{!! $html !!}</article><aside class="card policy-nav"><h2>More information</h2>@foreach(app(\App\Services\StaticPages::class)->published() as $key=>$item)<a href="{{ route('static-pages.show',$key) }}" @if($key===$slug) aria-current="page" @endif>{{ $item['title'] }}</a>@endforeach</aside></div>
@endsection
