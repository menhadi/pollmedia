<link rel="stylesheet" href="{{ asset('css/public-navigation.css') }}?v={{ substr(hash_file('sha256',public_path('css/public-navigation.css')),0,12) }}">

@php $site=app(\App\Services\SiteSettings::class)->appearance(); $menuLang=app()->getLocale()==='hi'?['lang'=>'hi']:[]; $censusMenu=app(\App\Services\CensusPlaceNavigation::class)->stateDirectory(2011); @endphp

<header data-compact-place-header class="public-header site-header header-{{ $site['header_template'] }}"><a class="brand" href="{{ route('home') }}">@if($site['logo'])<img src="{{ $site['logo'] }}" alt="{{ $site['name'] }}" width="210" height="53">@else<span class="brand-mark" aria-hidden="true"></span><span class="brand-name">{{ $site['name'] }}</span>@endif</a><button type="button" class="mp-search-toggle" hidden aria-expanded="false" aria-controls="mp-site-search" aria-label="{{ \App\Services\PublicLanguage::text('Search') }}">@include('search-symbol')</button><div class="header-search-wrap" data-place-search id="mp-site-search"><form class="header-search" action="{{ route('public.search') }}" role="search"><label class="visually-hidden" for="site-search">{{ \App\Services\PublicLanguage::text('Search places, PIN codes and public records') }}</label><input id="site-search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="search-suggestions" autocomplete="off" name="q" type="search" maxlength="100" minlength="2" value="{{ request()->routeIs('public.search') ? request('q') : (request()->routeIs('postal-areas.index') ? request('pin') : '') }}" placeholder="{{ \App\Services\PublicLanguage::text('Search district, PC, AC, village or PIN') }}" required><button type="submit" aria-label="{{ \App\Services\PublicLanguage::text('Search') }}">@include('search-symbol')</button></form><div id="search-suggestions" class="search-suggestions" role="listbox" hidden></div><span id="search-suggestion-status" class="visually-hidden" role="status"></span></div><button type="button" class="public-menu-toggle" aria-label="Menu" aria-controls="public-navigation" aria-expanded="false"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><span>Menu</span></button><nav id="public-navigation" aria-label="{{ \App\Services\PublicLanguage::text('Main navigation') }}">@php $menuStates=app(\App\Services\ElectionGeographySummary::class)->states(); @endphp

<a class="public-primary-link" href="{{ route('my-place.index', ['lang' => app()->getLocale()]) }}">{{ app()->getLocale() === 'hi' ? 'मेरा क्षेत्र' : 'My place' }}</a>

@foreach(['elections'=>'Elections','census'=>'Census'] as $topic=>$label)

<details class="topic-menu"><summary>{{ \App\Services\PublicLanguage::text($label) }} <svg class="menu-chevron" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="m4 6 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></summary><div class="state-megamenu"><div class="mega-national">
<a class="mega-all-india" href="{{ route($topic==='elections'?'elections.all-india':'civic.index',app()->getLocale()==='hi'?['lang'=>'hi']:[]) }}"><strong>{{ app()->getLocale()==='hi'?'अखिल भारत':'All India' }}</strong></a>
</div><div class="mega-groups">
@foreach(['State'=>'States','Union Territory'=>'Union Territories'] as $group=>$groupLabel)<section><h2>{{ \App\Services\PublicLanguage::text($groupLabel) }}</h2><div class="mega-state-links">@foreach($menuStates->where('kind',$group) as $item)@if($topic==='elections' || isset($censusMenu[$item['slug']]))
<a href="{{ $topic==='elections'?route('states.show',['state'=>$item['slug']]+$menuLang):route('civic.state',['state'=>\App\Services\PlaceFactTables::stateSlug($censusMenu[$item['slug']]->name)]+$menuLang) }}">{{ \App\Services\PublicLanguage::text($item['name']) }}</a>
@else<span class="menu-state-unavailable">{{ \App\Services\PublicLanguage::text($item['name']) }} <small>{{ $menuLang?'2011 प्रोफ़ाइल अनुपलब्ध':'2011 profile unavailable' }}</small></span>@endif
@endforeach</div></section>@endforeach

</div></div></details>

@endforeach





@auth

