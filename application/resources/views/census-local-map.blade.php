<section id="local-map" class="panel local-map-panel india-explorer"><h2>{{ $localMapTitle ?? 'Village map & filters' }}</h2>
@if(!empty($censusMapContext['reason']))<p role="status">{{ $censusMapContext['reason'] }}</p>
@else
@if($stateMap ?? false)<p class="small" data-methodology-note>Compare Census 2001 and 2011 with the vertical divider. Map summaries cover uniquely linked village observations; complete dated district figures are in the tables below. Historical boundary comparability is unverified. Missing values remain unavailable.</p>
@else<p class="small" data-methodology-note>Existing linked polygons and dated observations. {{ $censusMapContext['unlinked'] ?? 0 }} source village identities have no unique official correspondence. {{ $censusMapContext['no_geometry'] ?? 0 }} linked source identities have no available polygon. Unavailable values are not zero.</p>@endif
@php
$catalogue=app(\App\Services\IndiaVillageMap::class)->catalogue();
$electoral=app(\App\Services\ElectoralVillageMap::class)->catalogue();
@endphp
@if($stateMap ?? false)
@php
$mapStateName=collect($electoral['states'])->firstWhere('code',(string)$censusMapContext['state'])['name']??$place->name;
$stateCatalogue=$catalogue;
$stateCatalogue['layers']=array_values(array_filter($catalogue['layers'],fn ($layer) => strcasecmp($layer['state'],$mapStateName)===0));
$stateCatalogue['context_layers']=array_values(array_filter($catalogue['context_layers']??[],fn ($layer) => strcasecmp($layer['state'],$mapStateName)===0));
@endphp
<script type="application/json" id="state-map-catalogue">{!! json_encode($stateCatalogue,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
@endif
@include('india-map-explorer',['dashboardMap'=>true])
@endif</section>
