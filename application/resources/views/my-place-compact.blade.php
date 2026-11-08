@php
    $previews=app(\App\Services\MyPlaceOverview::class)->previews($context);
    $village=$context['village'];
    $districtName=$label($context['district']['name']);
    $localScope=$village?$t('Village','गाँव'):$t('District','ज़िला');
    $scopeName=$village?$label($village['name']):$districtName;
    $fmt=new \NumberFormatter('en_IN',\NumberFormatter::DECIMAL);
    $params=array_filter(['state'=>$context['state']['code'],'district'=>$context['district']['code'],'village'=>$village['code']??null,'subdistrict'=>request('subdistrict'),'locality'=>isset($context['locality'])?request('locality'):null,'lang'=>$language],fn($v)=>$v!==null && $v!=='');
    $more=fn($key)=>route('my-place.index',$params+['view'=>'details','topic'=>$key]);
    $metrics=array_filter(['population'=>$facts['population']??null,'households'=>$facts['households']??null,'literacy'=>$facts['literacy']??null],fn($v)=>is_numeric($v) && $v>=0);
    $labels=['population'=>['Residents','निवासी'],'households'=>['Households','परिवार'],'literacy'=>['Literacy, age 7+','साक्षरता, आयु 7+']];
    $water=$facts['water']??null;
    $validWater=is_numeric($water['households']??null) && is_numeric($water['tap_connections']??null) && $water['households']>0 && $water['tap_connections']>=0 && $water['tap_connections']<=$water['households'];
    $units=['Quintal'=>['quintal','क्विंटल'],'Kilogram'=>['kg','किलो'],'Nos'=>['item','नग'],'Bundle'=>['bundle','गट्ठर']];
@endphp
<div class="mp-overview" data-my-place-compact data-pilibhit-native>
<header class="place-panel mp-heading"><p class="eyebrow">{{ $label($context['state']['name']) }} · {{ $districtName }}</p>
<h2 data-place-heading="{{ $placeHeading }}">{{ $placeTitle }}</h2>
@if(!empty($context['locality']))<p>{{ $t('Detected locality. The figures below cover the district.','चिह्नित इलाका। नीचे दिए गए आँकड़े पूरे ज़िले के हैं।') }}</p>@endif
<a href="{{ $more('overview') }}">{{ $t('Open full My Place details','मेरा क्षेत्र के विस्तृत रिकॉर्ड खोलें') }} →</a></header>
<div class="mp-grid">
@if($metrics)<section class="place-panel mp-card" data-mp-card="census" data-scope="{{ $village?'village':'district' }}"><p class="mp-scope">{{ $localScope }} · {{ $label($facts['census_name']??($village['name']??$context['district']['name'])) }}</p>
<h3>{{ $t('Population and households','जनसंख्या और परिवार') }}</h3><p class="mp-note">{{ $t('Census 2011','जनगणना 2011') }}</p>
<table><tbody>@foreach($metrics as $key=>$value)<tr><th scope="row">{{ $labels[$key][$hi?1:0] }}</th><td>{{ $fmt->format($value) }}{{ $key==='literacy'?'%':'' }}</td></tr>@endforeach</tbody></table>
@if($village || $context['district_dashboard'])<a class="mp-more" href="{{ url($village?$context['dashboard']:$context['district_dashboard']).'?'.http_build_query(['lang'=>$language]) }}">{{ $village?$t('Open village dashboard','गाँव का डैशबोर्ड खोलें'):$t('Open district dashboard','ज़िला डैशबोर्ड खोलें') }} →</a>@endif</section>@endif
@if($validWater)
@php($coverage=100*$water['tap_connections']/$water['households'])
<section class="place-panel mp-card" data-mp-card="water" data-scope="{{ $village?'village':'district' }}"><p class="mp-scope">{{ $localScope }} · {{ $scopeName }}</p><h3>{{ $t('Household tap water','घरेलू नल जल') }}</h3>
<strong class="mp-value">{{ $fmt->format(round($coverage,2)) }}%</strong><div class="mp-track" aria-hidden="true"><i style="width:{{ $coverage }}%"></i></div>
<p>{{ $fmt->format($water['tap_connections']) }} / {{ $fmt->format($water['households']) }} {{ $t('households with a reported tap connection','परिवारों में नल कनेक्शन दर्ज') }}</p><a class="mp-more" href="{{ $more('water') }}">{{ $t('Explore water records','जल रिकॉर्ड देखें') }} →</a></section>@endif
@if($gp=$previews['gp'])<section class="place-panel mp-card" data-mp-card="panchayat" data-scope="panchayat"><p class="mp-scope">{{ $t('Gram Panchayat','ग्राम पंचायत') }} · {{ $label($gp['name']) }}</p>
@if($previews['payments'])<h3>{{ $t('Gram Panchayat payments','ग्राम पंचायत भुगतान') }}</h3>
<table><thead><tr><th scope="col">{{ $t('Financial year','वित्तीय वर्ष') }}</th><th scope="col">{{ $t('Reported total','दर्ज कुल') }}</th></tr></thead><tbody>
@foreach(array_slice($previews['payments'],0,3) as $p)<tr><th scope="row">{{ $p['year'] }}</th><td translate="no">{{ $p['unit']==='INR'?'₹':'' }}{{ $p['source_amount'] }}</td></tr>@endforeach</tbody></table>
<p class="mp-note">{{ $t('Whole Panchayat','पूरी पंचायत') }}@if(collect(array_slice($previews['payments'],0,3))->contains(fn($p)=>$p['unit']===null)) · {{ $t('Source unit unconfirmed','स्रोत की इकाई अपुष्ट') }}@endif</p>
@else<h3>{{ $t('Linked Gram Panchayat','संबंधित ग्राम पंचायत') }}</h3><p>{{ $label($gp['name']) }}</p>@endif
<a class="mp-more" href="{{ route('gram-panchayats.show',['state'=>$context['state']['code'],'gp'=>$gp['gp'],'lang'=>$language]) }}">{{ $t('Open Panchayat profile','पंचायत प्रोफ़ाइल खोलें') }} →</a></section>@endif
@if($previews['prices']->isNotEmpty())<section class="place-panel mp-card" data-mp-card="markets" data-scope="district"><p class="mp-scope">{{ $t('District','ज़िला') }} · {{ $districtName }}</p><h3>{{ $t('District mandi prices','ज़िले के मंडी भाव') }}</h3>
<table><thead><tr><th scope="col">{{ $t('Commodity and mandi','जिंस और मंडी') }}</th><th scope="col">{{ $t('Modal price','प्रचलित भाव') }}</th></tr></thead><tbody>
@foreach($previews['prices'] as $p)<tr><th scope="row">{{ $hi?($p['commodity_hi']??$p['commodity']):$p['commodity'] }}<small>{{ $p['market'] }} · {{ $p['date'] }}</small></th><td>₹{{ $fmt->format($p['modal']) }}<small>/ {{ $units[$p['unit']][$hi?1:0]??$p['unit'] }}</small></td></tr>@endforeach</tbody></table>
<a class="mp-more" href="{{ $more('local-markets') }}">{{ $t('Explore district market records','ज़िले के मंडी रिकॉर्ड देखें') }} →</a></section>@endif
</div>
@if(!$metrics && !$validWater && !$previews['gp'] && $previews['prices']->isEmpty())<p class="place-panel">{{ $t('The directory confirms this selection. Open the full details to browse its place and service records.','निर्देशिका में यह स्थान दर्ज है। स्थान और सेवा रिकॉर्ड देखने के लिए विस्तृत विवरण खोलें।') }}</p>@endif
</div>