<a class="public-account-link" href="{{ auth()->user()->is_admin ? route('admin.dashboard') : route('community.account') }}"><svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="10" cy="6" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M4 17v-2a6 6 0 0 1 12 0v2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>{{ auth()->user()->is_admin ? 'Admin' : 'Account' }}</a>

@else

<a class="public-account-link" href="{{ route('community.login') }}"><svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="10" cy="6" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M4 17v-2a6 6 0 0 1 12 0v2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>Sign in</a>

@endauth
@foreach($site['links'] as $link)@if(!in_array($link['url'],['/india#states','/india/census/explore','/india/elections/lok-sabha','/india/elections/all-india','/india/sir','/india/map','/sources']))<a href="{{ $link['url'] }}">{{ \App\Services\PublicLanguage::text($link['label']) }}</a>@endif @endforeach



</nav><div class="language-switch" aria-label="Language / भाषा"><a href="{{ request()->routeIs('census-catalogue.index')?\App\Services\CensusPlaceNavigation::catalogueUrl(array_replace(request()->query(),['lang'=>'en'])):request()->fullUrlWithQuery(['lang'=>'en']) }}" lang="en" @if(app()->getLocale()==='en') aria-current="true" @endif>English</a><span aria-hidden="true">/</span><a href="{{ request()->routeIs('census-catalogue.index')?\App\Services\CensusPlaceNavigation::catalogueUrl(array_replace(request()->query(),['lang'=>'hi'])):request()->fullUrlWithQuery(['lang'=>'hi']) }}" lang="hi" @if(app()->getLocale()==='hi') aria-current="true" @endif>हिन्दी</a></div></header>



<script src="{{ asset('js/header-search.js') }}?v={{ substr(hash_file('sha256',public_path('js/header-search.js')),0,12) }}" defer></script>

<style>.public-header.site-header nav a.public-account-link{display:inline-flex;align-items:center;justify-content:center;gap:8px;box-sizing:border-box;height:36px;min-height:36px;padding:6px 12px;border:1px solid currentColor;border-radius:8px;font-size:13px;font-weight:700;line-height:1.2;text-decoration:none;white-space:nowrap;transition:background .15s ease,box-shadow .15s ease}.public-account-link:hover{background:color-mix(in srgb,currentColor 10%,transparent)}.public-account-link:focus-visible{outline:3px solid var(--site-accent,#d6b667);outline-offset:3px}.public-account-link svg{flex-shrink:0}</style>




@if(app()->getLocale()==='hi')<script type="application/json" id="public-hindi-dictionary" data-place-names-url="{{ route('language.hindi.names') }}">@php($headerTranslations = (empty($summaryLayout) || !empty($fullHeaderDictionary)) ? config('public_translations', []) : \Illuminate\Support\Arr::only(config('public_translations', []), ['State','District','Subdistrict','Village','Town','Gram Panchayat','Assembly constituency','Parliamentary constituency','Search','Census','Searching…','No suggestions. Press Search to view available results.','Suggestions unavailable. Press Search to continue.']))@json($headerTranslations)</script>@endif

<script src="{{ asset('js/public-header-locale.js').'?v='.substr(hash_file('sha256',public_path('js/public-header-locale.js')),0,12) }}" defer></script>



<link rel="stylesheet" href="{{ asset('css/public-design.css') }}?v={{ substr(hash_file('sha256',public_path('css/public-design.css')),0,12) }}">



@if(empty($summaryLayout))
<script src="{{ asset('js/sortable-tables.js') }}?v={{ substr(hash_file('sha256',public_path('js/sortable-tables.js')),0,12) }}" defer></script>
@endif



@if(! request()->is('india/census/explore', 'india/census/source-tables*', 'india/elections/maps*', 'india/elections/village-map') && request()->is('india/state/*', 'india/states/*', 'india/districts/*', 'india/subdistricts/*', 'india/village/*', 'india/villages/*', 'india/district/*', 'india/towns/*', 'india/census/*', 'india/elections*', 'india/constituency', 'india/constituencies*', 'india/places/*', 'india/pc/*', 'india/ac/*'))



@if(empty($summaryLayout)) @include('compact-place-assets') @endif
@endif


@if(request()->is('my-place','my-place/pilibhit'))
@if(empty($summaryLayout)) @include('compact-place-assets') @endif
@endif
