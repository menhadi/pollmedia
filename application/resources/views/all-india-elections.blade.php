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
<script defer src="{{ asset('js/national-election-table.js') }}?v={{ substr(hash_file('sha256',public_path('js/national-election-table.js')),0,12) }}"></script>
<link rel="stylesheet" href="{{ asset('css/national-dashboards.css') }}?v={{ substr(hash_file('sha256',public_path('css/national-dashboards.css')),0,12) }}">
</head><body>@include('public-header',['summaryLayout'=>true])
<main class="national-elections india-hub" data-national-elections data-first="{{ $first }}">
<nav class="hub-crumb" aria-label="Breadcrumb"><a href="{{ route('home') }}">{{ $tr('India','भारत') }}</a> › {{ $tr('Elections','चुनाव') }}</nav>
@include('national-election-overview')
@include('national-election-records')
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
