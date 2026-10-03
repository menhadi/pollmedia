@php $printMapPaths=app(\App\Services\PrintableElectionMap::class)->paths($kind,$printMapState,$printMapSeat??null); @endphp
@if($printMapPaths)
<section class="printable-election-map" style="break-inside:avoid;page-break-inside:avoid"><h2>{{ $printMapState }} · {{ $kind==='pc'?'Lok Sabha':'Assembly' }} map</h2>
<svg viewBox="0 0 600 600" role="img" aria-label="{{ $printMapSeat??$printMapState }} constituency map" style="display:block;width:100%;max-height:420px;-webkit-print-color-adjust:exact;print-color-adjust:exact">
@foreach($printMapPaths as $shape)<path d="{{ $shape['path'] }}" fill="{{ $shape['selected']?'#075b4f':'#e2ebed' }}" stroke="{{ $shape['selected']?'#123d35':'#809aa5' }}" stroke-width="0.65" fill-rule="evenodd"><title>{{ $shape['name'] }}</title></path>@endforeach
</svg>@if($printMapSeat??null)<p><i style="display:inline-block;width:12px;height:12px;background:#075b4f;print-color-adjust:exact"></i> {{ $printMapSeat }}@if(isset($printMapResult)) · {{ $printMapResult['winner']??'Winner not established' }} · {{ $printMapResult['party']??'—' }}@endif</p>@endif</section>
@endif
