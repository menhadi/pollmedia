@php
    $tr=fn($en,$hindi)=>$hi?$hindi:$en;
    $num=new \NumberFormatter('en_IN',\NumberFormatter::DECIMAL);
    $params=['state'=>$record['state'],'gp'=>$record['gp'],'lang'=>$hi?'hi':null];
    $detail=fn($topic)=>route('gram-panchayats.show',$params+['view'=>'details','topic'=>$topic]);
    $districts=collect($members)->pluck('district_name')->unique()->implode(', ');
    $hasPopulation=collect($rows)->contains(fn($row)=>$row['population']!==null);
    $hasHouseholds=collect($rows)->contains(fn($row)=>$row['households']!==null);
@endphp
<!doctype html><html lang="{{ $hi?'hi':'en' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $record['name'] }} · {{ $tr('Gram Panchayat','ग्राम पंचायत') }} · Pollmedia</title>
<meta name="description" content="{{ $record['name'] }} · {{ $districts }} · {{ $tr('Panchayat records and linked villages','पंचायत रिकॉर्ड और संबंधित गाँव') }}">
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/pilibhit-summary.css') }}?v=9"><link rel="stylesheet" href="{{ asset('css/panchayat-summary.css') }}"><link rel="stylesheet" href="{{ asset('css/static-district-map.css') }}?v={{ substr(hash_file('sha256',public_path('css/static-district-map.css')),0,12) }}"><script src="{{ asset('js/static-district-map.js') }}?v={{ substr(hash_file('sha256',public_path('js/static-district-map.js')),0,12) }}" defer></script></head>
<body class="election-ui pb-site">@include('public-header',['summaryLayout'=>true])
<main class="pb-dashboard" data-gp-summary data-pilibhit-native>
<nav class="pb-crumb" aria-label="{{ $tr('Breadcrumb','स्थान क्रम') }}"><a href="{{ route('home') }}">{{ $tr('India','भारत') }}</a><span>›</span><span>{{ $members[0]['state_name'] }}</span><span>›</span><span>{{ $districts }}</span></nav>
<h1>{{ $record['name'] }}</h1><p class="pb-scope">{{ $tr('Gram Panchayat','ग्राम पंचायत') }}</p>
<p class="pb-view-switch"><a href="{{ $detail('overview') }}">{{ $tr('Open full Panchayat profile','पूरी पंचायत प्रोफ़ाइल खोलें') }} →</a></p>
<div class="pb-record-grid pb-anchor-pair">
<section class="pb-panel pb-record-card" data-gp-panel="directory"><h2>{{ $tr('Panchayat directory record','पंचायत निर्देशिका रिकॉर्ड') }}</h2>
<table class="pb-metrics"><tbody>
<tr><th scope="row">{{ $tr('District','ज़िला') }}</th><td>{{ $districts }}</td></tr>
<tr><th scope="row">{{ $tr('Linked villages','संबंधित गाँव') }}</th><td>{{ $num->format(count($members)) }}</td></tr>
</tbody></table>
<a class="pb-more" href="{{ $detail('villages') }}">{{ $tr('Open village directory','गाँव निर्देशिका खोलें') }} →</a></section>
@if($own['payment_totals'])
<section class="pb-panel pb-record-card" data-gp-panel="payments"><h2>{{ $tr('Panchayat payments','पंचायत भुगतान') }}</h2>
<p>{{ $tr('Annual payments reported for this Gram Panchayat','इस ग्राम पंचायत के लिए दर्ज वार्षिक भुगतान') }}</p>
<table class="pb-metrics"><thead><tr><th scope="col">{{ $tr('Financial year','वित्तीय वर्ष') }}</th><th scope="col">{{ $tr('Reported total','दर्ज कुल') }}</th></tr></thead><tbody>
@foreach(array_slice($own['payment_totals'],0,3) as $total)<tr data-gp-year="{{ $total['year'] }}"><th scope="row">{{ $total['year'] }}</th><td translate="no">{{ $total['unit']==='INR'?'₹':'' }}{{ $total['source_amount'] }}</td></tr>@endforeach
</tbody></table>
@if(collect(array_slice($own['payment_totals'],0,3))->contains(fn($r)=>$r['unit']===null))<small>{{ $tr('Original amounts · unit unconfirmed','मूल राशि · इकाई की पुष्टि नहीं') }}</small>@endif
<a class="pb-more" href="{{ $detail('records') }}#panchayat-records">{{ $tr('View all reported years','सभी दर्ज वर्ष देखें') }} →</a></section>
@endif
@if($own['allocations'] || $own['reports'])
<section class="pb-panel pb-record-card" data-gp-panel="records"><h2>{{ $tr('Panchayat accounts and allocations','पंचायत के लेखे और आवंटन') }}</h2>
@if($own['allocations'])
<h3>{{ $tr('State Finance Commission allocations','राज्य वित्त आयोग के आवंटन') }}</h3>
@foreach(array_slice($own['allocations'],0,2) as $allocation)
<p>{{ $allocation['year'] }}@if(!empty($allocation['installments'][0])) · {{ $allocation['installments'][0]['label'] }} <strong translate="no">{{ $allocation['unit']==='INR'?'₹':'' }}{{ $allocation['installments'][0]['amount'] }}</strong>@if($allocation['unit']===null) <small>{{ $tr('Unit unconfirmed','इकाई की पुष्टि नहीं') }}</small>@endif @endif</p>
@endforeach
@endif
@if($own['reports'])
<h3>{{ $tr('Captured records','संग्रहित रिकॉर्ड') }}</h3>
@foreach(collect($own['reports'])->sortByDesc('financial_year')->take(2) as $report)
<p><strong>{{ $report['financial_year'] }}</strong>@foreach(['activities'=>['activities','गतिविधियाँ'],'payments'=>['payment entries','भुगतान प्रविष्टियाँ'],'receipts'=>['receipt entries','प्राप्ति प्रविष्टियाँ']] as $kind=>$label)@if(!empty($report[$kind])) · {{ $num->format(count($report[$kind])) }} {{ $label[$hi?1:0] }}@endif @endforeach</p>
@endforeach
@endif<a class="pb-more" href="{{ $detail('records') }}">{{ $tr('Explore Panchayat records','पंचायत रिकॉर्ड देखें') }} →</a></section>@endif
</div>
@include('panchayat-village-map')
<section class="pb-panel pb-wide" data-gp-panel="villages"><h2>{{ $tr('Linked villages','संबंधित गाँव') }}</h2>@if($hasPopulation || $hasHouseholds)<p>{{ $tr('Residents and households are 2011 Census figures for each linked place. Panchayat membership is from the current directory.','निवासी और परिवार प्रत्येक संबंधित स्थान के 2011 की जनगणना के आँकड़े हैं। पंचायत की सदस्यता वर्तमान निर्देशिका के अनुसार है।') }}</p>@endif
<table class="pb-metrics gp-members"><thead><tr><th scope="col">{{ $tr('Village','गाँव') }}</th>
@if($hasPopulation)<th scope="col">{{ $tr('Residents','निवासी') }}</th>@endif
@if($hasHouseholds)<th scope="col">{{ $tr('Households','परिवार') }}</th>@endif
@if(!$hasPopulation && !$hasHouseholds)<th scope="col">{{ $tr('Subdistrict','उपज़िला') }}</th>@endif
</tr></thead><tbody>@foreach($rows as $row)<tr><th scope="row">@if($row['url'])<a href="{{ $row['url'] }}">{{ $row['name'] }}</a>@else{{ $row['name'] }}@endif</th>
@if($hasPopulation)<td>{{ $row['population']===null?'—':$num->format($row['population']) }}</td>@endif
@if($hasHouseholds)<td>{{ $row['households']===null?'—':$num->format($row['households']) }}</td>@endif
@if(!$hasPopulation && !$hasHouseholds)<td>{{ $row['subdistrict'] }}</td>@endif
</tr>@endforeach</tbody></table>
<a class="pb-more" href="{{ $detail('villages') }}">{{ $tr('View all linked villages','सभी संबंधित गाँव देखें') }} →</a></section>
</main>@include('public-footer')</body></html>
