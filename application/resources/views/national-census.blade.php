@php
    $hi = app()->getLocale() === 'hi';
    $tr = fn ($en, $hn) => $hi ? $hn : $en;
    $lang = $hi ? ['lang'=>'hi'] : [];
    $title = $tr('All India Census', 'अखिल भारतीय जनगणना');
    $points = collect($nationalSeries['rows'])->whereNotNull('population')->sortBy('year')->values();
    $latest = $points->last();
    $chart = $points->take(-6);
    $topicLabels = ['population'=>$tr('Population','जनसंख्या'),'households'=>$tr('Households','परिवार'),'literacy'=>$tr('Literacy','साक्षरता'),'work'=>$tr('Work','कामकाज')];
    $fieldLabels = ['No_HH'=>$tr('Households','परिवार'),'P_LIT'=>$tr('Literate people','साक्षर लोग'),'M_LIT'=>$tr('Literate males','साक्षर पुरुष'),'F_LIT'=>$tr('Literate females','साक्षर महिलाएँ'),'TOT_WORK_P'=>$tr('Total workers','कुल कामगार'),'MAINWORK_P'=>$tr('Main workers','मुख्य कामगार'),'MARGWORK_P'=>$tr('Marginal workers','सीमांत कामगार')];
@endphp
<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
@include('seo-metadata',['canonical'=>route('civic.index',$lang),'seoTitle'=>$title.' · Pollmedia','seoDescription'=>$tr('Explore India’s recorded population, decadal growth, state profiles and original Census tables.','भारत की आबादी, दशकीय वृद्धि, राज्य प्रोफ़ाइल और मूल जनगणना तालिकाएँ देखें।'),'breadcrumbs'=>['Pollmedia'=>url('/'),$title=>route('civic.index',$lang)]])
@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/national-dashboards.css') }}?v={{ substr(hash_file('sha256',public_path('css/national-dashboards.css')),0,12) }}">
<script src="{{ asset('js/national-census.js') }}?v={{ substr(hash_file('sha256',public_path('js/national-census.js')),0,12) }}" defer></script></head><body>@include('public-header',['summaryLayout'=>true])
<main class="india-hub census-hub">
<nav class="hub-crumb" aria-label="Breadcrumb"><a href="{{ route('home',$lang) }}">{{ $tr('India','भारत') }}</a> / {{ $tr('Census','जनगणना') }}</nav>
<header class="hub-hero">
<div class="hub-intro"><h1>{{ $title }}</h1>
@if($latest)<div class="hub-lead-number">{{ number_format($latest['population']) }}</div>
<p>{{ $tr('People recorded in the','दर्ज जनसंख्या ·') }} {{ $latest['year'] }} {{ $tr('Census of India.','की भारतीय जनगणना।') }}</p>@endif
<div class="hub-actions"><a href="#census-states">{{ $tr('Explore states & UTs','राज्य और केंद्र शासित प्रदेश देखें') }} ↓</a><a href="#population-records">{{ $tr('Population records','जनसंख्या रिकॉर्ड') }} ↓</a></div>
</div>
@if($chart->isNotEmpty())
<section class="hub-chart"><h2>{{ $tr('Population by Census year','जनगणना वर्ष के अनुसार आबादी') }}</h2>
<ol class="hub-columns">@foreach($chart as $point)
<li><span class="hub-bar-value">{{ number_format($point['population']/10000000,1) }}</span><i style="--bar:{{ 100*$point['population']/max(1,$chart->max('population')) }}%"></i><span>{{ $point['year'] }}</span></li>
@endforeach</ol><p>{{ $tr('Population in crore','जनसंख्या करोड़ में') }}</p></section>@endif
</header>
@if($latest)
<div class="census-metrics">
@foreach([[$tr('Male population','पुरुष जनसंख्या'),$latest['male']??null,''],[$tr('Female population','महिला जनसंख्या'),$latest['female']??null,''],[$tr('Decadal growth','दशकीय वृद्धि'),$latest['growth']??null,'%']] as [$label,$value,$unit])
<section><span>{{ $label }} · {{ $latest['year'] }}</span><strong>{{ $value===null?'—':number_format($value,$unit==='%'?2:0).$unit }}</strong></section>
@endforeach</div>@endif
<section id="population-records" class="hub-records" aria-labelledby="population-heading">
<div class="records-heading"><h2 id="population-heading">{{ $censusTopic==='population'?$tr('Population records','जनसंख्या रिकॉर्ड'):$topicLabels[$censusTopic] }}</h2><span>{{ $censusYear }}</span></div>
<form class="records-filters census-filters" method="get" action="{{ route('civic.index') }}#population-records">
@if($censusTopic!=='population')<input type="hidden" name="census_topic" value="{{ $censusTopic }}">@endif
@if($hi)<input type="hidden" name="lang"  value="hi">@endif
<label>{{ $tr('Census year','जनगणना वर्ष') }}<select name="census_year">@foreach($censusYears as $choice)<option @selected($censusYear===$choice)>{{ $choice }}</option>@endforeach</select></label>
<label>{{ $tr('Area','क्षेत्र') }}<select name="census_area"><option value="">{{ $tr('India, states and UTs','भारत, राज्य और यूटी') }}</option>@foreach($areas as $area)<option value="{{ $area['state_code'] }}" @selected($censusArea===$area['state_code'])>{{ $area['state_code']==='00'?$tr('All India','अखिल भारत'):$area['name'] }}</option>@endforeach</select></label>
<button type="submit">{{ $tr('Show records','रिकॉर्ड देखें') }}</button></form>
@if(count($topicFields))
<nav class="census-topics" aria-label="{{ $tr('Census measures','जनगणना संकेतक') }}">
@foreach(array_merge(['population'],array_keys($topicFields)) as $topic)
<a href="{{ route('civic.index',['census_year'=>$censusYear,'census_area'=>$censusArea,'census_topic'=>$topic==='population'?null:$topic]+$lang) }}#population-records" @if($topic===$censusTopic) aria-current="page" @endif>{{ $topicLabels[$topic] }}</a>
@endforeach</nav>@endif
<div class="national-scroll" tabindex="0" role="region" aria-labelledby="population-heading"><table class="records-table"><thead><tr>
<th scope="col">{{ $tr('Area','क्षेत्र') }}</th>
@if($censusTopic==='population')
@foreach([['Population','जनसंख्या'],['Male','पुरुष'],['Female','महिला'],['Decadal growth (%)','दशकीय वृद्धि (%)']] as [$en,$hn])<th scope="col">{{ $tr($en,$hn) }}</th>@endforeach
@else
@foreach($topicFields[$censusTopic] as $key)<th scope="col">{{ $fieldLabels[$key] }}</th>@endforeach
@endif
</tr></thead><tbody>
@forelse($populationRows as $area)
<tr><th scope="row">{{ $area['state_code']==='00'?$tr('All India','अखिल भारत'):$area['name'] }}</th>
@if($censusTopic==='population')
@foreach(['persons','males','females'] as $key)<td>{{ is_numeric($area[$key]??null)?number_format($area[$key]):'—' }}</td>@endforeach
<td>{{ is_numeric(trim($area['percentage']??''))?number_format((float)$area['percentage'],2).'%':'—' }}</td>
@else
@foreach($topicFields[$censusTopic] as $key)<td>{{ is_numeric($abstract->get($area['state_code'])[$key]??null)?number_format($abstract->get($area['state_code'])[$key]):'—' }}</td>@endforeach
@endif
</tr>
@empty<tr><td colspan="5">{{ $tr('No population records for this selection.','इस चयन के लिए कोई जनसंख्या रिकॉर्ड नहीं है।') }}</td></tr>@endforelse
</tbody></table></div>
@if($populationRows->count()>20)<button type="button" data-more-areas hidden>{{ $tr('Show all areas','सभी क्षेत्र दिखाएँ') }} ({{ $populationRows->count() }})</button>@endif
</section>
<section id="census-states" class="hub-panel visible-directory"><div class="hub-panel-title"><h2>{{ $tr('Choose a state or UT','राज्य या केंद्र शासित प्रदेश चुनें') }}</h2><span>{{ $tr('Census geography','जनगणना भूगोल') }} · {{ $year }}</span></div>
@if($stateOptions->isNotEmpty())<ul class="hub-places">@foreach($stateOptions as $area)<li><a href="{{ route('civic.state',['state'=>\App\Services\PlaceFactTables::stateSlug($area->name),'census'=>(int)$year===2011?null:$year]+$lang) }}">{{ $area->display_name }} <span aria-hidden="true">→</span></a></li>@endforeach</ul>
@else<p>{{ $tr('State profiles are unavailable for this edition.','इस संस्करण में राज्य प्रोफ़ाइल उपलब्ध नहीं हैं।') }}</p>@endif
</section>
<details class="hub-panel hub-disclaimer"><summary>{{ $tr('Historical records & disclaimer','ऐतिहासिक रिकॉर्ड और अस्वीकरण') }}</summary>
<p>{{ $boundaryBasis }} {{ $tr('India and state totals overlap. Missing figures are shown with a dash.','भारत और राज्यों के कुल आँकड़े एक-दूसरे में शामिल हैं। डैश अनुपलब्ध आँकड़े दर्शाता है।') }}</p>
@php($flags=$populationRows->flatMap(fn ($area)=>$area['flags']??[])->unique())
@if($flags->isNotEmpty())<ul>@foreach($flags as $flag)<li>{{ $flag }}</li>@endforeach</ul>@endif
<table><thead><tr><th>{{ $tr('Census year','जनगणना वर्ष') }}</th><th>{{ $tr('India population','भारत की जनसंख्या') }}</th></tr></thead><tbody>
@foreach($points as $point)<tr><th scope="row">{{ $point['year'] }}</th><td>{{ number_format($point['population']) }}</td></tr>@endforeach</tbody></table></details>
</main>@include('public-footer')</body></html>
