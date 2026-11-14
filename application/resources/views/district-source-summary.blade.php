@php
    $hi=app()->getLocale()==='hi'||request('lang')==='hi';
    $tr=fn($en,$hindi)=>$hi?$hindi:$en;
    $name=$district['source_name']??$district['name']??$data['name'];
    $villages=$district['revenue_villages']??[];
    $features=$district['features']??$shapeCount??null;
    $details=request()->fullUrlWithQuery(['view'=>'details']);
    $file=$district['file']??$sourceMap??null;
    $ndvi=null;
    if ($file) {
        $layers=json_decode(file_get_contents(database_path('fixtures/district-summary-satellite.json')),true,flags:JSON_THROW_ON_ERROR);
        $layer=$layers[$file]??null;
        if ($layer) {
            $checksum=$district['sha256']??null;
            if ($checksum===null && isset($sourceMap) && is_file(public_path($sourceMap))) {
                $checksum=hash_file('sha256',public_path($sourceMap));
            }
            if ($checksum===$layer['geometry_sha256']) {
                $ndvi=$layer;
            }
        }
    }
    $map=request()->fullUrlWithQuery(['view'=>'details','topic'=>'map']);
@endphp
<!doctype html><html lang="{{ $hi?'hi':'en' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $name }} · {{ $tr('District records','ज़िला रिकॉर्ड') }} · Pollmedia</title>
<link rel="stylesheet" href="{{ asset('css/election-dashboard.css') }}">@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/pilibhit-summary.css') }}?v=8"><link rel="stylesheet" href="{{ asset('css/compact-area-pages.css') }}?v={{ substr(hash_file('sha256',public_path('css/compact-area-pages.css')),0,12) }}"></head>
<body class="election-ui pb-site">@include('public-header',['summaryLayout'=>true])
<main class="pb-dashboard compact-district" data-district-source-summary data-pilibhit-native>
<nav class="pb-crumb"><a href="{{ route('district-dashboard-directory') }}">{{ $tr('Districts','ज़िले') }}</a><span>›</span><span>{{ $name }}</span></nav>
<h1>{{ $name }}</h1><p class="pb-scope">{{ $regionLabel??$tr('District records','ज़िला रिकॉर्ड') }}</p>
<p class="pb-view-switch"><a href="{{ $details }}">{{ $tr('Open full district records','विस्तृत ज़िला रिकॉर्ड खोलें') }} →</a></p>
@if($villages)
<section class="pb-panel pb-wide"><h2>{{ $tr('Revenue villages','राजस्व गाँव') }}</h2><p>{{ number_format(count($villages)) }} {{ $tr('listed in this directory','इस निर्देशिका में दर्ज') }}</p>
<table><thead><tr><th scope="col">{{ $tr('Village','गाँव') }}</th></tr></thead><tbody>@foreach(array_slice($villages,0,10) as $village)<tr><th scope="row">{{ $village['name'] }}</th></tr>@endforeach</tbody></table>
<a class="pb-more" href="{{ $details }}">{{ $tr('Browse the full directory','पूरी निर्देशिका देखें') }} →</a></section>
@else
<section class="pb-panel pb-wide"><h2>{{ $tr('District map','ज़िले का नक्शा') }}</h2>
@if($features!==null)<p><strong>{{ number_format($features) }}</strong> {{ $tr('features in this map layer','इस मानचित्र परत में आकृतियाँ') }}</p>
@else<p>{{ $tr('District boundary from the source map','स्रोत मानचित्र में ज़िले की सीमा') }}</p>@endif
<a class="pb-more" href="{{ $map }}">{{ $tr('Explore this area and its coverage','इस क्षेत्र और उसका कवरेज देखें') }} →</a></section>
@endif
@if($ndvi)<div class="pb-record-grid pb-anchor-pair">@include('district-summary-vegetation')</div>@endif
@if(!empty($constituencyLinks['assembly']))<section class="pb-panel pb-wide"><h2>{{ $tr('Assembly constituencies','विधानसभा क्षेत्र') }}</h2>
<ul>@foreach(array_slice($constituencyLinks['assembly'],0,5) as $seat)<li><a href="{{ $seat['url'] }}">{{ $seat['name'] }}</a></li>@endforeach</ul><a href="{{ request()->fullUrlWithQuery(['view'=>'details','topic'=>'elections']) }}">{{ $tr('More election records','अन्य चुनाव रिकॉर्ड') }} →</a></section>@endif
</main>@include('public-footer')</body></html>
