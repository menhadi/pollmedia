<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $place['name'] }} · Postal area · Pollmedia</title>@include('site-theme')<link rel="stylesheet" href="{{ asset('css/postal-areas.css') }}?v={{ substr(hash_file('sha256',public_path('css/postal-areas.css')),0,12) }}"></head><body>@include('public-header')
<main class="postal-page"><nav class="postal-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('postal-areas.index') }}">Postal directory</a><span aria-hidden="true">/</span><span>{{ $place['name'] }}</span></nav>
<header class="postal-hero"><div><p class="postal-eyebrow">{{ $place['kind'] === 'village' ? 'Village' : 'Urban local body' }}</p><h1>{{ $place['name'] }}</h1><p class="postal-intro">Postal information and available administrative links for this area.</p></div></header>
<section class="postal-card"><table class="postal-table postal-identity"><tbody>
<tr><th scope="row">Area / Village</th><td>@if($villageUrl)<a href="{{ $villageUrl }}">{{ $place['name'] }} →</a>@else{{ $place['name'] }}@endif</td></tr>
@if($place['panchayat'])
<tr><th scope="row">Gram panchayat</th><td><a href="{{ $place['panchayat']['url'] }}">{{ $place['panchayat']['name'] }}</a></td></tr>
@endif
@foreach(['subdistrict'=>'Taluka / Subdistrict','district'=>'District'] as $level=>$label)
<tr><th scope="row">{{ $label }}</th><td>
@if(!empty($place['parent_links'][$level]))
@foreach($place['parent_links'][$level] as $parent)
<div>
@if($parent['url'])
<a href="{{ $parent['url'] }}">{{ $parent['name'] }}</a>
@else
{{ $parent['name'] }}
@endif
<small class="postal-source-record">LGD coverage association</small>
</div>
@endforeach
@elseif($place[$level.'_url'])
<a href="{{ $place[$level.'_url'] }}">{{ $place[$level.'_name'] }} →</a>
@elseif($place[$level.'_name'])
{{ $place[$level.'_name'] }}
@else
<span class="postal-unavailable">Not provided by the postal source</span>
@endif
</td></tr>
@endforeach
@foreach(['ac'=>'Assembly constituency (AC)','pc'=>'Parliamentary constituency (PC)'] as $kind=>$label)
<tr><th scope="row">{{ $label }}</th><td>
@if($place[$kind])
@if($place[$kind]['url'])
<a href="{{ $place[$kind]['url'] }}">{{ $place[$kind]['name'] }} →</a>
@else
{{ $place[$kind]['name'] }}
@endif
@else
<span class="postal-unavailable">—</span>
@endif
</td></tr>
@endforeach
<tr><th scope="row">State / UT</th><td>
@if($place['state_url'])
<a href="{{ $place['state_url'] }}">{{ $place['state_name'] }} →</a>
@else
{{ $place['state_name'] }}
@endif
</td></tr>
<tr><th scope="row">Official LGD code</th><td>{{ $place['code'] }}</td></tr>
</tbody></table></section>
@if($villageUrl)<p><a class="postal-button" href="{{ $villageUrl }}">Open village dashboard →</a></p>@endif
@include('place-postal-codes', ['postal'=>$postal])
<p class="postal-notes">{{ $place['kind'] === 'local-body' ? 'PINs are reported for this local body. They are not assigned automatically to every ward or street. District and taluka associations use the official LGD local-body coverage directory (2 November 2026), including partial coverage. A shared PIN is not used to infer these links.' : ($villageUrl ? 'Current administrative identity matched by LGD codes. Other dashboard datasets retain their own dates and boundary editions.' : 'This postal source record is retained, but a matching current village dashboard is not linked.') }}</p>
</main>@include('public-footer')</body></html>
