<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $pin ? 'PIN '.$pin.' · Linked places' : 'Find places by PIN' }} · Pollmedia</title>@include('site-theme')<link rel="stylesheet" href="{{ asset('css/postal-areas.css') }}?v={{ substr(hash_file('sha256',public_path('css/postal-areas.css')),0,12) }}"></head><body>@include('public-header')
<main class="postal-page">
<nav class="postal-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('home') }}">Home</a><span aria-hidden="true">/</span><span>Postal directory</span></nav>
<header class="postal-hero"><div><p class="postal-eyebrow">Explore your area</p><h1>{{ $pin ? 'Places in PIN '.$pin : 'Find places by PIN' }}</h1></div>@if($pin && $results['available'])<div class="postal-count"><strong>{{ number_format($results['total']) }}</strong><span>linked areas</span></div>@endif</header>
<form class="postal-search" method="get" action="{{ route('postal-areas.index') }}"><label for="postal-pin">Postal PIN code<input id="postal-pin" name="pin" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" placeholder="e.g. 262001" value="{{ $pin }}" required></label><button type="submit">Find places <span aria-hidden="true">→</span></button></form>
@if($pin !== '')
@if(!$results['available'])<div class="postal-card postal-empty">The imported postal directory is currently unavailable.</div>@else
<div class="postal-section-heading"><h2>Villages &amp; urban areas</h2><p>Area, panchayat, taluka, district and constituencies</p></div>
<section class="postal-card" aria-label="Places linked to PIN {{ $pin }}">
@if($results['rows'])
<div class="postal-table-tools">
<label class="postal-table-search" for="postal-area-search">Search this list
<input id="postal-area-search" type="search" inputmode="search" placeholder="Area, village or urban body" autocomplete="off" data-postal-area-search>
</label>
<span class="postal-table-count" data-postal-area-count role="status" aria-live="polite"></span>
</div>
<div class="postal-table-scroll" tabindex="0" role="region" aria-label="Place hierarchy table; scroll horizontally on small screens">
<table class="postal-table">
<colgroup><col class="postal-col-place"><col class="postal-col-type"><col class="postal-col-panchayat"><col class="postal-col-subdistrict"><col class="postal-col-district"><col class="postal-col-seat"><col class="postal-col-seat"><col class="postal-col-state"></colgroup>
<thead><tr><th scope="col">Area</th><th scope="col">Type</th><th scope="col">Gram panchayat</th><th scope="col">Taluka / subdistrict</th><th scope="col">District</th><th scope="col">Assembly constituency</th><th scope="col">Lok Sabha (PC)</th><th scope="col">State / UT</th></tr></thead>
<tbody>
@foreach($results['rows'] as $row)
<tr>
<td>
<a class="postal-place-link" href="{{ $row['place_url'] }}">{{ $row['name'] }} <span aria-hidden="true">↗</span></a>
@if($row['kind'] === 'village' && !$row['current_identity_linked'])
<span class="postal-source-record">Postal source record · current identity unlinked</span>
@endif
</td>
<td><span class="postal-type {{ $row['kind'] === 'local-body' ? 'urban' : '' }}">{{ $row['kind'] === 'village' ? 'Village' : 'Urban local body' }}</span></td>
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
<script>
(() => {
    const input = document.querySelector('[data-postal-area-search]');
    const table = document.querySelector('.postal-table');
    if (!input || !table || !table.tBodies.length) return;
    const rows = Array.from(table.tBodies[0].rows);
    const count = document.querySelector('[data-postal-area-count]');
    const normalise = value => value.normalize('NFKD').toLowerCase().replace(/\s+/g, ' ').trim();
    const apply = () => {
        const query = normalise(input.value);
        let shown = 0;
        for (const row of rows) {
            const haystack = normalise((row.cells[0]?.textContent || '') + ' ' + (row.cells[1]?.textContent || ''));
            const match = query === '' || haystack.includes(query);
            row.hidden = !match;
            if (match) shown += 1;
        }
        table.classList.toggle('is-filtered', query !== '');
        if (count) count.textContent = query === '' ? '' : shown + ' of ' + rows.length + ' areas';
    };
    input.addEventListener('input', apply);
    input.addEventListener('search', apply);
})();
</script>
@else<div class="postal-empty">No areas are listed for this PIN in the imported LGD release.</div>@endif
<nav class="postal-pagination" aria-label="Results pages"><span>@if($results['rows'])Showing {{ number_format(($results['page']-1)*50+1) }}–{{ number_format(($results['page']-1)*50+count($results['rows'])) }} of {{ number_format($results['total']) }}@else{{ number_format($results['total']) }} linked areas @endif</span><div>@if($results['page'] > 1)<a rel="prev" href="{{ route('postal-areas.index', ['pin'=>$pin,'page'=>$results['page']-1]) }}">← Previous</a>@endif @if($results['page'] * 50 < $results['total'])<a rel="next" href="{{ route('postal-areas.index', ['pin'=>$pin,'page'=>$results['page']+1]) }}">Next →</a>@endif</div></nav>
</section>
@endif @endif
</main>@include('public-footer')</body></html>
