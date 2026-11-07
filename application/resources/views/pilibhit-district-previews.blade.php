<section class="pb-wide pb-record-section" aria-labelledby="pb-topics-title">
<h2 id="pb-topics-title">{{ $tr('Explore district records','ज़िले के रिकॉर्ड देखें') }}</h2>
<div class="pb-previews pb-record-grid">
@foreach(['panchayats','elections','rainfall','soil'] as $key)
<article class="pb-panel pb-record-card" data-pb-preview="{{ $key }}">
<h3>{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }}</h3>
@if($key==='rainfall' && $previews['rain'])
<p>{{ $tr('Annual rainfall over mapped district area','मानचित्रित ज़िला क्षेत्र की वार्षिक वर्षा') }} · IMD</p>
@foreach($previews['rain'] as $year=>$mm)
<div class="pb-rain-row"><span>{{ $year }}</span><div class="pb-track" aria-hidden="true"><i style="width:{{ 100*$mm/max(1,max($previews['rain'])) }}%"></i></div><strong>{{ $fmt($mm) }} {{ $tr('mm','मिमी') }}</strong></div>
@endforeach
@elseif($key==='soil' && $previews['soil'])
<p><strong>{{ $fmt($previews['soil']['low']) }}</strong> {{ $tr('of','/') }} {{ $fmt($previews['soil']['tested']) }} {{ $tr('tested samples had low nitrogen','परीक्षित नमूनों में नाइट्रोजन कम थी') }}</p>
@if($previews['soil']['tested']>0)<div class="pb-track pb-soil-track" aria-hidden="true"><i style="width:{{ 100*$previews['soil']['low']/$previews['soil']['tested'] }}%"></i></div>@endif
<small>{{ $tr('Soil Health Card','मृदा स्वास्थ्य कार्ड') }} · {{ $previews['soil']['cycle'] }}</small>
@elseif($key==='panchayats' && $previews['directory'])
<p><strong>{{ $fmt($previews['directory']['panchayats']) }}</strong> {{ $tr('Gram Panchayats','ग्राम पंचायतें') }} · {{ $fmt($previews['directory']['villages']) }} {{ $tr('linked villages','संबंधित गाँव') }}</p>
<table class="pb-sample"><thead><tr><th scope="col">{{ $tr('Panchayat','पंचायत') }}</th><th scope="col">{{ $tr('LGD code','एलजीडी कोड') }}</th><th scope="col">{{ $tr('Villages','गाँव') }}</th></tr></thead>
<tbody>@foreach($previews['directory']['rows'] as $gp)
<tr><th scope="row">{{ $gp['name'] }}</th><td>{{ $gp['code'] }}</td><td>{{ $fmt($gp['villages']) }}</td></tr>
@endforeach</tbody></table>
<small>LGD · {{ $previews['directory']['date'] }}</small>
@elseif($key==='elections' && $previews['elections'])
<p><strong>{{ $fmt($previews['elections']['assembly']) }}</strong> {{ $tr('Assembly seats','विधानसभा क्षेत्र') }} · {{ $fmt($previews['elections']['parliamentary']) }} {{ $tr('linked Lok Sabha seats','संबंधित लोकसभा क्षेत्र') }}</p>
<table class="pb-sample"><thead><tr><th scope="col">{{ $tr('Constituency','निर्वाचन क्षेत्र') }}</th><th scope="col">{{ $tr('Seat','सीट') }}</th></tr></thead>
<tbody>@foreach($previews['elections']['rows'] as $seat)
<tr><th scope="row"><a href="{{ $hi ? url($seat['url']).(str_contains($seat['url'],'?')?'&':'?').'lang=hi' : $seat['url'] }}">{{ $seat['name'] }}</a></th><td>{{ $seat['type'] }} {{ $seat['code'] }}</td></tr>
@endforeach</tbody></table>
<small>{{ $tr('Assembly boundary source','विधानसभा सीमा स्रोत') }} · {{ $previews['elections']['date'] }}</small>
@else
<p>{{ $tr('Summary unavailable','सारांश उपलब्ध नहीं है') }}</p>
@endif
@php($moreLabels=['panchayats'=>['Browse all Panchayats','सभी पंचायतें देखें'],'elections'=>['Explore election records','चुनाव रिकॉर्ड देखें'],'rainfall'=>['View rainfall by month','मासिक वर्षा देखें'],'soil'=>['View soil records','मृदा रिकॉर्ड देखें']])
<a class="pb-more" href="{{ $key==='panchayats'?$gpUrl:$url($key) }}">{{ $moreLabels[$key][$hi?1:0] }} →</a>
</article>
@endforeach
</div>
</section>
