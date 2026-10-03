<!doctype html><html lang="{{ app()->getLocale() }}"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow">
<title>{{ $selectedState['name'] }} constituency boundaries · Pollmedia</title>@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/electoral-map-pilot.css') }}?v={{ substr(hash_file('sha256',public_path('css/electoral-map-pilot.css')),0,12) }}">
</head><body class="electoral-map-page">@include('public-header')
<main class="electoral-pilot">
<p>{{ $selectedState['name'] }}</p><h1>Explore parliamentary and Assembly constituencies</h1>
<form class="electoral-pilot-controls" action="{{ route('elections.maps.index') }}" method="get">
<label>State / Union Territory<select name="state">@foreach($catalogue['states'] as $item)<option value="{{ $item['slug'] }}" @selected($item['slug']===$selectedState['slug'])>{{ $item['name'] }}</option>@endforeach</select></label>
<label>Constituency type<select name="kind"><option value="pc" @selected($initialKind==='pc')>Lok Sabha · PC</option><option value="ac" @selected($initialKind==='ac')>State Assembly · AC</option></select></label><button>Show map</button>
<a href="{{ route('states.show',['state'=>$selectedState['slug']]) }}">Explore {{ $selectedState['name'] }} →</a></form>
@include('election-map',['mapKind'=>$initialKind,'mapStateName'=>$selectedState['name']])
<section class="map-notes" aria-labelledby="boundary-notes"><h2 id="boundary-notes">Boundary sources and limitations</h2>
@if($sourceNote)<p>{{ $sourceNote }}</p>@endif
<p>This is a community source, not a verified official electoral map. Parliamentary shapes use the DataMeet 2019 simplified dataset. The Assembly dataset does not establish its boundary year and reports positional shifts and possible incorrect names. These shapes do not define boundaries for every historical election.</p>
<p>All {{ number_format($catalogue['totals']['pc']) }} parliamentary and {{ number_format($catalogue['totals']['ac']) }} Assembly source records are retained across the state maps. These are source records, not a current seat count. Duplicate codes, missing names, older boundaries and geometry problems are recorded in administration. An absent layer means this dataset has no separate shapes for that state or territory, not that election records are absent.</p>
@if($selectedState['slug']==='uttar-pradesh')<p>All 80 PC and 403 AC Uttar Pradesh records are included. Akbarpur (PC 44) and Mirzapur (PC 79) retain their source geometry problems.</p>@endif
<p>Shapes with geometry problems retain original coordinates without repair or simplification. Valid geometry is simplified for display; passing this check does not verify the boundary edition. Names, seat codes and boundary versions must be checked against the original election reports before a geographic match is confirmed.</p>
<p>Download: <a href="{{ asset('maps/electoral/'.$selectedState['file']) }}">{{ $selectedState['name'] }} source shapes and attributes</a> · <a href="{{ asset('maps/electoral/catalogue.json') }}">National source inventory, hashes and counts</a>@if($selectedState['slug']==='telangana') · <a href="{{ asset('maps/electoral/andhra-pradesh.json') }}">Combined Andhra Pradesh / Telangana Assembly source</a>@endif</p>
<p>Parliamentary data: <a href="https://github.com/datameet/maps/tree/b3fbbde595310b397a55d718e0958ce249a4fa1f/parliamentary-constituencies" target="_blank" rel="noopener noreferrer">Arun Ganesh / DataMeet</a> (CC0 1.0). Assembly data: <a href="https://github.com/datameet/maps/tree/b3fbbde595310b397a55d718e0958ce249a4fa1f/assembly-constituencies" target="_blank" rel="noopener noreferrer">DataMeet</a> (<a href="https://creativecommons.org/licenses/by/2.5/in/" target="_blank" rel="noopener noreferrer">CC BY 2.5 India</a>); simplified for display. Original attributes remain in the pinned source files.</p></section>
<noscript><p>JavaScript enables the interactive shapes. The constituency directory and boundary source links remain available.</p></noscript>
</main>@include('public-footer')</body></html>
