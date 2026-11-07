<section class="pb-panel pb-wide" aria-labelledby="pb-topics-title">
<h2 id="pb-topics-title">{{ $tr('Explore district records','ज़िले के रिकॉर्ड देखें') }}</h2>
<div class="pb-previews">
@foreach(['rainfall','soil','panchayats','elections'] as $key)
<article data-pb-preview="{{ $key }}">
<h3><a href="{{ $key==='panchayats'?$gpUrl:$url($key) }}">{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }} <span aria-hidden="true">→</span></a></h3>
@if($key==='rainfall' && $previews['rain'])
<p>{{ $tr('Annual rainfall over mapped district area','मानचित्रित ज़िला क्षेत्र की वार्षिक वर्षा') }} · IMD</p>
@foreach($previews['rain'] as $year=>$mm)
<div class="pb-rain-row"><span>{{ $year }}</span><div class="pb-track" aria-hidden="true"><i style="width:{{ 100*$mm/max(1,max($previews['rain'])) }}%"></i></div><strong>{{ $fmt($mm) }} {{ $tr('mm','मिमी') }}</strong></div>
@endforeach
@elseif($key==='soil' && $previews['soil'])
<p><strong>{{ $fmt($previews['soil']['low']) }}</strong> {{ $tr('of','/') }} {{ $fmt($previews['soil']['tested']) }} {{ $tr('tested samples had low nitrogen','परीक्षित नमूनों में नाइट्रोजन कम थी') }}</p>
<small>{{ $tr('Soil Health Card','मृदा स्वास्थ्य कार्ड') }} · {{ $previews['soil']['cycle'] }}</small>
@elseif($key==='panchayats' && $previews['directory'])
<p><strong>{{ $fmt($previews['directory']['panchayats']) }}</strong> {{ $tr('Gram Panchayats','ग्राम पंचायतें') }} · {{ $fmt($previews['directory']['villages']) }} {{ $tr('linked villages','संबंधित गाँव') }}</p>
<small>LGD · {{ $previews['directory']['date'] }}</small>
@elseif($key==='elections' && $previews['elections'])
<p><strong>{{ $fmt($previews['elections']['assembly']) }}</strong> {{ $tr('Assembly seats','विधानसभा क्षेत्र') }} · {{ $fmt($previews['elections']['parliamentary']) }} {{ $tr('linked Lok Sabha seats','संबंधित लोकसभा क्षेत्र') }}</p>
<small>{{ $tr('Assembly boundary source','विधानसभा सीमा स्रोत') }} · {{ $previews['elections']['date'] }}</small>
@else
<p>{{ $tr('Open the topic for source records.','स्रोत रिकॉर्ड के लिए विषय खोलें।') }}</p>
@endif
</article>
@endforeach
</div>
<ul class="pb-topic-index">
@foreach(['map'=>['Village boundaries and satellite layers','गाँव की सीमाएँ और उपग्रह चित्र'],'history'=>['Census comparisons across years','विभिन्न वर्षों की जनगणना तुलना'],'authorities'=>['District offices and contacts','ज़िले के कार्यालय और संपर्क'],'markets'=>['Market reports and prices','मंडी रिपोर्ट और भाव'],'issues'=>['Local issue reports','स्थानीय समस्याओं की रिपोर्ट'],'sources'=>['Data references and coverage','आँकड़ों के स्रोत और कवरेज']] as $key=>$description)
<li><a data-pb-preview="{{ $key }}" href="{{ $url($key) }}"><b>{{ \App\Services\PlaceTopics::LABELS[$key][$hi?1:0] }}</b><span>{{ $description[$hi?1:0] }}</span><i aria-hidden="true">→</i></a></li>
@endforeach
</ul>
</section>
