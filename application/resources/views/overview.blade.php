<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
@include('seo-metadata', ['canonical'=>route('home'),'seoTitle'=>'India, elections & places · Pollmedia','seoDescription'=>'Explore Lok Sabha and Assembly election trends, state results, Census and places.','breadcrumbs'=>['India'=>route('home')]])
@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}?v={{ substr(hash_file('sha256',public_path('css/election-dashboard.css')),0,12) }}">
<link rel="stylesheet" href="{{ asset('css/home-dashboard.css') }}?v={{ substr(hash_file('sha256',public_path('css/home-dashboard.css')),0,12) }}">
<script src="{{ asset('js/history-lines.js') }}?v={{ substr(hash_file('sha256',public_path('js/history-lines.js')),0,12) }}" defer></script>
<script src="{{ asset('js/home-dashboard.js') }}?v={{ substr(hash_file('sha256',public_path('js/home-dashboard.js')),0,12) }}" defer></script>

<link rel="stylesheet" href="{{ asset('css/public-landings.css') }}?v={{ substr(hash_file('sha256',public_path('css/public-landings.css')),0,12) }}"></head><body class="election-ui home-dashboard pm-landing">@include('public-header')
@php $issueService=app(\App\Services\CommunityAreaIssues::class); $issueArea=$issueService->context('india','India'); @endphp


<main class="home-main">

@php
    $nationalCensus = app(\App\Services\CensusProfileSummary::class)->series();
    $populationPoint = collect($nationalCensus['rows'])->whereNotNull('population')->sortByDesc('year')->first();
    $parliament = collect($electionDashboard['pc']['rows'])->sortByDesc('year')->first();
@endphp
<header class="landing-intro home-lead">
    <div><p class="landing-kicker">India · Places and public records</p><h1>Find your place</h1>
    <p>Explore a village, district or constituency through its people, elections and local records.</p>
    <div class="landing-finder" data-place-search><form role="search" action="{{ route('public.search') }}">
        <input aria-label="Search by place or PIN code" name="q" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="home-matches" autocomplete="off" type="search" required minlength="2" maxlength="100" placeholder="Place name or PIN code"><button aria-label="Search">@include('search-symbol')</button>
    </form><div id="home-matches" class="search-suggestions" role="listbox" hidden></div><span class="visually-hidden" role="status"></span></div></div>
    <nav aria-label="Explore India"><a href="{{ route('my-place.index') }}">My Place <span>↗</span></a><a href="{{ route('india-map.index') }}">Explore the India map <span>↗</span></a></nav>
</header>
<div class="landing-duo">
    <article class="landing-card"><p class="landing-kicker">Lok Sabha (PC)</p><h2>National elections</h2>
    @if($parliament)<p class="landing-value">{{ $parliament['year'] }}</p><p>@if(isset($parliament['turnout'])){{ number_format($parliament['turnout'],2) }}% turnout · {{ number_format($parliament['turnout_count']) }} of {{ number_format($parliament['tables']) }} constituencies with comparable figures.@endif</p>@endif
    <a href="#lok-sabha">Election history ↓</a> · <a href="#states">Browse states ↓</a></article>
    <article class="landing-card"><p class="landing-kicker">Census of India</p><h2>Population and places</h2>
    @if($populationPoint)<p class="landing-value">{{ number_format($populationPoint['population']/10000000,2) }} crore</p><p>Population recorded in {{ $populationPoint['year'] }}.</p>@endif
    <a href="{{ route('census-catalogue.index') }}">Explore Census →</a></article>
