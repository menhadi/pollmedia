<section class="pb-wide pb-record-section" aria-labelledby="pb-topics-title">
<h2 id="pb-topics-title">{{ $tr('Explore district records','ज़िले के रिकॉर्ड देखें') }}</h2>
<div class="pb-previews pb-record-grid">
@foreach(['panchayats','elections','rainfall','soil'] as $key)
@continue(empty($visible[$key]))
<article class="pb-panel pb-record-card" data-pb-preview="{{ $key }}">
<h3>{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }}</h3>
@if($key==='rainfall' && $previews['rain'])
<p>{{ $tr('Annual rainfall over mapped district area','मानचित्रित ज़िला क्षेत्र की वार्षिक वर्षा') }}</p>
@foreach($previews['rain'] as $year=>$mm)
<div class="pb-rain-row"><span>{{ $year }}</span><div class="pb-track" aria-hidden="true"><i style="width:{{ 100*$mm/max(1,max($previews['rain'])) }}%"></i></div><strong>{{ $fmt($mm) }} {{ $tr('mm','मिमी') }}</strong></div>
@endforeach
@elseif($key==='soil' && $previews['soil'])
<p><strong>{{ $fmt($previews['soil']['low']) }}</strong> {{ $tr('of','/') }} {{ $fmt($previews['soil']['tested']) }} {{ $tr('tested samples had low nitrogen','परीक्षित नमूनों में नाइट्रोजन कम थी') }}</p>
@if($previews['soil']['tested']>0)<div class="pb-track pb-soil-track" aria-hidden="true"><i style="width:{{ 100*$previews['soil']['low']/$previews['soil']['tested'] }}%"></i></div>@endif
@unless($pilot)<small>{{ $tr('Samples from linked villages','संबंधित गाँवों के नमूने') }}</small>@endunless
<small>{{ $tr('Testing cycle','परीक्षण चक्र') }} {{ $previews['soil']['cycle'] }}</small>
@elseif($key==='panchayats' && $previews['directory'])
@if(isset($previews['directory']['panchayats']))<p><strong>{{ $fmt($previews['directory']['panchayats']) }}</strong> {{ $tr('Gram Panchayats','ग्राम पंचायतें') }} · {{ $fmt($previews['directory']['villages']) }} {{ $tr('linked villages','संबंधित गाँव') }}</p>
@endif
<table class="pb-sample"><thead><tr><th scope="col">{{ $tr('Panchayat','पंचायत') }}</th>@if(isset($previews['directory']['villages']))<th scope="col">{{ $tr('Villages','गाँव') }}</th>@endif</tr></thead>
<tbody>@foreach($previews['directory']['rows'] as $gp)
<tr><th scope="row">@if(isset($gp['state'],$gp['gp']))<a href="{{ route('gram-panchayats.show',['state'=>$gp['state'],'gp'=>$gp['gp'],'lang'=>$hi?'hi':null]) }}">{{ $gp['name'] }}</a>@else{{ $gp['name'] }}@endif</th>@if(isset($gp['villages']))<td>{{ $fmt($gp['villages']) }}</td>@endif</tr>
@endforeach</tbody></table>
@elseif($key==='elections' && $previews['elections'])
<p><strong>{{ $fmt($previews['elections']['assembly']) }}</strong> {{ $tr('Assembly seats','विधानसभा क्षेत्र') }} · {{ $fmt($previews['elections']['parliamentary']) }} {{ $tr($previews['elections']['parliamentary']===1?'linked Lok Sabha seat':'linked Lok Sabha seats','संबंधित लोकसभा क्षेत्र') }}</p>
<table class="pb-sample"><thead><tr><th scope="col">{{ $tr('Constituency','निर्वाचन क्षेत्र') }}</th><th scope="col">{{ $tr('Seat','सीट') }}</th></tr></thead>
<tbody>@foreach($previews['elections']['rows'] as $seat)
<tr><th scope="row"><a href="{{ $hi ? url($seat['url']).(str_contains($seat['url'],'?')?'&':'?').'lang=hi' : $seat['url'] }}">{{ $seat['name'] }}</a></th><td>{{ $seat['type'] }} {{ $seat['code'] }}</td></tr>
@endforeach</tbody></table>
@else
<p>{{ $tr('Summary unavailable','सारांश उपलब्ध नहीं है') }}</p>
@endif
@php($moreLabels=['panchayats'=>['Browse all Panchayats','सभी पंचायतें देखें'],'elections'=>['Explore election records','चुनाव रिकॉर्ड देखें'],'rainfall'=>['View rainfall by month','मासिक वर्षा देखें'],'soil'=>['View soil records','मृदा रिकॉर्ड देखें']])
@if($key==='panchayats' && !empty($previews['directory']['parents']))
@foreach($previews['directory']['parents'] as $code=>$parentName)<a class="pb-more" href="{{ route('my-place.index',['state'=>9,'district'=>$code,'topic'=>'gram-panchayat','lang'=>$hi?'hi':null]) }}">{{ $tr('Panchayat records','पंचायत रिकॉर्ड') }} · {{ $parentName }} →</a>@endforeach
@else
<a class="pb-more" href="{{ $key==='panchayats'?$gpUrl:$url($key) }}">{{ $moreLabels[$key][$hi?1:0] }} →</a>
@endif
</article>
@endforeach
</div>
</section>
