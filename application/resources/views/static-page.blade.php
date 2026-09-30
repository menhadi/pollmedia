@extends('geography-layout')
@section('title',$page['title'])
@section('content')
<div class="static-page-layout"><article class="card policy-copy"><p class="muted">{{ app(\App\Services\SiteSettings::class)->appearance()['name'] }} · Information</p><h1>{{ $page['title'] }}</h1><p class="policy-summary">{{ $page['summary'] }}</p>@if(isset($page['updated_at']))<p class="muted">Updated {{ \Carbon\Carbon::parse($page['updated_at'])->format('j F Y') }}</p>@endif
@if(app()->getLocale()==='hi' && $contentLanguage==='en')<p class="notice" lang="hi">इस पृष्ठ का विस्तृत हिन्दी अनुवाद अभी उपलब्ध नहीं है। मूल अंग्रेज़ी पाठ नीचे दिया गया है।</p>@endif<div lang="{{ $contentLanguage }}">{!! $html !!}</div></article><aside class="card policy-nav"><h2>{{ \App\Services\PublicLanguage::text('More information') }}</h2>@foreach(app(\App\Services\StaticPages::class)->published() as $key=>$item)<a href="{{ route('static-pages.show',$key) }}" target="_blank" rel="noopener noreferrer" title="Opens in a new tab" @if($key===$slug) aria-current="page" @endif>{{ \App\Services\PublicLanguage::text($item['title']) }}</a>@endforeach</aside></div>
@endsection
