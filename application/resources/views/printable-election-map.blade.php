@php $printMapPaths=app(\App\Services\PrintableElectionMap::class)->paths($kind,$printMapState,$printMapSeat??null); @endphp
@if($printMapPaths)
<section class="printable-election-map" style="break-inside:avoid;page-break-inside:avoid"><h2>{{ $printMapState }} · {{ $kind==='pc'?'Lok Sabha':'Assembly' }} map</h2>
<svg viewBox="0 0 600 {{ ($printMapSeat??null)?690:600 }}" role="img" aria-label="{{ $printMapSeat??$printMapState }} constituency map" style="display:block;width:100%;max-height:480px;-webkit-print-color-adjust:exact;print-color-adjust:exact">
@foreach($printMapPaths as $shape)<path d="{{ $shape['path'] }}" fill="{{ $shape['selected']?'#075b4f':'#e2ebed' }}" stroke="{{ $shape['selected']?'#123d35':'#809aa5' }}" stroke-width="0.65" fill-rule="evenodd"><title>{{ $shape['name'] }}</title></path>@endforeach
@foreach($printMapPaths as $shape)
@if($shape['selected'])
@php
$mapLabelLines=[$printMapSeat.' · '.($kind==='pc'?'Lok Sabha':'AC'),$printMapState];
if(isset($printMapResult)) {
    $mapLabelLines=[$printMapSeat,$printMapState.' · '.($printMapYear??''),trim(($printMapResult['party']??'').(!empty($printMapResult['winner'])?' · '.$printMapResult['winner']:''))];
    $mapLabelLines=array_values(array_filter($mapLabelLines));
}
$labelX=300;
$labelY=620;
@endphp
<g class="print-map-tooltip"><rect x="90" y="{{ $labelY-18 }}" width="420" height="{{ count($mapLabelLines)*17+12 }}" rx="7" fill="white" stroke="#809aa5"/>
<text x="{{ $labelX }}" y="{{ $labelY }}" text-anchor="middle" fill="#123d35" font-family="sans-serif" font-size="12">@foreach($mapLabelLines as $line)<tspan x="{{ $labelX }}" dy="{{ $loop->first?0:17 }}">{{ $line }}</tspan>@endforeach</text></g>
@elseif(!($printMapSeat??null))
<text x="{{ $shape['label_x'] }}" y="{{ $shape['label_y'] }}" text-anchor="middle" font-family="sans-serif" font-size="6" fill="#123d35" stroke="white" stroke-width="1.5" paint-order="stroke">{{ $shape['name'] }}</text>
@endif
@endforeach
</svg></section>
@endif
