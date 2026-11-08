@if($mapStateInfo)
<section class="atlas-state-explorers" aria-labelledby="atlas-state-maps">
<h2 id="atlas-state-maps" tabindex="-1">{{ $state }} · {{ $tr('Constituency maps','निर्वाचन क्षेत्र के नक्शे') }}</h2>
<div class="atlas-map-pair">
@foreach(['pc'=>$tr('Lok Sabha (PC) map','लोक सभा (PC) का नक्शा'),'ac'=>$tr('Assembly map','विधान सभा का नक्शा')] as $layer=>$mapTitle)
@php
    $layerAvailable=($mapStateInfo['counts'][$layer]??0)>0 || ($layer==='ac' && $mapStateInfo['slug']==='telangana');
    $linked=$exactSeatOnly?[]:($atlasFamily[$layer]??[]);
    $selectedMapName=$exactSeatOnly?'':($kind===$layer?$name:(count($linked)===1?$linked[0]['name']:''));
@endphp
@if($layerAvailable)
<section class="atlas-map-explorer" data-layer="{{ $layer }}" @if($layer===$kind) data-current-layer @endif aria-labelledby="atlas-{{ $layer }}-title">
<h3 id="atlas-{{ $layer }}-title">{{ $mapTitle }}</h3>
@if($layer==='ac' && in_array($mapStateInfo['slug'],['andhra-pradesh','telangana'],true))<p class="atlas-caption">{{ $tr('Reference outlines from undivided Andhra Pradesh.','अविभाजित आंध्र प्रदेश की संदर्भ सीमाएँ।') }}</p>@endif
@include('election-map',['mapKind'=>$layer,'mapStateName'=>$state,'mapSelectedName'=>$selectedMapName,'mapMode'=>'focus','atlasExplorer'=>true,'atlasLinkedSeats'=>$layer===$kind?[]:$linked])
</section>
@endif
@endforeach
</div></section>
@endif
