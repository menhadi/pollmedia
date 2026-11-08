@php
    if (app()->getLocale()==='hi') {
        $names=app(\App\Services\HindiPlaceNames::class);
        $mapVillages=collect($mapVillages)->map(fn($village)=>$village+['label'=>$names->translated($village['name'],true)??$village['name']])->all();
    }
    $rawQuery=request()->query('village_q');
    $query=is_string($rawQuery)?mb_substr(trim($rawQuery),0,120):'';
    $filtered=collect($mapVillages)->filter(fn($village)=>$query==='' || mb_stripos($village['name'],$query)!==false || mb_stripos($village['label']??$village['name'],$query)!==false)->values();
    $size=20;
    $last=max(1,(int)ceil($filtered->count()/$size));
    $page=min($last,max(1,filter_var(request()->query('village_page'),FILTER_VALIDATE_INT)?:1));
    $pageRows=$filtered->forPage($page,$size)->values();
    $base=\App\Services\ConstituencyUrls::url(['kind'=>$kind,'state'=>$state,'name'=>$name],false);
    $params=array_filter(request()->only(['edition','code','lang']),'is_scalar');
    $pageUrl=fn($number)=>$base.'?'.http_build_query($params+['village_q'=>$query,'village_page'=>$number]).'#linked-villages';
    $villageUrl=route('electoral-villages.village',['code'=>'VILLAGECODE','lang'=>app()->getLocale()],false);
@endphp
<section id="linked-villages" class="atlas-registry" data-village-directory data-url-pattern="{{ $villageUrl }}" data-size="{{ $size }}" data-page="{{ $page }}" aria-labelledby="village-directory-title">
<div class="registry-heading"><div><h2 id="village-directory-title">{{ $tr('Linked villages','संबद्ध गाँव') }}</h2><p>{{ number_format(count($mapVillages)) }} {{ $tr('villages in the published mapping','गाँव प्रकाशित मानचित्रण में') }}</p></div>
<form method="get" action="{{ $base }}#linked-villages" data-village-search>
@foreach($params as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<label for="atlas-village-query">{{ $tr('Search villages','गाँव खोजें') }}</label>
<input id="atlas-village-query" type="search" name="village_q" value="{{ $query }}" maxlength="120" autocomplete="off" aria-controls="atlas-village-table">
<button type="submit">{{ $tr('Search','खोजें') }}</button>
</form></div>
<table id="atlas-village-table"><thead><tr><th scope="colgroup" colspan="4">{{ $tr('Village name','गाँव का नाम') }}</th></tr></thead><tbody>
@foreach($pageRows->chunk(4) as $group)<tr>@foreach($group as $village)<td><a href="{{ str_replace('VILLAGECODE',$village['code'],$villageUrl) }}">{{ $village['label']??$village['name'] }}</a></td>@endforeach</tr>@endforeach
</tbody></table><p data-village-empty @if($filtered->isNotEmpty()) hidden @endif>{{ $tr('No villages match this search.','इस खोज से कोई गाँव नहीं मिला।') }}</p>
<div class="registry-paging">
<span data-village-status role="status">@if($filtered->isNotEmpty()){{ ($page-1)*$size+1 }}–{{ min($page*$size,$filtered->count()) }} / {{ number_format($filtered->count()) }}@endif</span>
<nav aria-label="{{ $tr('Village pages','गाँवों के पृष्ठ') }}"><a data-village-prev href="{{ $pageUrl(max(1,$page-1)) }}" @if($page<=1) hidden @endif>{{ $tr('Previous','पिछला') }}</a><a data-village-next href="{{ $pageUrl(min($last,$page+1)) }}" @if($page>=$last) hidden @endif>{{ $tr('Next','अगला') }}</a></nav>
</div>
<script type="application/json" data-village-rows>{!! json_encode($mapVillages,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) !!}</script>
</section>
