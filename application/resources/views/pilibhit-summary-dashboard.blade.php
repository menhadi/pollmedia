@php
    $tr=fn($en,$hindi)=>$hi?$hindi:$en;
    $path=url($identity['path']);
    $title=$pilot?$tr('Pilibhit district','पीलीभीत ज़िला'):$data['name'].' '.$tr('district','ज़िला');
    $stateName=$pilot?$tr('Uttar Pradesh','उत्तर प्रदेश'):$identity['state_name'];
    $stateSlug=explode('/',$identity['path'])[3];
    $url=fn($key)=>$path.'?'.http_build_query(array_filter(['topic'=>$key==='overview'?null:$key,'view'=>in_array($key,['overview','places'],true)?null:'details','lang'=>$hi?'hi':null]));
    $parent=$pilot?['9','173']:($previews['directory']['parent']??null);
    $gpDistricts=$previews['directory']['parents']??[];
    $parent??=$gpDistricts ? ['9',array_key_first($gpDistricts)] : null;
    $gpUrl=$parent?route('gram-panchayats.area',['scope'=>'lgd','state'=>$parent[0],'district'=>$parent[1],'lang'=>$hi?'hi':null]):route('gram-panchayats.area',['scope'=>'census','state'=>(int)$identity['state'],'district'=>(int)$identity['district'],'lang'=>$hi?'hi':null]);
    $number=new \NumberFormatter('en_IN',\NumberFormatter::DECIMAL);
    $fmt=fn($n)=>$n===null?'—':$number->format($n);
    $pct=fn($n,$precision=1)=>$n===null?'—':number_format($n,$precision).'%';
    $share=fn($n,$den)=>$n!==null && $den>0?100*$n/$den:null;
    $total=$data['totals']['Total'];
    $census=$tr('Census','जनगणना').' '.$data['year'];
    $visible=['overview'=>true,'people'=>true,'water'=>(bool)$water,'places'=>$villages->total()>0 || $data['subdistricts'] || $data['towns'] || $topic==='places',
        'panchayats'=>!empty($previews['directory']),'map'=>!empty($previews['hasMap']),'rainfall'=>!empty($previews['rain']),'soil'=>!empty($previews['soil']),
        'history'=>count($data['history']['rows']??[])>1,'elections'=>!empty($previews['elections']),'authorities'=>!empty($previews['offices']),
        'markets'=>collect($previews['prices']??[])->isNotEmpty(),'issues'=>$pilot,'sources'=>true];
    $extra=collect(\App\Services\PlaceTopics::GROUPS['district'])->filter(fn($key)=>$visible[$key]??false)->diff(['overview','water','people','places']);
