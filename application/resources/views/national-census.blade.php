@php
    $hi = app()->getLocale() === 'hi';
    $tr = fn ($en, $hn) => $hi ? $hn : $en;
    $lang = $hi ? ['lang'=>'hi'] : [];
    $title = $tr('All India Census', 'अखिल भारतीय जनगणना');
    $points = collect($nationalSeries['rows'])->whereNotNull('population')->sortBy('year')->values();
    $latest = $points->last();
    $chart = $points->take(-6);
@endphp
<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
@include('seo-metadata',['canonical'=>route('civic.index',$lang),'seoTitle'=>$title.' · Pollmedia','seoDescription'=>$tr('Explore India’s recorded population, decadal growth, state profiles and original Census tables.','भारत की आबादी, दशकीय वृद्धि, राज्य प्रोफ़ाइल और मूल जनगणना तालिकाएँ देखें।'),'breadcrumbs'=>['Pollmedia'=>url('/'),$title=>route('civic.index',$lang)]])
@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/national-dashboards.css') }}?v={{ substr(hash_file('sha256',public_path('css/national-dashboards.css')),0,12) }}">
</head><body>@include('public-header',['summaryLayout'=>true])
<main class="india-hub census-hub">
<nav class="hub-crumb" aria-label="Breadcrumb"><a href="{{ route('home',$lang) }}">{{ $tr('India','भारत') }}</a> / {{ $tr('Census','जनगणना') }}</nav>
<header class="hub-hero">
<div class="hub-intro"><h1>{{ $title }}</h1>
@if($latest)<div class="hub-lead-number">{{ number_format($latest['population']) }}</div>
<p>{{ $tr('People recorded in the','दर्ज जनसंख्या ·') }} {{ $latest['year'] }} {{ $tr('Census of India.','की भारतीय जनगणना।') }}</p>@endif
<div class="hub-actions"><a href="#census-states">{{ $tr('Explore states & UTs','राज्य और केंद्र शासित प्रदेश देखें') }} ↓</a><a href="{{ route('census.national-history',$lang) }}">{{ $tr('Population history','जनसंख्या का इतिहास') }} →</a></div>
</div>
@if($chart->isNotEmpty())
<section class="hub-chart"><h2>{{ $tr('Population by Census year','जनगणना वर्ष के अनुसार आबादी') }}</h2>
<ol class="hub-columns">@foreach($chart as $point)
<li><span class="hub-bar-value">{{ number_format($point['population']/10000000,1) }}</span><i style="--bar:{{ 100*$point['population']/max(1,$chart->max('population')) }}%"></i><span>{{ $point['year'] }}</span></li>
@endforeach</ol><p>{{ $tr('Population in crore · Census A-02','जनसंख्या करोड़ में · जनगणना A-02') }}</p></section>@endif
</header>
<div class="hub-topics">
<section><h2>{{ $tr('Decadal growth','दशकीय वृद्धि') }}</h2>
@if($latest && $latest['growth'] !== null)<strong>{{ number_format($latest['growth'],2) }}%</strong><p>{{ $tr('Population change in the decade ending','इस वर्ष तक के दशक में जनसंख्या परिवर्तन') }} {{ $latest['year'] }}.</p>@endif
<a href="{{ route('census.national-history',['state'=>'00']+$lang) }}">{{ $tr('Compare Census years','जनगणना वर्षों की तुलना') }} →</a></section>
<section><h2>{{ $tr('Census place profiles','जनगणना क्षेत्र प्रोफ़ाइल') }}</h2><p>{{ $tr('Browse population, households, literacy and work by state, district, town or village.','राज्य, ज़िले, नगर या गाँव की आबादी, परिवार, साक्षरता और कामकाज देखें।') }}</p><a href="{{ route('civic.index',['year'=>$year,'group'=>'population']+$lang) }}">{{ $tr('Open the place explorer','क्षेत्र खोजें') }} →</a></section>
<section><h2>{{ $tr('Source tables','स्रोत तालिकाएँ') }}</h2><p>{{ $tr('Find published Census editions, original columns and geographic definitions.','प्रकाशित जनगणना संस्करण, मूल कॉलम और भौगोलिक परिभाषाएँ देखें।') }}</p><a href="{{ \App\Services\CensusPlaceNavigation::catalogueUrl($lang) }}#catalogue-editions">{{ $tr('Browse the catalogue','सूची देखें') }} →</a></section>
</div>
<section id="census-states" class="hub-panel"><div class="hub-panel-title"><h2>{{ $tr('Choose a state or UT','राज्य या केंद्र शासित प्रदेश चुनें') }}</h2><span>{{ $tr('Census geography','जनगणना भूगोल') }} · {{ $year }}</span></div>
@if($stateOptions->isNotEmpty())<ul class="hub-places">@foreach($stateOptions as $area)<li><a href="{{ route('civic.state',['state'=>\App\Services\PlaceFactTables::stateSlug($area->name),'census'=>(int)$year===2011?null:$year]+$lang) }}">{{ $area->display_name }} <span aria-hidden="true">→</span></a></li>@endforeach</ul>
@else<p>{{ $tr('State profiles are unavailable for this edition.','इस संस्करण में राज्य प्रोफ़ाइल उपलब्ध नहीं हैं।') }}</p>@endif
</section>
</main>@include('public-footer')</body></html>
