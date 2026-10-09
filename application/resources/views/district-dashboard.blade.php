<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $data['name'] }} district | Pollmedia</title><link rel="stylesheet" href="{{ asset('css/pilibhit-ac-pilot.css') }}"><link rel="stylesheet" href="{{ asset('css/rampur-district-pilot.css') }}"><script src="{{ asset('js/history-lines.js') }}" defer></script></head>
<body class="rampur-dashboard"><header><a class="brand" href="https://pollmedia.org">Pollmedia</a><a href="{{ route('district-dashboard-directory') }}">Change district →</a></header><main>
<section class="district-title"><div><p class="eyebrow">Census {{ $data['year'] }} · published records</p><h1>{{ $data['name'] }} district</h1></div></section>
@if($sourceMap)
@include('district-source-map')
@else
<p class="notice">District demographics are available now. A source village map has not yet been prepared for this district.</p>
@endif
@php($total=$data['totals']['Total'])
<section class="metrics">
@foreach(['population'=>'Population','households'=>'Households','literacy'=>'Literacy age 7+','workers'=>'Main & marginal workers'] as $key=>$label)
<article><span>{{ $label }}</span><strong>{{ ($total[$key]??null)===null ? 'Unavailable' : number_format($total[$key],$key==='literacy'?2:0).($key==='literacy'?'%':'') }}</strong><small>Census {{ $data['year'] }}</small></article>
@endforeach
</section>
@include('district-dashboard-history')
@include('district-dashboard-elections')
<section class="panel"><h2>Available subdistricts & towns</h2><div class="table-wrap"><table><thead><tr><th>Place</th><th>Type</th><th>Population</th><th>Literacy 7+</th></tr></thead><tbody>
@foreach(['subdistricts'=>'Subdistrict','towns'=>'Town'] as $key=>$label)
@foreach($data[$key] as $place)
<tr><td><a href="{{ route('civic.place',['record'=>$place['id']]) }}">{{ $place['name'] }}</a></td><td>{{ $label }}</td><td>{{ $place['population']===null?'Unavailable':number_format($place['population']) }}</td><td>{{ $place['literacy']===null?'Unavailable':number_format($place['literacy'],2).'%' }}</td></tr>
@endforeach
@endforeach
</tbody></table></div></section>
<section class="panel"><h2>Publication coverage</h2><p>{{ $data['census_village_rows'] }} village Census rows are available in the selected published sources. Missing records remain unavailable.</p>
@foreach($data['census_editions'] as $edition)
<p><a href="{{ $edition['source_url'] }}">{{ $edition['source_key'] }} · official source ↗</a></p>
@endforeach
</section></main></body></html>
