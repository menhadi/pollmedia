<!doctype html><html lang="{{ app()->getLocale() }}"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow">
<title>Uttar Pradesh constituency map pilot · Pollmedia</title>@include('site-theme')
<link rel="stylesheet" href="{{ asset('css/electoral-map-pilot.css') }}?v={{ substr(hash_file('sha256',public_path('css/electoral-map-pilot.css')),0,12) }}">
</head><body>@include('public-header')
<main class="electoral-pilot" data-electoral-pilot data-source="{{ asset('maps/up-pilot.json') }}" data-finder="{{ route('elections.constituencies') }}">
<p>Uttar Pradesh · Boundary preview</p><h1>Explore parliamentary and Assembly constituencies</h1>
<p>Select a shape or choose a constituency to find its available election records.</p>
<p class="electoral-pilot-legend"><span>Primary colour: geometry checks passed</span><span>Accent colour with dashed outline: boundary geometry needs review</span></p>
<div class="electoral-pilot-controls">
<label>Constituency type<select data-layer disabled><option value="pc">Lok Sabha · PC</option><option value="ac">State Assembly · AC</option></select></label>
<label>Constituency<select data-seats disabled><option>Loading constituencies…</option></select></label></div>
<div class="electoral-pilot-layout"><section class="electoral-pilot-surface" aria-label="Constituency boundary preview">
<svg viewBox="0 0 600 600" aria-label="Uttar Pradesh constituency shapes"></svg><p role="status">Loading boundary preview…</p></section>
<aside class="electoral-pilot-details"><h2 data-selected>Select a constituency</h2>
<p class="electoral-geometry-warning" data-geometry-warning hidden></p>
<p>The number is the seat code supplied by the boundary dataset. Record matches remain subject to the election year and boundary version.</p>
<a data-records hidden>Find election records →</a><a href="{{ route('elections.constituencies',['state'=>'Uttar Pradesh']) }}">Browse all Uttar Pradesh records →</a></aside></div>
<section class="map-notes" aria-labelledby="boundary-notes"><h2 id="boundary-notes">Boundary sources and limitations</h2>
<p>This is a community boundary preview, not a verified official electoral map. Parliamentary shapes use the DataMeet 2019 simplified dataset. The Assembly dataset does not establish its boundary year and reports positional shifts and possible incorrect names. These shapes do not define boundaries for every historical election.</p>
<p>All 80 PC and 403 AC source records for Uttar Pradesh are included. Akbarpur (PC 44) and Mirzapur (PC 79) have geometry problems and appear in the accent colour with a dashed outline. Their original coordinates are retained without repair or simplification. Source files, hashes, licences and geometry warnings are retained in the <a href="{{ asset('maps/up-pilot.json') }}">downloadable pilot data</a>.</p>
<p>Parliamentary data: <a href="https://github.com/datameet/maps/tree/b3fbbde595310b397a55d718e0958ce249a4fa1f/parliamentary-constituencies" target="_blank" rel="noopener noreferrer">Arun Ganesh / DataMeet</a> (CC0 1.0). Assembly data: <a href="https://github.com/datameet/maps/tree/b3fbbde595310b397a55d718e0958ce249a4fa1f/assembly-constituencies" target="_blank" rel="noopener noreferrer">DataMeet</a> (<a href="https://creativecommons.org/licenses/by/2.5/in/" target="_blank" rel="noopener noreferrer">CC BY 2.5 India</a>); simplified for display. Original attributes remain in the pinned source files.</p></section>
<noscript><p>JavaScript enables the interactive shapes. The constituency directory and boundary source links remain available.</p></noscript>
</main>@include('public-footer')<script src="{{ asset('js/electoral-map-pilot.js') }}?v={{ substr(hash_file('sha256',public_path('js/electoral-map-pilot.js')),0,12) }}" defer></script></body></html>
