@if($visible['places'])
<section class="pb-panel pb-wide" id="places">
@if($villages->total() || $search!=='')
<div class="pb-search"><div><h2>{{ $tr('Village directory','गाँव निर्देशिका') }}</h2><p class="pb-sub">{{ $census }}</p></div>
<form data-pb-form method="get" action="{{ $path }}#places">
@if($topic==='places')<input type="hidden" name="topic" value="places">@endif @if($hi)<input type="hidden" name="lang" value="hi">@endif
<input type="search" name="q" maxlength="120" aria-label="{{ $tr('Village name','गाँव का नाम') }}" placeholder="{{ $tr('Village name','गाँव का नाम') }}" value="{{ $search }}"><button>{{ $tr('Search','खोजें') }}</button></form></div>
<p class="pb-status" role="status" data-pb-status></p>
@include('pilibhit-summary-villages')
@endif
@if($topic==='places')
@foreach(['subdistricts'=>$tr('Subdistricts','उपजिले'),'towns'=>$tr('Towns','कस्बे')] as $kind=>$heading)
@if($data[$kind])<h2 id="pb-{{ $kind }}">{{ $heading }}</h2><ul class="pb-locations">@foreach($data[$kind] as $place)<li><a href="{{ app(\App\Services\NamedPlaceUrls::class)->link(['record'=>$place['id'],'lang'=>$hi?'hi':null]) }}">{{ $place['name'] }}</a></li>@endforeach</ul>@endif
@endforeach
@endif</section>
@endif
