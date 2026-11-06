@extends('geography-layout')
@section('title',($selection['subdistrict']['name']??$selection['district']['name']).' · Place directory')
@section('content')
<p>{{ $selection['state']['name'] }} · {{ $selection['district']['name'] }}</p>
<h1>{{ $selection['subdistrict']['name']??$selection['district']['name'] }} {{ $selection['subdistrict']?'subdistrict':'district' }}</h1>
<nav class="filters" aria-label="Selected place pages">@foreach($selection['navigation'] as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach</nav>
<p>Current LGD directory scope · {{ number_format($total) }} linked places. This selection is separate from historical Census boundaries; Census totals are not assigned to changed areas.</p>
<section class="card"><h2>Villages and places</h2><div class="scroll"><table><thead><tr><th>Place</th><th>LGD code</th><th>Subdistrict</th></tr></thead><tbody>@foreach($villages as $village)<tr><td><a href="{{ route('electoral-villages.village',['code'=>$village['code']]) }}">{{ $village['name'] }}</a></td><td>{{ $village['code'] }}</td><td>{{ $village['subdistrict'] }}</td></tr>@endforeach</tbody></table></div><nav class="filters">@if($page>1)<a href="{{ request()->fullUrlWithQuery(['page'=>$page-1]) }}">Previous</a>@endif @if($page*50<$total)<a href="{{ request()->fullUrlWithQuery(['page'=>$page+1]) }}">Next</a>@endif</nav></section>
<p>Open a village to see its available Census records and verified Parliament (PC) and Assembly (AC) links.</p>
@endsection
