<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $pin ? 'PIN '.$pin.' · Linked places' : 'Find places by PIN' }} · Pollmedia</title>@include('site-theme')<link rel="stylesheet" href="{{ asset('css/postal-areas.css') }}?v={{ substr(hash_file('sha256',public_path('css/postal-areas.css')),0,12) }}"><script src="/js/instant-filters.js?v={{ substr(hash_file('sha256',public_path('js/instant-filters.js')),0,12) }}" defer></script></head><body>@include('public-header')
<main class="postal-page">
<nav class="postal-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span>Postal directory</span></nav>
<header class="postal-hero"><div><p class="postal-eyebrow">Explore your area</p><h1>{{ $pin ? 'Places in PIN '.$pin : 'Find places by PIN' }}</h1></div>@if($pin && $results['available'])<div class="postal-count"><strong>{{ number_format($results['total']) }}</strong><span>linked areas</span></div>@endif</header>
<form class="postal-search" method="get" action="{{ route('postal-areas.index') }}"><label for="postal-pin">Postal PIN code<input id="postal-pin" name="pin" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" placeholder="e.g. 262001" value="{{ $pin }}" required></label><button type="submit">Find places <span aria-hidden="true">→</span></button></form>
@if($pin !== '')
@if(!$results['available'])<div class="postal-card postal-empty">The imported postal directory is currently unavailable.</div>@else
<div class="postal-table-bar">
<h2>Villages &amp; urban areas</h2>
<form class="postal-area-search" method="get" action="{{ route('postal-areas.index') }}" role="search">
<input type="hidden" name="pin" value="{{ $pin }}">
<input id="postal-area-search" name="q" type="search" value="{{ $query }}" placeholder="Search area, village or urban body" aria-label="Search areas by name" autocomplete="off" maxlength="100">
<button type="submit">Search</button>
</form>
</div>
<section class="postal-card" aria-label="Places linked to PIN {{ $pin }}">
@if($results['rows'])
<div class="postal-table-scroll" tabindex="0" role="region" aria-label="Place hierarchy table; scroll horizontally on small screens">
<table class="postal-table">
<colgroup><col class="postal-col-place"><col class="postal-col-type"><col class="postal-col-panchayat"><col class="postal-col-subdistrict"><col class="postal-col-district"><col class="postal-col-seat"><col class="postal-col-seat"><col class="postal-col-state"></colgroup>
<thead><tr><th scope="col">Area</th><th scope="col">Type</th><th scope="col">Gram panchayat</th><th scope="col">Taluka / tehsil</th><th scope="col">District</th><th scope="col">Assembly constituency</th><th scope="col">Lok Sabha (PC)</th><th scope="col">State / UT</th></tr></thead>
<tbody>
@foreach($results['rows'] as $row)
<tr>
<td>
<a class="postal-place-link" href="{{ $row['place_url'] }}">{{ $row['name'] }}</a>
@if($row['kind'] === 'village' && !$row['current_identity_linked'])
<span class="postal-source-record">Postal source record · current identity unlinked</span>
@endif
</td>
<td>{{ $row['kind'] === 'village' ? 'Village' : 'Urban local body' }}</td>
<td>
@if($row['panchayat'])
<a href="{{ $row['panchayat']['url'] }}">{{ $row['panchayat']['name'] }}</a>
@else
<span class="postal-unavailable">—</span>
@endif
</td>
@foreach(['subdistrict','district'] as $level)
<td>
@php
$parents = collect($row['parent_links'][$level] ?? [])->unique(fn ($parent) => mb_strtolower((string) $parent['name']))->values();
@endphp
@if($parents->isNotEmpty())
@foreach($parents as $parent)
@if($parent['url'])
<a href="{{ $parent['url'] }}">{{ $parent['name'] }}</a>
@else
{{ $parent['name'] }}
@endif
@endforeach
@elseif($row[$level.'_url'])
<a href="{{ $row[$level.'_url'] }}">{{ $row[$level.'_name'] }}</a>
@elseif($row[$level.'_name'])
{{ $row[$level.'_name'] }}
@else
<span class="postal-unavailable">—</span>
@endif
</td>
@endforeach
@foreach(['ac','pc'] as $kind)
<td>
@if($row[$kind])
@if($row[$kind]['url'])
<a href="{{ $row[$kind]['url'] }}">{{ $row[$kind]['name'] }}</a>
@else
{{ $row[$kind]['name'] }}
@endif
@else
<span class="postal-unavailable">—</span>
@endif
</td>
@endforeach
<td>
@if($row['state_url'])
<a href="{{ $row['state_url'] }}">{{ $row['state_name'] }}</a>
@else
{{ $row['state_name'] }}
@endif
</td>
</tr>
@endforeach
</tbody>
</table>
</div>
@else<div class="postal-empty">@if($query !== '')No areas match “{{ $query }}” in this PIN. <a href="{{ route('postal-areas.index', ['pin' => $pin]) }}">Clear the search</a>.@else No areas are listed for this PIN in the imported LGD release.@endif</div>@endif
<nav class="postal-pagination" aria-label="Results pages"><span>@if($results['rows'])Showing {{ number_format(($results['page']-1)*50+1) }}–{{ number_format(($results['page']-1)*50+count($results['rows'])) }} of {{ number_format($results['total']) }}@else{{ number_format($results['total']) }} linked areas @endif</span><div>@if($results['page'] > 1)<a rel="prev" href="{{ route('postal-areas.index', array_filter(['pin'=>$pin,'page'=>$results['page']-1,'q'=>$query ?: null])) }}">← Previous</a>@endif @if($results['page'] * 50 < $results['total'])<a rel="next" href="{{ route('postal-areas.index', array_filter(['pin'=>$pin,'page'=>$results['page']+1,'q'=>$query ?: null])) }}">Next →</a>@endif</div></nav>
</section>
@endif @endif
</main>@include('public-footer')</body></html>
