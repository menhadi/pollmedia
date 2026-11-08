<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
@include('seo-metadata', ['breadcrumbs'=>['India'=>route('home'),$title=>route('states.show',['state'=>$state])], 'canonical'=>route('states.show',['state'=>$state]), 'seoTitle'=>$title.' election results & history · Pollmedia', 'seoDescription'=>'Explore available turnout, party vote shares and winning margins with constituency results and official sources.'])
<link rel="stylesheet" href="/css/election-dashboard.css?v={{ substr(hash_file('sha256', public_path('css/election-dashboard.css')), 0, 12) }}"><script src="/js/instant-filters.js?v={{ substr(hash_file('sha256',public_path('js/instant-filters.js')),0,12) }}" defer></script>@include('site-theme')<link rel="stylesheet" href="{{ asset('css/civic-pages.css') }}?v={{ substr(hash_file('sha256',public_path('css/civic-pages.css')),0,12) }}"><script src="/js/history-lines.js?v={{ substr(hash_file('sha256',public_path('js/history-lines.js')),0,12) }}" defer></script><script src="/js/state-fragments.js?v={{ substr(hash_file('sha256',public_path('js/state-fragments.js')),0,12) }}" defer></script><link rel="stylesheet" href="{{ asset('css/public-landings.css') }}?v={{ substr(hash_file('sha256',public_path('css/public-landings.css')),0,12) }}"><script src="{{ asset('js/public-landings.js') }}?v={{ substr(hash_file('sha256',public_path('js/public-landings.js')),0,12) }}" defer></script><link rel="stylesheet" href="{{ asset('css/constituency-atlas.css') }}?v={{ substr(hash_file('sha256', public_path('css/constituency-atlas.css')),0,12) }}"><link rel="stylesheet" href="{{ asset('css/compact-area-pages.css') }}?v={{ substr(hash_file('sha256',public_path('css/compact-area-pages.css')),0,12) }}"></head><body class="election-ui civic-design state-design pm-landing seat-atlas-page">
@include('public-header',['summaryLayout'=>true])



<div class="dashboard-shell">
<main class="dashboard-main landing-state seat-atlas compact-election">
@php($sectionLabel=$kind==='pc'?'Lok Sabha (PC)':'Assembly (AC)')
<div class="state-atlas-filters">
<header class="landing-intro"><nav aria-label="Breadcrumb"><a href="{{ route('home') }}">India</a> / Elections</nav>
<div class="state-title-row"><h1>{{ $title }} elections</h1>
<a class="state-census-button" href="{{ $censusUrl }}">{{ app()->getLocale()==='hi'?'जनगणना देखें':'Explore Census' }} →</a>
</div>
<div class="landing-controls"><label>State or union territory<select data-go-url>@foreach($states as $item)<option value="{{ route('states.show',['state'=>$item['slug'],'election'=>$kind]) }}" @selected($item['slug']===$state)>{{ $item['name'] }}</option>@endforeach</select></label>
<nav class="landing-switch" aria-label="Election type">@foreach(['pc'=>'Lok Sabha (PC)','ac'=>'Assembly (AC)'] as $key=>$name)<a href="{{ route('states.show',['state'=>$state,'election'=>$key]) }}" @if($key===$kind) aria-current="page" @endif>{{ $name }}</a>@endforeach</nav>
<form data-manual-submit  class="landing-year" method="get" action="{{ route('states.show',['state'=>$state]) }}#{{ $kind }}-results"><input type="hidden" name="election" value="{{ $kind }}"><input type="hidden" name="{{ $kind==='pc'?'ac_edition':'pc_edition' }}" value="{{ $electionSections[$kind==='pc'?'ac':'pc']['edition'] }}"><label for="{{ $kind }}-edition">Election year</label><div><select data-submit-choice id="{{ $kind }}-edition" name="{{ $kind }}_edition" @disabled(!$history)>@forelse($history as $row)<option value="{{ $row['id'] }}" @selected($edition===$row['id'])>{{ $row['label'] }}</option>@empty<option>No available election years</option>@endforelse</select><button @disabled(!$history)>Show results</button></div></form>
</div></header>
@include('state-results-summary')
</div>

<nav class="landing-links"><a href="#{{ $kind }}-constituency-results">Constituency results</a><a href="#{{ $kind }}-history">Election history</a></nav>
<div id="election-analysis" data-local-data-browser>@include('state-election-section')</div>
<section class="panel" id="state-evidence"><h2>{{ $title }} election coverage</h2><h3>Assembly archive</h3><a href="{{ route('elections.assembly-sources',['state'=>$title]) }}">Browse {{ $title }} Assembly sources</a><p><a href="{{ route('elections.assembly') }}">Assembly results</a> · <a href="{{ route('elections.history') }}">Lok Sabha results</a></p></section>
<p><a href="{{ route('community.scope',['state'=>$state,'level'=>'STATE']) }}">Edit data</a> · <a href="{{ route('elections.by-election-results') }}">By-elections</a></p>
@include('election-methodology',['isStateHistory'=>true])
</main></div><script src="/js/sortable-tables.js?v={{ substr(hash_file('sha256', public_path('js/sortable-tables.js')), 0, 12) }}" defer></script>@include('public-footer')</body></html>
