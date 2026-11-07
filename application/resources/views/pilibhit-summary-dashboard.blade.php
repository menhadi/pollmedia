@php
    $tr=fn($en,$hindi)=>$hi?$hindi:$en;
    $path=route('district-dashboard.named',['state'=>'uttar-pradesh','district'=>'pilibhit']);
    $url=fn($key)=>$path.'?'.http_build_query(array_filter(['topic'=>$key==='overview'?null:$key,'view'=>in_array($key,['overview','places'],true)?null:'details','lang'=>$hi?'hi':null]));
    $gpUrl=route('my-place.index',['state'=>9,'district'=>173,'topic'=>'gram-panchayat','lang'=>$hi?'hi':null]);
    $number=new \NumberFormatter('en_IN',\NumberFormatter::DECIMAL);
    $fmt=fn($n)=>$n===null?'—':$number->format($n);
    $pct=fn($n,$precision=1)=>$n===null?'—':number_format($n,$precision).'%';
    $share=fn($n,$den)=>$n!==null && $den>0?100*$n/$den:null;
    $total=$data['totals']['Total'];
    $census=$tr('Census','जनगणना').' '.$data['year'];
    $extra=collect(\App\Services\PlaceTopics::GROUPS['district'])->diff(['overview','water','people','places']);
@endphp
<!doctype html><html lang="{{ $hi?'hi':'en' }}"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $tr('Pilibhit district','पीलीभीत ज़िला') }} · Pollmedia</title>
<meta name="description" content="{{ $tr('Pilibhit district at a glance. Explore Census population trends, Panchayats, villages, satellite vegetation readings, mandi prices and public offices.','पीलीभीत ज़िले की जनसंख्या के रुझान, पंचायतें, गाँव, उपग्रह हरियाली सूचकांक, मंडी भाव और सरकारी कार्यालय देखें।') }}">
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/pilibhit-summary.css') }}?v=5">
<script defer src="{{ asset('js/pilibhit-summary.js') }}?v=1"></script></head>
<body class="election-ui pb-site">@include('public-header',['summaryLayout'=>true])
<main class="pb-dashboard" data-pilibhit-native data-pb-lang="{{ $hi?'hi':'en' }}">
<nav class="pb-crumb" aria-label="{{ $tr('Breadcrumb','स्थान क्रम') }}"><a href="{{ route('home') }}">{{ $tr('India','भारत') }}</a><span>›</span><a href="{{ route('civic.state',['state'=>'uttar-pradesh','lang'=>$hi?'hi':null]) }}">{{ $tr('Uttar Pradesh','उत्तर प्रदेश') }}</a><span>›</span><span>{{ $tr('Pilibhit','पीलीभीत') }}</span></nav>
<h1>{{ $tr('Pilibhit district','पीलीभीत ज़िला') }}</h1><p class="pb-scope">{{ $tr('Uttar Pradesh · District dashboard','उत्तर प्रदेश · ज़िला डैशबोर्ड') }}</p>
<p class="pb-view-switch"><a href="{{ $path.'?'.http_build_query(array_filter(['view'=>'details','topic'=>$topic==='places'?'places':null,'lang'=>$hi?'hi':null])) }}">{{ $tr('Open full district dashboard','विस्तृत ज़िला डैशबोर्ड खोलें') }} →</a></p>
<nav class="pb-tabs" data-place-topics="district" data-active-topic="{{ $topic }}" aria-label="{{ $tr('District topics','ज़िले के विषय') }}">
@foreach(['overview','water','people','places'] as $key)<a data-topic="{{ $key }}" href="{{ $url($key) }}" @if($key===$topic) aria-current="page" @endif>{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }}</a>@endforeach
<details><summary>{{ $tr('More','अन्य') }}</summary><div>@foreach($extra as $key)<a data-topic="{{ $key }}" href="{{ $key==='panchayats'?$gpUrl:$url($key) }}">{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }}</a>@endforeach</div></details>
</nav>
<div data-topic-body>
@if($topic==='overview')
<div class="pb-grid">
<section class="pb-panel" id="overview"><h2>{{ $tr('District overview','ज़िले का सारांश') }}</h2>
<table class="pb-metrics"><thead><tr><th scope="col">{{ $tr('Measure','माप') }}</th><th scope="col">{{ $tr('Value','मान') }}</th><th scope="col">{{ $tr('Reference','संदर्भ') }}</th></tr></thead><tbody>
@foreach([[$tr('Population','जनसंख्या'),$fmt($total['population'])],[$tr('Households','परिवार'),$fmt($total['households'])],[$tr('Literacy, age 7+','साक्षरता, आयु 7+'),$pct($total['literacy'],2)],[$tr('Rural population share','ग्रामीण जनसंख्या का हिस्सा'),$pct($share($data['totals']['Rural']['population']??null,$total['population']))]] as [$name,$value])
<tr><th scope="row">{{ $name }}</th><td>{{ $value }}</td><td>{{ $census }}</td></tr>
@endforeach</tbody></table>
<details class="pb-extra"><summary>{{ $tr('More Census figures','जनगणना के अन्य आँकड़े') }}</summary><table class="pb-metrics"><tbody>
@foreach([[$tr('Female per 1,000 male','प्रति 1,000 पुरुषों पर महिलाएँ'),$total['male']>0 && $total['female']!==null?$fmt(round(1000*$total['female']/$total['male'])):'—'],[$tr('Population aged 0–6','आयु 0–6 की जनसंख्या'),$fmt($total['children'])],[$tr('Male literacy age 7+','पुरुष साक्षरता, आयु 7+'),$pct($total['male_literacy'])],[$tr('Female literacy age 7+','महिला साक्षरता, आयु 7+'),$pct($total['female_literacy'])],[$tr('Worker participation','कार्य सहभागिता'),$pct($share($total['workers'],$total['population']))]] as [$name,$value])
<tr><th scope="row">{{ $name }}</th><td>{{ $value }}</td><td>{{ $census }}</td></tr>
@endforeach</tbody></table></details></section>
<section class="pb-panel pb-water" id="water"><h2>{{ $tr('Household tap water','घरेलू नल जल') }}</h2>
@if($water)
@php($coverage=$share($water['summary']['tap_connections'],$water['summary']['households']))
<strong class="pb-big">{{ $pct($coverage,2) }}</strong><p>{{ $tr('Reported tap connection coverage','रिपोर्ट किया गया नल कनेक्शन कवरेज') }}</p>
@if($coverage!==null)<div class="pb-track" aria-hidden="true"><i style="width:{{ min(100,max(0,$coverage)) }}%"></i></div>@endif
<div class="pb-pairs"><div><strong>{{ $fmt($water['summary']['tap_connections']) }}</strong><small>{{ $tr('Tap connections','नल कनेक्शन') }}</small></div><div><strong>{{ $fmt($water['summary']['households']) }}</strong><small>{{ $tr('Households in report','रिपोर्ट में परिवार') }}</small></div></div>
<p class="pb-source"><a href="{{ $water['source_url'] }}">Jal Jeevan Mission</a> · {{ $tr('Retrieved','प्राप्त') }} {{ \Illuminate\Support\Carbon::parse($water['retrieved_at'])->locale($hi?'hi':'en')->translatedFormat('j F Y') }}</p>
@else<p>{{ $tr('The water snapshot is currently unavailable.','जल रिपोर्ट अभी उपलब्ध नहीं है।') }}</p>@endif
<a class="pb-more" href="{{ $url('water') }}">{{ $tr('Explore water records','जल रिकॉर्ड देखें') }} →</a></section>
</div>
<section class="pb-panel pb-wide" id="population"><h2>{{ $tr('Population by subdistrict','उपजिले के अनुसार जनसंख्या') }}</h2><p class="pb-sub">{{ $tr('Residents and share of district population','निवासी और ज़िले की जनसंख्या में हिस्सा') }} · {{ $census }}</p>
@foreach(collect($data['subdistricts'])->sortByDesc('population') as $sub)
@php($part=$share($sub['population'],$total['population']))
<div class="pb-row"><a href="{{ route('civic.place',['record'=>$sub['id'],'lang'=>$hi?'hi':null]) }}">{{ $sub['name'] }}</a><div class="pb-track" aria-hidden="true">@if($part!==null)<i style="width:{{ min(100,max(0,$part)) }}%"></i>@endif</div><strong>{{ $fmt($sub['population']) }}</strong><small>{{ $pct($part) }}</small></div>
@endforeach
<a class="pb-more" href="{{ $url('people') }}">{{ $tr('Explore population records','जनसंख्या रिकॉर्ड देखें') }} →</a></section>
@include('pilibhit-district-previews')
@include('pilibhit-anchor-topics')
@endif
<section class="pb-panel pb-wide" id="places"><div class="pb-search"><div><h2>{{ $tr('Village directory','गाँव निर्देशिका') }}</h2><p class="pb-sub">{{ $census }}</p></div>
<form data-pb-form method="get" action="{{ $path }}#places">
@if($topic==='places')<input type="hidden" name="topic" value="places">@endif @if($hi)<input type="hidden" name="lang" value="hi">@endif
<input type="search" name="q" maxlength="120" aria-label="{{ $tr('Village name or Census code','गाँव का नाम या जनगणना कोड') }}" placeholder="{{ $tr('Village name or Census code','गाँव का नाम या जनगणना कोड') }}" value="{{ $search }}"><button>{{ $tr('Search','खोजें') }}</button></form></div>
<p class="pb-status" role="status" data-pb-status></p>
@include('pilibhit-summary-villages')
@if($topic==='overview')<a class="pb-more" href="{{ $url('places') }}#places">{{ $tr('Browse all villages','सभी गाँव देखें') }} →</a>
@else
@foreach(['subdistricts'=>$tr('Subdistricts','उपजिले'),'towns'=>$tr('Towns','कस्बे')] as $kind=>$heading)
@if($data[$kind])<h2>{{ $heading }}</h2><ul class="pb-locations">@foreach($data[$kind] as $place)<li><a href="{{ route('civic.place',['record'=>$place['id'],'lang'=>$hi?'hi':null]) }}">{{ $place['name'] }}</a></li>@endforeach</ul>@endif
@endforeach
@endif</section>
@if($topic==='overview')
<details class="pb-panel pb-wide pb-other-records"><summary>{{ $tr('More district records','ज़िले के अन्य रिकॉर्ड') }}</summary>
<ul class="pb-topic-index">
@foreach(['map'=>['Village boundaries and satellite layers','गाँव की सीमाएँ और उपग्रह चित्र'],'issues'=>['Local issue reports','स्थानीय समस्याओं की रिपोर्ट'],'sources'=>['Data references and coverage','आँकड़ों के स्रोत और कवरेज']] as $key=>$description)
<li><a data-pb-preview="{{ $key }}" href="{{ $url($key) }}"><b>{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }}</b><span>{{ $description[$hi?1:0] }}</span><i aria-hidden="true">→</i></a></li>
@endforeach
</ul>
</details>
@endif
</div></main>@include('public-footer')</body></html>
