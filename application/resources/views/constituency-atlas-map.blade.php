<section class="atlas-region" aria-label="Constituency map" data-atlas-region>
<script type="application/json" data-atlas-ac-pages>{!! json_encode($atlasFamily['ac'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<div class="atlas-map-frame" hidden><svg viewBox="0 0 360 250" aria-label="{{ $name }} constituency reference map" role="group"></svg></div>
<div><h2>{{ $tr('Constituency coverage','निर्वाचन क्षेत्र का विस्तार') }}</h2>
@if($coverage || ($kind==='pc' && $atlasFamily['ac']))
<table><thead><tr><th>{{ $tr('Assembly segment','विधान सभा क्षेत्र') }}</th>@if($coverage)<th>{{ $tr('Mapped villages','मैप किए गए गाँव') }}</th>@endif</tr></thead><tbody>
@php
$mappedRows=collect($atlasFamily['ac'])->map(fn($seat)=>$seat+['slug'=>\Illuminate\Support\Str::slug(preg_replace('/\s*\((SC|ST)\)$/i','',$seat['name']))]);
$missing=collect($coverage)->keys()->diff($mappedRows->pluck('slug'))->map(fn($slug)=>['slug'=>$slug,'name'=>\Illuminate\Support\Str::headline($slug),'url'=>null]);
@endphp
@foreach($mappedRows->concat($missing) as $seat)<tr><th scope="row">@if($seat['url'])<a href="{{ $seat['url'] }}">{{ $seat['name'] }}</a>@else{{ $seat['name'] }}@endif</th>@if($coverage)<td>{{ isset($coverage[$seat['slug']])?count($coverage[$seat['slug']]):'—' }}</td>@endif</tr>@endforeach
</tbody></table>
@elseif($kind==='ac' && $atlasFamily['pc'])
<p>{{ $tr('Lok Sabha constituency','लोक सभा क्षेत्र') }} · @foreach($atlasFamily['pc'] as $seat)@if($seat['url'])<a href="{{ $seat['url'] }}">{{ $seat['name'] }}</a>@else{{ $seat['name'] }}@endif{{ $loop->last?'':' · ' }}@endforeach</p>
@endif
@if($mapVillages)<p class="atlas-map-stat"><strong>{{ count($mapVillages) }}</strong> {{ $tr('mapped villages','मैप किए गए गाँव') }}</p>@endif
@if(!$coverage && !$atlasFamily['ac'])<ul data-atlas-segments></ul>@endif
</div></section>
<section class="atlas-map-explorer" aria-label="{{ $tr('Explore the state map','राज्य का नक्शा देखें') }}">
<h2>{{ $state }} · {{ $kind==='pc'?$tr('Lok Sabha map','लोक सभा का नक्शा'):$tr('Assembly map','विधान सभा का नक्शा') }}</h2><p class="atlas-caption">{{ $tr('Select a constituency on the map or choose it by name to open its page.','क्षेत्र का पेज खोलने के लिए नक्शे पर चुनें या सूची से उसका नाम चुनें।') }}</p>
@include('election-map',['mapKind'=>$kind,'mapStateName'=>$state,'mapSelectedName'=>$name,'mapMode'=>'focus','atlasExplorer'=>true])
</section>
