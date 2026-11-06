@php($postal = $postal ?? app(\App\Services\PlacePostalCodes::class)->village($village))
<section class="coverage" id="postal-codes" aria-label="Postal PIN codes">
<h2>Postal PIN codes</h2>
@if($postal['pins'])
<p>@foreach($postal['pins'] as $pin)<a href="{{ route('postal-areas.index', ['pin' => $pin]) }}">{{ $pin }}</a>{{ $loop->last ? '' : ', ' }}@endforeach</p>
@if(count($postal['pins']) > 1)<p>LGD lists more than one PIN for this area. Confirm the PIN for your address with India Post.</p>@endif
@if($postal['has_unusable_rows'] ?? false)<p>Some source entries have a missing or invalid PIN; only valid PIN entries are shown.</p>@endif
@elseif($postal['status'] === 'identity_mismatch')
<p>PIN information is withheld because the postal source and this page disagree on the administrative identity.</p>
@else
<p>A usable PIN mapping is not available for this area in the imported release.</p>
@endif
@if($postal['source'])<p data-methodology-note><a href="{{ $postal['source']['source_url'] }}">LGD official PIN mapping</a> · source updated {{ substr($postal['source']['updated_at'], 0, 10) }} · retrieved {{ $postal['source']['retrieved_on'] }}. Matched by LGD code.</p>@endif
<p><a href="https://www.indiapost.gov.in/locate-postoffice">Confirm the serving post office with India Post →</a></p>
</section>

@php($relatedHistory = app(\App\Services\PlacePostalCodes::class)->historicalReference($postal['place'] ?? []))
@if($relatedHistory)
<section class="coverage" aria-label="Historical Kengeri record">
<h2>Historical urban record</h2>
<p>Kengeri (LGD 938441) has no Census 2011 crosswalk in the imported LGD directory. Kengeri Gollahalli is a separate place (LGD 613025); its figures are not Kengeri's figures.</p>
<p><a href="{{ $relatedHistory['url'] }}">{{ $relatedHistory['name'] }} →</a></p>
<p>This published archive uses the 1991 urban geography and is classified as a ward in the source. Its relationship to today's LGD boundary is unverified. It is offered for historical reference, not as current population or a continuous statistical series.</p>
</section>
@endif

@include('place-urban-census')