</div>
<section class="home-finder" aria-labelledby="finder-title"><div><p class="home-eyebrow">Go straight to your constituency</p><h2 id="finder-title">Find election results</h2></div><form data-home-finder data-endpoint="{{ route('home') }}"><label>Election<select name="kind"><option value="pc">Lok Sabha</option><option value="ac">State Assembly</option></select></label><label>State / Union Territory<select name="state"><option value="">Choose a state</option>@foreach($states as $item)<option value="{{ $item['name'] }}">{{ $item['name'] }}</option>@endforeach</select></label><label>Constituency<select name="seat" disabled><option value="">Choose a state first</option></select></label><button disabled>View results →</button><p class="home-finder-status" role="status"></p></form></section>
@foreach(['pc'=>['lok-sabha','Lok Sabha elections','India’s parliamentary election history'], 'ac'=>['assembly','Assembly elections','How the states have voted']] as $kind=>[$anchor,$label,$description])
@php
 $section=$electionDashboard[$kind];
 $compact=fn($value)=>$value>=10000000?rtrim(rtrim(number_format($value/10000000,1),'0'),'.').' Cr.':($value>=100000?rtrim(rtrim(number_format($value/100000,1),'0'),'.').' L':number_format($value));
 $plots=[['title'=>'Registered electors, votes polled and turnout','unit'=>'people','rightAutoScale'=>true,'series'=>[['key'=>'electors','label'=>'Registered electors'],['key'=>'polled','label'=>'Votes polled'],['key'=>'turnout','label'=>'Turnout (%)','axis'=>'right','dashed'=>false]]],['title'=>'Top five parties · vote share over time','unit'=>'%','series'=>array_map(fn($party,$i)=>['key'=>'party'.$i.'_share','votes_key'=>'party'.$i,'label'=>$party],$section['top_parties'],array_keys($section['top_parties']))]];
@endphp
<section id="{{ $anchor }}" class="home-election-section"><header class="home-section-heading"><div><p class="home-eyebrow">{{ $kind==='pc'?'01 · Parliament':'02 · State legislatures' }}</p><h2>{{ $label }}</h2><p>{{ $description }}</p></div><a class="home-text-link" href="{{ route($kind==='pc'?'elections.history':'elections.assembly') }}">Explore all results →</a></header>
<div class="home-stat-grid">@foreach(['elections'=>$kind==='pc'?'Election years':'State elections','parties'=>'Political parties','candidates'=>'Candidates','results'=>'Constituency results'] as $key=>$statLabel)<article><span>{{ $statLabel }}</span><strong>{{ $section['rows']?$compact($section['stats'][$key]):'—' }}</strong></article>@endforeach</div>
@if($section['rows'])<div class="home-chart-grid">@foreach($plots as $plot)@include('home-history-chart',['plot'=>$plot,'chartRows'=>$section['rows']])@endforeach</div>
<p class="home-chart-note">{{ $kind==='ac'?'Assembly figures combine state elections held in the same calendar year. ':'' }}Figures reflect available source records. Turnout uses votes and electors from the same available constituencies. Party shares use available candidate votes; the five parties are ranked by their total votes across the displayed years. † Source notes apply.</p>
@else<p class="home-empty">Explore state pages for available election results and original sources.</p>@endif
<div class="home-section-heading home-state-heading" @if($kind==='pc') id="states" @endif><div><h3>Explore {{ $kind==='pc'?'Lok Sabha':'Assembly' }} by state</h3><p>Choose a state to see its map, trends and constituencies.</p></div><label class="home-state-filter">Find a state<input type="search" placeholder="State name" data-state-filter="{{ $kind }}"></label></div>
<div class="home-state-grid" data-state-grid="{{ $kind }}">@foreach($states->filter(fn($item)=>$kind==='pc' || $item['kind']==='State' || in_array($item['slug'],['delhi','jammu-and-kashmir','puducherry'])) as $item)<a class="home-state-card" href="{{ route('states.show',['state'=>$item['slug'],'election'=>$kind]) }}#{{ $kind }}-history"><div><span>{{ $item['kind'] }}</span><strong>{{ $item['name'] }}</strong></div><span class="home-card-arrow" aria-hidden="true">↗</span></a>@endforeach</div><p data-state-empty="{{ $kind }}" hidden>No matching states.</p>
</section>
@endforeach
@include('census-landing-section')
@include('community-area-issues')
</main>@include('public-footer')</body></html>
