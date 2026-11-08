@php
 $hi=$language==='hi'; $t=fn($en,$hindi)=>$hi?$hindi:$en;
 $names=app(\App\Services\HindiPlaceNames::class); $label=fn($s)=>$hi?($names->translated($s,true)??$s):$s;
 $placeTitle=$label($context['locality']['name']??$context['village']['name']??$context['district']['name']);
 $subdistrict=$context['village']['subdistrict']??collect($context['locality']['scopes']??[])->firstWhere('kind','subdistrict')['name']??null;
 $placeHeading=implode(' · ',array_values(array_unique(array_filter([$placeTitle,$subdistrict?$label($subdistrict):null,$label($context['district']['name']),$label($context['state']['name'])]))));
 $facts=$context['facts']; $number=fn($n,$suffix='')=>is_numeric($n)?number_format((float)$n,$suffix==='%'?2:0).$suffix:$t('Unavailable','उपलब्ध नहीं');
@endphp
<?php $topic = \App\Services\PlaceTopics::selected('my'); ?>
@if($topic==='overview' && request('view')!=='details')
@include('my-place-compact')
@else
<section class="place-panel place-overview"><p class="eyebrow">{{ $label($context['state']['name']) }} · {{ $label($context['district']['name']) }}</p><h2 data-place-heading="{{ $placeHeading }}">{{ $placeTitle }}</h2></section>
@if(request('view')==='details')
<p><a href="{{ route('my-place.index',request()->except(['view','topic','pm_panel'])) }}">← {{ $t('My Place summary','मेरा क्षेत्र का सारांश') }}</a></p>@endif
@include('place-topic-nav',['topicGroup'=>'my'])
<div data-topic-body>
<?php if (in_array($topic, ['overview'], true)): ?>
<section class="place-panel">@if(!empty($context['locality']))
<p class="place-note">{{ $t('Detected locality. Facts and services below cover the district; locality-level figures are unavailable.','नीचे के आँकड़े और सेवाएँ जिले के हैं।') }}</p>
<div class="place-jurisdictions">@foreach(['district'=>'District','pc'=>'PC · Lok Sabha','ac'=>'AC · Assembly'] as $kind=>$scopeLabel)@php $scope=collect($context['locality']['scopes'])->firstWhere('kind',$kind); @endphp @if($scope)<div><span>{{ $scopeLabel }}</span><strong>{{ $label($scope['name']) }}</strong></div>@endif @endforeach</div>
@endif
@if(($facts['scope']??null)==='district')<p class="place-note">{{ $t('District figures · Census 2011 boundaries','जिले के आँकड़े · जनगणना 2011 की सीमाएँ') }}: {{ $facts['census_name']??$label($context['district']['name']) }}. {{ $t('These are not locality totals.','ये स्थानीय इलाके के आँकड़े नहीं हैं।') }}</p>@endif
<div class="place-facts"><article><span>{{ $t('Population · Census 2011','जनसंख्या · जनगणना 2011') }}</span><strong>{{ $number($facts['population']??null) }}</strong></article>
<article><span>{{ $t('Households · Census 2011','परिवार · जनगणना 2011') }}</span><strong>{{ $number($facts['households']??null) }}</strong></article><article><span>{{ $t('Literacy · age 7+ · 2011','साक्षरता · आयु 7+ · 2011') }}</span><strong>{{ $number($facts['literacy']??null,'%') }}</strong></article>
<article><span>{{ $t('Tap connection coverage · JJM','नल कनेक्शन कवरेज · जल जीवन मिशन') }}</span><strong>{{ $number($facts['water']['coverage_pct']??null,'%') }}</strong>
@if(!empty($facts['water']))
<small>{{ $number($facts['water']['tap_connections']) }} / {{ $number($facts['water']['households']) }} {{ $t('JJM households','JJM परिवार') }}</small>
@endif
@if(!empty($facts['water_source']))
<small>{{ $t('Retrieved','प्राप्त') }} {{ substr($facts['water_source']['retrieved_at'],0,10) }}</small>
@endif
</article></div>
<div class="place-actions"><a class="place-button" href="{{ url($context['dashboard']).'?lang='.$language }}">{{ $t('All place data & maps','क्षेत्र के सभी आँकड़े और नक्शे') }} &rarr;</a></div><p class="place-note">{{ $t('Explore Census history, water, soil, rainfall and available satellite maps.','जनगणना इतिहास, पानी, मिट्टी, वर्षा और उपलब्ध उपग्रह नक्शे देखें।') }}</p></section>
<?php endif; ?>
<?php if (in_array($topic, ['places'], true)): ?>
@if(!empty($context['village']['code']))
@include('place-postal-codes', ['postal'=>app(\App\Services\PlacePostalCodes::class)->lookup('village',(string)$context['village']['code'],(string)$context['state']['code'],['district'=>(string)$context['district']['code'],'subdistrict'=>(string)($context['village']['subdistrict_code']??'')])])
@endif
<nav class="approved-context-links" aria-label="{{ $t('Administrative connections','प्रशासनिक संबंध') }}">
<h2>{{ $t('Explore your connections','अपने क्षेत्र के संबंध देखें') }}</h2>
@php
$placeSeats=[];
if(!empty($context['village']['code'])) {
    $linksDb=app(\App\Services\MyPlaceDirectory::class)->database();
    $readyTables=$linksDb->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name IN ('membership','seats','metadata')")->fetchColumn();
    if((int)$readyTables===3) {
        $placeSeats=app(\App\Services\ElectoralVillageMap::class)->connections((string)$context['village']['code'])['constituencies']??[];
    }
}
@endphp
<ol class="approved-context-tree">
<li><a href="{{ route('civic.state',['state'=>\Illuminate\Support\Str::slug($context['state']['name'])]) }}">{{ $label($context['state']['name']) }}</a></li>
<li><a href="{{ route('district-dashboard.named',['state'=>\Illuminate\Support\Str::slug($context['state']['name']),'district'=>\Illuminate\Support\Str::slug($context['district']['name'])]) }}">{{ $label($context['district']['name']) }}</a></li>
@if($subdistrict)<li>{{ $label($subdistrict) }}</li>@endif
@if(!empty($context['village']))<li><a href="{{ url($context['dashboard']) }}">{{ $label($context['village']['name']) }}</a></li>@endif
</ol>
@if($placeSeats)
<h3>{{ $t('Related electoral areas','संबंधित निर्वाचन क्षेत्र') }}</h3>
<ul class="approved-context-tree">@foreach($placeSeats as $seat)<li><a href="{{ $seat['url'] }}">{{ $seat['kind']==='pc'?'Lok Sabha (PC)':'Assembly (AC)' }}: {{ $label($seat['name']) }}</a>@if($seat['partial']) · {{ $t('Partly covered','आंशिक संबंध') }}@endif</li>@endforeach</ul>
@endif
</nav>
<?php endif; ?>
<?php if (in_array($topic, ['water'], true)): ?>
@include('water-evidence-panel',['waterState'=>(string)$context['state']['code'],'waterDistrict'=>(string)$context['district']['code'],'waterVillage'=>isset($context['village']['code'])?(string)$context['village']['code']:null,'waterLanguage'=>$language])
<?php endif; ?>
<?php if (!in_array($topic,['overview','places','water'],true)): ?>
@include('my-place-hub')
<?php endif; ?>
</div>
@endif
