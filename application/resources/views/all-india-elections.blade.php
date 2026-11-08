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
@if($isOverview)
@include('national-election-overview')
@else
<h1>{{ $title }}</h1><p><a href="{{ route('elections.all-india',$lang) }}">{{ $tr('All India election overview','अखिल भारतीय चुनाव सारांश') }} →</a></p>
@endif
<section id="national-records" aria-label="{{ $tr('Search constituency records','निर्वाचन क्षेत्र रिकॉर्ड खोजें') }}"><h2>{{ $tr('Constituency records','निर्वाचन क्षेत्र रिकॉर्ड') }}</h2>
<form method="get" action="{{ route('elections.all-india') }}">
@if($hi)<input type="hidden" name="lang" value="hi">@endif
<label>{{ $tr('Election type','चुनाव का प्रकार') }}<select name="type"><option value="">{{ $tr('All elections','सभी चुनाव') }}</option>@foreach($labels as $key=>$label)<option value="{{ $key }}" @selected(($filters['type']??'')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>{{ $tr('Year','वर्ष') }}<select name="year"><option value="">{{ $tr('All years','सभी वर्ष') }}</option>@foreach($years as $item)<option value="{{ $item }}" @selected(isset($filters['year']) && (int)$filters['year']===$item)>{{ $item?:$tr('Year unreported','वर्ष अनुपलब्ध') }}</option>@endforeach</select></label>
<label>{{ $tr('State / UT','राज्य / केंद्र शासित प्रदेश') }}<select name="state"><option value="">{{ $tr('All states and UTs','सभी राज्य और केंद्र शासित प्रदेश') }}</option>@foreach($states as $area)<option value="{{ $area }}" @selected(($filters['state']??null)===$area)>{{ $area }}</option>@endforeach</select></label>
<label>{{ $tr('Constituency','निर्वाचन क्षेत्र') }}<input type="search" name="q" maxlength="120" value="{{ $filters['q']??'' }}" placeholder="{{ $tr('Seat name','क्षेत्र का नाम') }}"></label>
<button type="submit">{{ $tr('Apply filters','फ़िल्टर लागू करें') }}</button>
</form>
<h2>{{ number_format($total) }} {{ $tr($total===1?'constituency record':'constituency records','निर्वाचन क्षेत्र रिकॉर्ड') }}</h2>
<p>{{ $tr('Each election type starts with its newest records. Original report editions are listed separately.','हर चुनाव प्रकार के नवीनतम रिकॉर्ड पहले हैं। मूल रिपोर्ट संस्करण अलग सूचीबद्ध हैं।') }}</p>
<div class="national-scroll" role="region" aria-label="{{ $title }}" tabindex="0"><table>
<thead><tr>@foreach([['Year','वर्ष'],['Election','चुनाव'],['State / UT','राज्य / केंद्र शासित प्रदेश'],['Constituency','निर्वाचन क्षेत्र'],['Recorded winner','दर्ज विजेता'],['Party','दल'],['Candidate rows','उम्मीदवार पंक्तियाँ'],['Source edition','स्रोत संस्करण']] as [$en,$hn])<th scope="col">{{ $tr($en,$hn) }}</th>@endforeach</tr></thead>
<tbody>@include('all-india-election-rows')</tbody>
</table></div>
<nav class="national-pager" aria-label="{{ $tr('Election records','चुनाव रिकॉर्ड') }}">
@if($previousUrl)<a href="{{ $previousUrl }}">{{ $tr('Previous records','पिछले रिकॉर्ड') }}</a>@endif
<span role="status" data-national-status>{{ $rows->isEmpty()?'0':number_format($first).'–'.number_format($last) }} / {{ number_format($total) }}</span>
@if($nextUrl)<a data-national-more href="{{ $nextUrl }}">{{ $tr('Load more','और दिखाएँ') }}</a>@endif
</nav></section>
</main>@include('public-footer')</body></html>
