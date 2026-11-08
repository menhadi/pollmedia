<section class="atlas-region" aria-label="Constituency map" data-atlas-region>
<div class="atlas-map-frame" hidden><svg viewBox="0 0 360 250" aria-label="{{ $name }} constituency reference map" role="img"></svg></div>
<div><p class="atlas-eyebrow">{{ $tr('Constituency map','निर्वाचन क्षेत्र का नक्शा') }}</p><h2>{{ $tr('Constituency coverage','निर्वाचन क्षेत्र का विस्तार') }}</h2><p class="atlas-caption">{{ $tr('Reference boundaries can differ from those used in earlier elections.','पुराने चुनावों की सीमाएँ इस संदर्भ नक्शे से भिन्न हो सकती हैं।') }}</p>
@if($coverage)<table><thead><tr><th>Assembly segment</th><th>Mapped villages</th></tr></thead><tbody>@foreach($coverage as $ac=>$villages)<tr><th>{{ \Illuminate\Support\Str::headline($ac) }}</th><td>{{ count($villages) }}</td></tr>@endforeach</tbody></table>
@elseif($mapVillages)<p class="atlas-map-stat"><strong>{{ count($mapVillages) }}</strong> {{ $tr('mapped villages','मैप किए गए गाँव') }}</p>
@else<ul data-atlas-segments></ul>@endif
@if($coverage || $mapVillages)<small>Local Government Directory · 16 September 2026</small>@endif
<p class="atlas-caption" data-atlas-map-source></p></div>
</section>
<details class="atlas-map-explorer" open><summary>{{ $tr('Explore the state map','राज्य का नक्शा देखें') }}</summary>
@include('election-map',['mapKind'=>$kind,'mapStateName'=>$state,'mapSelectedName'=>$name,'mapMode'=>'focus'])
</details>
