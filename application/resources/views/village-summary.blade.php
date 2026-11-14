@php
    $tr=fn($en,$hindi)=>$hi?$hindi:$en;
    $number=new \NumberFormatter('en_IN',\NumberFormatter::DECIMAL);
    $fmt=fn($v)=>$number->format($v);
    $topic=fn($key)=>$current.'?'.http_build_query(array_filter(['view'=>'details','topic'=>$key,'lang'=>$hi?'hi':null]));
    $labels=['population'=>['Residents','निवासी'],'households'=>['Households','परिवार'],'literacy'=>['Literacy, age 7+','साक्षरता, आयु 7+'],'children'=>['Children aged 0–6','0–6 वर्ष के बच्चे'],'literates'=>['Literate residents','साक्षर निवासी'],'workers'=>['Workers','कामगार']];
@endphp
<!doctype html><html lang="{{ $hi?'hi':'en' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $name }} · {{ $tr('Village summary','गाँव का सारांश') }} · Pollmedia</title>
<meta name="description" content="{{ $name }} · {{ $district }} · {{ $tr('Village figures and links to detailed records','गाँव के आँकड़े और विस्तृत रिकॉर्ड') }}">
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/pilibhit-summary.css') }}?v=9"><link rel="stylesheet" href="{{ asset('css/village-consistent.css') }}?v={{ substr(hash_file('sha256',public_path('css/village-consistent.css')),0,12) }}"></head>
<body class="election-ui pb-site">@include('public-header',['summaryLayout'=>true])
<main class="pb-dashboard" data-village-summary data-pilibhit-native>
<nav class="pb-crumb" aria-label="{{ $tr('Breadcrumb','स्थान क्रम') }}"><a href="{{ route('home') }}">{{ $tr('India','भारत') }}</a>
@if($state)<span>›</span><span>{{ $state }}</span>@endif
@if($district)<span>›</span>@if($identity)<a href="{{ url($identity['path']).($hi?'?lang=hi':'') }}">{{ $district }}</a>@else<span>{{ $district }}</span>@endif @endif</nav>
<h1>{{ $name }}</h1><p class="pb-scope">{{ $tr('Village summary','गाँव का सारांश') }}</p>
<p class="pb-view-switch"><a href="{{ $detail }}">{{ $tr('Open full village records','गाँव के विस्तृत रिकॉर्ड खोलें') }} →</a></p>
@if($year && !$metrics && !$lgd)<p>{{ $tr('Village record from the','गाँव का रिकॉर्ड ·') }} {{ $year }} {{ $tr('Census','जनगणना') }}</p>@endif
@include('local-board-place-link', ['boardArea' => $localBoardArea ?? (!empty($lgd['lgd']) ? 'village:'.$lgd['lgd'] : null)])
<div class="pb-record-grid pb-anchor-pair">
@if(!empty($archive) && $archive['observations']->isNotEmpty())<section class="pb-panel pb-record-card" data-village-panel="reported"><h2>{{ $tr('Reported figures','दर्ज आँकड़े') }}</h2><table><tbody>
@foreach($archive['observations'] as $row)<tr><th scope="row">{{ $row->label }}<small>{{ $row->period }}</small></th><td>{{ $row->value }} {{ $row->unit }}</td></tr>@endforeach
</tbody></table><a class="pb-more" href="{{ $detail }}">{{ $tr('Open reported records','दर्ज रिकॉर्ड खोलें') }} →</a></section>@endif
@if(!empty($archive) && $archive['places']->isNotEmpty())<section class="pb-panel pb-record-card" data-village-panel="places"><h2>{{ $tr('Linked places','संबंधित स्थान') }}</h2><ul>
@foreach($archive['places'] as $other)<li><a href="{{ route('geography.show',['slug'=>$other->slug,'lang'=>$hi?'hi':null]) }}">{{ $other->name }}</a></li>@endforeach
</ul></section>@endif
@if($metrics)<section class="pb-panel pb-record-card" data-village-panel="people"><h2>{{ $tr('Population and households','जनसंख्या और परिवार') }}</h2><p class="pb-sub">{{ $tr('Census','जनगणना') }} {{ $year }}</p>
<table class="pb-metrics"><tbody>@foreach($metrics as $key=>$v)<tr><th scope="row">{{ $labels[$key][$hi?1:0] }}</th><td>{{ $fmt($v) }}{{ $key==='literacy'?'%':'' }}</td></tr>@endforeach</tbody></table>
<a class="pb-more" href="{{ $people }}">{{ $tr('View Census records','जनगणना रिकॉर्ड देखें') }} →</a></section>@endif
@if($water)<section class="pb-panel pb-record-card pb-water" data-village-panel="water"><h2>{{ $tr('Household tap water','घरेलू नल जल') }}</h2>
@php($coverage=100*$water['tap_connections']/$water['households'])
<strong class="pb-big">{{ $fmt(round($coverage,2)) }}%</strong><div class="pb-track" aria-hidden="true"><i style="width:{{ $coverage }}%"></i></div>
<p>{{ $fmt($water['tap_connections']) }} / {{ $fmt($water['households']) }} {{ $tr('households with a reported tap connection','परिवारों में नल कनेक्शन दर्ज') }}</p>
<a class="pb-more" href="{{ $topic('water') }}">{{ $tr('Explore water records','जल रिकॉर्ड देखें') }} →</a></section>@endif
@if($lgd && ($gp || filled($lgd['subdistrict']??null) || filled($district)))<section class="pb-panel pb-record-card" data-village-panel="places"><h2>{{ $tr('Local administration','स्थानीय प्रशासन') }}</h2><table class="pb-metrics"><tbody>
@foreach([[$tr('District','ज़िला'),$lgd['district']??null],[$tr('Subdistrict','उपज़िला'),$lgd['subdistrict']??null]] as [$label,$value])@if(filled($value))<tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td></tr>@endif @endforeach
@if($gp)<tr><th scope="row">{{ $tr('Gram Panchayat','ग्राम पंचायत') }}</th><td><a href="{{ $gp['url'] }}">{{ $gp['name'] }}</a></td></tr>@endif
</tbody></table><a class="pb-more" href="{{ $topic('places') }}">{{ $tr('Explore linked places','संबंधित स्थान देखें') }} →</a></section>@endif
@if($rain)<section class="pb-panel pb-record-card" data-village-panel="rainfall"><h2>{{ $tr('Annual rainfall','वार्षिक वर्षा') }}</h2><p>{{ $tr('Estimate for the mapped village area','मानचित्रित गाँव क्षेत्र के लिए अनुमान') }}</p>
@foreach($rain as $y=>$mm)<div class="pb-rain-row"><span>{{ $y }}</span><div class="pb-track" aria-hidden="true"><i style="width:{{ $mm*100/max(1,max($rain)) }}%"></i></div><strong>{{ $fmt($mm) }} {{ $tr('mm','मिमी') }}</strong></div>@endforeach
<a class="pb-more" href="{{ $topic('rainfall') }}">{{ $tr('Explore rainfall records','वर्षा रिकॉर्ड देखें') }} →</a></section>@endif
@if($satellite)<section class="pb-panel pb-record-card" data-village-panel="satellite"><h2>{{ $tr('Vegetation index (NDVI)','वनस्पति सूचकांक (NDVI)') }}</h2>
<div class="pb-index-pair"><div><time>{{ $satellite['date1'] }}</time><strong>{{ number_format($satellite['a'],4) }}</strong></div><span class="pb-index-arrow" aria-hidden="true">→</span><div><time>{{ $satellite['date2'] }}</time><strong>{{ number_format($satellite['b'],4) }}</strong></div></div>
<p>{{ $tr('Paired coverage','तुलनीय कवरेज') }} <strong>{{ $fmt($satellite['coverage']) }}%</strong></p>
<a class="pb-more" href="{{ $topic('rainfall') }}#satellite-comparison">{{ $tr('Compare village satellite images','गाँव के उपग्रह चित्रों की तुलना करें') }} →</a></section>@endif
@if($soil)<section class="pb-panel pb-record-card" data-village-panel="soil"><h2>{{ $tr('Soil tests','मृदा परीक्षण') }}</h2><p class="pb-sub">2025–26</p>
<p><strong>{{ $fmt(round($soil['value'],1)) }}%</strong> {{ $tr('of tested samples had low nitrogen','परीक्षित नमूनों में नाइट्रोजन कम थी') }} · {{ $fmt($soil['samples']) }} {{ $tr('samples','नमूने') }}</p>
<a class="pb-more" href="{{ $topic('soil') }}">{{ $tr('View soil records','मृदा रिकॉर्ड देखें') }} →</a></section>@endif
</div></main>@include('public-footer')</body></html>
