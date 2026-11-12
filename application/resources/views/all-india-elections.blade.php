@php
    $hi=app()->getLocale()==='hi';
    $tr=fn($en,$hn)=>$hi?$hn:$en;
    $lang = $hi ? ['lang'=>'hi'] : [];
    $labels=['pc'=>$tr('Lok Sabha (PC)','लोक सभा (पीसी)'), 'ac'=>$tr('Assembly (AC)','विधान सभा (एसी)'), 'by'=>$tr('By-election','उपचुनाव')];
    $title=$tr('All India elections','अखिल भारतीय चुनाव');
@endphp
<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
@include('seo-metadata',['breadcrumbs'=>['Pollmedia'=>url('/'),$title=>route('elections.all-india')],'canonical'=>route('elections.all-india',$lang),'seoTitle'=>$title.' · Pollmedia','seoDescription'=>'Search parliamentary, Assembly and by-election constituency records in one national table.'])
@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/national-election-table.css') }}?v={{ substr(hash_file('sha256',public_path('css/national-election-table.css')),0,12) }}">
<script defer src="{{ asset('js/national-election-table.js') }}?v={{ substr(hash_file('sha256',public_path('js/national-election-table.js')),0,12) }}"></script><script defer src="{{ asset('js/sortable-tables.js') }}?v={{ substr(hash_file('sha256',public_path('js/sortable-tables.js')),0,12) }}"></script>
<link rel="stylesheet" href="{{ asset('css/national-dashboards.css') }}?v={{ substr(hash_file('sha256',public_path('css/national-dashboards.css')),0,12) }}">
</head><body>@include('public-header',['summaryLayout'=>true])
<main class="national-elections india-hub" data-national-elections data-first="{{ $first }}">
<nav class="hub-crumb" aria-label="Breadcrumb"><a href="{{ route('home') }}">{{ $tr('India','भारत') }}</a> › {{ $tr('Elections','चुनाव') }}</nav>
@include('national-election-overview')
@include('national-election-records')
@if($mapEdition)
<section id="national-lok-sabha-map" class="hub-panel national-map-panel" aria-labelledby="national-map-title">
<div class="hub-panel-title"><h2 id="national-map-title">{{ $tr('Lok Sabha constituency map','लोक सभा निर्वाचन क्षेत्र मानचित्र') }}</h2>
<form method="get" action="{{ route('elections.all-india') }}#national-lok-sabha-map" class="hub-jump-form" data-map-edition-form>
@foreach(array_filter(array_merge($filters,$lang),fn($value,$key)=>$key!=='map_edition' && $value!==null && $value!=='',ARRAY_FILTER_USE_BOTH) as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<label>{{ $tr('Election year','चुनाव वर्ष') }}<select name="map_edition">@foreach($mapEditions as $entry)<option value="{{ $entry['id'] }}" @selected($entry['id']===$mapEdition['id'])>{{ $entry['label']??$entry['year']??'—' }}</option>@endforeach</select></label><button type="submit">{{ $tr('Show map','मानचित्र देखें') }}</button>
</form></div>
@include('election-map',['mapKind'=>'pc','mapStateName'=>'','mapEdition'=>$mapEdition['id'],'mapYear'=>$mapEdition['year']??null,'nationalFilters'=>true])
</section>
@endif
@php($byYears=$annual->filter(fn($counts)=>($counts['by']??0)>0))
<section id="national-by-elections" class="hub-panel national-by-panel" aria-labelledby="national-by-title">
<div class="hub-panel-title"><h2 id="national-by-title">{{ $tr('By-elections','उपचुनाव') }}</h2><span>{{ number_format($nationalCounts['by']??0) }} {{ $tr('records','रिकॉर्ड') }}</span></div>
@if($byYears->isNotEmpty())
<ul class="hub-by-years">@foreach($byYears->take(6) as $year=>$counts)<li><a data-record-link href="{{ route('elections.all-india',['year'=>$year,'type'=>'by']+$lang) }}#national-records"><strong>{{ $year ?: $tr('Year unreported','वर्ष अनुपलब्ध') }}</strong><span>{{ number_format($counts['by']) }} {{ $tr('records','रिकॉर्ड') }} <span aria-hidden="true">→</span></span></a></li>@endforeach</ul>
<form method="get" action="{{ route('elections.all-india') }}#national-records" data-by-year-form class="hub-jump-form"><input type="hidden" name="type" value="by">
@if($hi)<input type="hidden" name="lang" value="hi">@endif
@if(isset($filters['map_edition']))<input type="hidden" name="map_edition" value="{{ $filters['map_edition'] }}">@endif
<label>{{ $tr('By-election year','उपचुनाव वर्ष') }}<select name="year"><option value="">{{ $tr('All years','सभी वर्ष') }}</option>@foreach($byYears as $year=>$counts)<option value="{{ $year }}">{{ $year ?: $tr('Year unreported','वर्ष अनुपलब्ध') }}</option>@endforeach</select></label><button type="submit">{{ $tr('Show results','परिणाम देखें') }}</button></form>
@endif</section>
<section id="election-states" class="hub-panel visible-directory"><h2>{{ $tr('State election results','राज्य चुनाव परिणाम') }}</h2>
@foreach(['State'=>$tr('States','राज्य'),'Union Territory'=>$tr('Union territories','केंद्र शासित प्रदेश')] as $group=>$caption)
<h3>{{ $caption }}</h3><ul class="hub-places">@foreach($stateDirectory->where('kind',$group) as $area)<li><a href="{{ route('states.show',['state'=>$area['slug']]+$lang) }}">{{ $area['name'] }} <span aria-hidden="true">→</span></a></li>@endforeach</ul>
@endforeach</section>
<details class="hub-panel hub-disclaimer"><summary>{{ $tr('Historical records & disclaimer','ऐतिहासिक रिकॉर्ड और अस्वीकरण') }}</summary>
<p>{{ $tr('Totals count published constituency records. Report editions and historical boundaries can differ. A dash marks unavailable data. Candidate votes are shown as recorded; a winner is displayed only where supported by the published result.', 'कुल संख्या प्रकाशित निर्वाचन क्षेत्र रिकॉर्ड की है। रिपोर्ट संस्करण और ऐतिहासिक सीमाएँ भिन्न हो सकती हैं। डैश अनुपलब्ध आँकड़े दर्शाता है। मत दर्ज रूप में हैं और विजेता की पुष्टि प्रकाशित परिणाम से होती है।') }}</p>
<div class="national-scroll" tabindex="0"><table><thead><tr><th>{{ $tr('Year','वर्ष') }}</th>@foreach($labels as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
@foreach($annual as $y=>$numbers)<tr><th scope="row">{{ $y ?: $tr('Year unreported','वर्ष अनुपलब्ध') }}</th>@foreach($labels as $key=>$label)<td>{{ isset($numbers[$key])?number_format($numbers[$key]):'—' }}</td>@endforeach</tr>@endforeach
</tbody></table></div></details>
</main>@include('public-footer')</body></html>