@endphp
<!doctype html><html lang="{{ $hi?'hi':'en' }}"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }} · Pollmedia</title>
<meta name="description" content="{{ $title }} · {{ $tr('District figures, places and published records','ज़िले के आँकड़े, स्थान और प्रकाशित रिकॉर्ड') }}">
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/pilibhit-summary.css') }}?v=9">
<script defer src="{{ asset('js/pilibhit-summary.js') }}?v=1"></script><link rel="stylesheet" href="{{ asset('css/compact-area-pages.css') }}?v={{ substr(hash_file('sha256',public_path('css/compact-area-pages.css')),0,12) }}"><link rel="stylesheet" href="{{ asset('css/static-district-map.css') }}?v={{ substr(hash_file('sha256',public_path('css/static-district-map.css')),0,12) }}"><script src="{{ asset('js/static-district-map.js') }}?v={{ substr(hash_file('sha256',public_path('js/static-district-map.js')),0,12) }}" defer></script></head>
<body class="election-ui pb-site">@include('public-header',['summaryLayout'=>true])
<main class="pb-dashboard compact-district" data-district-summary data-pilibhit-native data-pb-lang="{{ $hi?'hi':'en' }}">
<nav class="pb-crumb" aria-label="{{ $tr('Breadcrumb','स्थान क्रम') }}"><a href="{{ route('home') }}">{{ $tr('India','भारत') }}</a><span>›</span><a href="{{ route('civic.state',['state'=>$stateSlug,'lang'=>$hi?'hi':null]) }}">{{ $stateName }}</a><span>›</span><span>{{ $pilot?$tr('Pilibhit','पीलीभीत'):$data['name'] }}</span></nav>
<h1>{{ $title }}</h1><p class="pb-scope">{{ $stateName }} · {{ $tr('District dashboard','ज़िला डैशबोर्ड') }}</p>
<p class="pb-view-switch"><a href="{{ $path.'?'.http_build_query(array_filter(['view'=>'details','topic'=>$topic==='places'?'places':null,'lang'=>$hi?'hi':null])) }}">{{ $tr('Open full district dashboard','विस्तृत ज़िला डैशबोर्ड खोलें') }} →</a></p>
@include('local-board-place-link', ['boardArea' => app(\App\Services\LocalBoardLocations::class)->districtKey($identity['state_name'], $identity['name'])])
<nav class="pb-tabs" data-place-topics="district" data-active-topic="{{ $topic }}" aria-label="{{ $tr('District topics','ज़िले के विषय') }}">
@foreach(['overview','water','people','places'] as $key)@continue(empty($visible[$key]))<a data-topic="{{ $key }}" href="{{ $url($key) }}" @if($key===$topic) aria-current="page" @endif>{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }}</a>@endforeach
<details><summary>{{ $tr('More','अन्य') }}</summary><div>@foreach($extra as $key)@continue(empty($visible[$key]))<a data-topic="{{ $key }}" href="{{ $key==='panchayats'?$gpUrl:$url($key) }}">{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }}</a>@endforeach</div></details>
</nav>
<div data-topic-body>
@if($topic==='overview')
<section class="compact-district-overview" id="overview" aria-label="{{ $tr('District overview','ज़िले का सारांश') }}">
<p class="pb-sub">{{ $census }}</p>
<dl class="compact-facts">
@foreach([[$tr('Population','जनसंख्या'),$fmt($total['population'])],[$tr('Households','परिवार'),$fmt($total['households'])],[$tr('Literacy, age 7+','साक्षरता, आयु 7+'),$pct($total['literacy'],2)]] as [$label,$value])
@if($value!=='—')<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endif
@endforeach
</dl>
@include('residence-breakdown',['populationTotal'=>$total['population'],'ruralCount'=>$data['totals']['Rural']['population']??null,'urbanCount'=>$data['totals']['Urban']['population']??null])
<details class="pb-extra"><summary>{{ $tr('More Census figures','जनगणना के अन्य आँकड़े') }}</summary><table class="pb-metrics"><tbody>
@if($data['subdistricts'] || $data['towns'])
<tr><th scope="row">{{ $tr('Places','स्थान') }}</th><td>@foreach(['subdistricts'=>$tr('subdistricts','उपज़िले'),'towns'=>$tr('towns','कस्बे')] as $kind=>$label)@if($data[$kind])<a href="{{ $url('places') }}#pb-{{ $kind }}">{{ $fmt(count($data[$kind])) }} {{ $label }}</a>@if($kind==='subdistricts' && $data['towns']) · @endif @endif @endforeach</td></tr>
@endif
@foreach([[$tr('Female per 1,000 male','प्रति 1,000 पुरुषों पर महिलाएँ'),$total['male']>0 && $total['female']!==null?$fmt(round(1000*$total['female']/$total['male'])):'—'],[$tr('Population aged 0–6','आयु 0–6 की जनसंख्या'),$fmt($total['children'])],[$tr('Male literacy age 7+','पुरुष साक्षरता, आयु 7+'),$pct($total['male_literacy'])],[$tr('Female literacy age 7+','महिला साक्षरता, आयु 7+'),$pct($total['female_literacy'])],[$tr('Worker participation','कार्य सहभागिता'),$pct($share($total['workers'],$total['population']))]] as [$name,$value])
@continue($value==='—')<tr><th scope="row">{{ $name }}</th><td>{{ $value }}</td></tr>
@endforeach</tbody></table></details></section>
@include('district-village-map')<div class="compact-district-directory">
@include('district-summary-place-directory')
@if(!empty($previews['satellite']))
@include('district-summary-vegetation',['ndvi'=>$previews['satellite']])
@endif
</div>
@if($water)
<section class="pb-panel pb-water" id="water"><h2>{{ $tr('Household tap water','घरेलू नल जल') }}</h2>
@unless($pilot)<p class="pb-sub">{{ $fmt($water['summary']['villages']) }} {{ $tr('linked villages with reported household counts','संबंधित गाँव जहाँ परिवारों की संख्या दर्ज है') }}</p>@endunless
@if($water)
@php($coverage=$share($water['summary']['tap_connections'],$water['summary']['households']))
<strong class="pb-big">{{ $pct($coverage,2) }}</strong><p>{{ $tr('Reported tap connection coverage','रिपोर्ट किया गया नल कनेक्शन कवरेज') }}</p>
@if($coverage!==null)<div class="pb-track" aria-hidden="true"><i style="width:{{ min(100,max(0,$coverage)) }}%"></i></div>@endif
<div class="pb-pairs"><div><strong>{{ $fmt($water['summary']['tap_connections']) }}</strong><small>{{ $tr('Tap connections','नल कनेक्शन') }}</small></div><div><strong>{{ $fmt($water['summary']['households']) }}</strong><small>{{ $tr('Households in report','रिपोर्ट में परिवार') }}</small></div></div>
@if($coverage !== null && $water['summary']['tap_connections'] >= 0 && $water['summary']['households'] >= $water['summary']['tap_connections'])
<p><strong>{{ $fmt($water['summary']['households'] - $water['summary']['tap_connections']) }}</strong> {{ $tr('households without a reported tap connection','परिवारों के लिए नल कनेक्शन दर्ज नहीं था') }}</p>
@endif
@else<p>{{ $tr('The water snapshot is currently unavailable.','जल रिपोर्ट अभी उपलब्ध नहीं है।') }}</p>@endif
<a class="pb-more" href="{{ $url('water') }}">{{ $tr('Explore water records','जल रिकॉर्ड देखें') }} →</a></section>
@endif
@if($data['subdistricts'])
<section class="pb-panel pb-wide" id="population"><h2>{{ count($data['subdistricts'])>5?$tr('Five most populous subdistricts','सर्वाधिक जनसंख्या वाले पाँच उपज़िले'):$tr('Population by subdistrict','उपजिले के अनुसार जनसंख्या') }}</h2><p class="pb-sub">{{ $tr('Residents and share of district population','निवासी और ज़िले की जनसंख्या में हिस्सा') }} · {{ $census }}</p>
@foreach(collect($data['subdistricts'])->whereNotNull('population')->sortByDesc('population')->take(5) as $sub)
@php($part=$share($sub['population'],$total['population']))
<div class="pb-row"><a href="{{ app(\App\Services\NamedPlaceUrls::class)->link(['record'=>$sub['id'],'lang'=>$hi?'hi':null]) }}">{{ $sub['name'] }}</a><div class="pb-track" aria-hidden="true">@if($part!==null)<i style="width:{{ min(100,max(0,$part)) }}%"></i>@endif</div><strong>{{ $fmt($sub['population']) }}</strong><small>{{ $pct($part) }}</small></div>
@endforeach
<a class="pb-more" href="{{ $url('people') }}">{{ $tr('Explore population records','जनसंख्या रिकॉर्ड देखें') }} →</a></section>
@endif
@if(!empty($previews['directory']) || !empty($previews['soil']) || !empty($previews['elections']) || !empty($previews['rain']))
@include('pilibhit-district-previews')
@endif
@include('pilibhit-anchor-topics')
@endif
@if($topic==='places')
@include('district-summary-place-directory')
@endif
@if($topic==='overview')
<details class="pb-panel pb-wide pb-other-records"><summary>{{ $tr('More district records','ज़िले के अन्य रिकॉर्ड') }}</summary>
<ul class="pb-topic-index">
@foreach(['map'=>['Village boundaries and satellite layers','गाँव की सीमाएँ और उपग्रह चित्र'],'issues'=>['Local issue reports','स्थानीय समस्याओं की रिपोर्ट'],'sources'=>['Data references and coverage','आँकड़ों के स्रोत और कवरेज']] as $key=>$description)
@continue(empty($visible[$key]))<li><a data-pb-preview="{{ $key }}" href="{{ $url($key) }}"><b>{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }}</b><span>{{ $description[$hi?1:0] }}</span><i aria-hidden="true">→</i></a></li>
@endforeach
</ul>
</details>
@endif
</div></main>@include('public-footer')</body></html>
