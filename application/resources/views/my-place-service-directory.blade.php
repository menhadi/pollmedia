@php
    $serviceLabels = \App\Services\MyPlaceServices::categories();
    $serviceCategories = array_unique(array_merge([], ...array_column($hub['service_contacts'], 'categories')));
@endphp
<section class="hub-service-directory" aria-labelledby="service-directory-heading">
<h3 id="service-directory-heading">{{ $t('Services & offices for your area', 'आपके क्षेत्र की सेवाएँ और कार्यालय') }}</h3>
<p class="place-note">{{ $t('District contacts serve the wider district. Confirm availability before calling or visiting.', 'जिला संपर्क पूरे जिले के लिए हैं। फोन करने या जाने से पहले उपलब्धता की पुष्टि करें।') }}</p>
@if($hub['service_contacts'])
<div data-service-tools hidden>
<div class="service-filters" role="group" aria-label="{{ $t('Service category', 'सेवा श्रेणी') }}">
<button type="button" data-category="all" aria-pressed="true">{{ $t('All services', 'सभी सेवाएँ') }}</button>
@foreach($serviceLabels as $key => $labels) @if(in_array($key, $serviceCategories, true))
<button type="button" data-category="{{ $key }}" aria-pressed="false">{{ $t(...$labels) }}</button>
@endif @endforeach
</div>
<label class="service-search">{{ $t('Search services', 'सेवाएँ खोजें') }}<input type="search" data-service-search placeholder="{{ $t('Office, service or phone', 'कार्यालय, सेवा या फोन') }}"></label>
<p class="place-note" data-service-count role="status" data-template="{{ $t(':count services shown', ':count सेवाएँ दिखाई गई हैं') }}"></p>
</div>
<div class="service-grid">
@foreach($hub['service_contacts'] as $service)
<article class="service-card" data-service-card data-category="{{ implode(' ', $service['categories']) }}" data-search="{{ implode(' ', $service['name']).' '.implode(' ', $service['address'] ?? []).' '.$service['original_name'].' '.$service['phone'].' '.implode(' ', array_merge(...array_map(fn($key) => $serviceLabels[$key], $service['categories']))) }}">
<span class="service-tag">{{ $t(...$serviceLabels[$service['categories'][0]]) }}</span>
<h4>{{ $service['name'][$language] }}</h4>
@if($language === 'hi' && $service['original_name'])<p class="service-original" lang="en">{{ $service['original_name'] }}</p>@endif
@if($service['address'])<p>{{ $service['address'][$language] }}</p>@endif
@if($service['phone'])<p class="service-phone" dir="ltr">@if($service['phone_href'])<a class="phone-call" href="{{ $service['phone_href'] }}" aria-label="{{ $t('Call','फोन करें').' '.$service['phone'] }}"><span aria-hidden="true">☎</span> {{ $service['phone'] }} <span class="phone-call-label">{{ $t('Tap to call','फोन करें') }}</span></a>@else{{ $service['phone'] }}@endif</p>@endif
@if($service['email'])<p class="service-email"><a href="mailto:{{ $service['email'] }}"><span aria-hidden="true">✉</span> {{ $service['email'] }} <span class="contact-action-hint">{{ $t('Tap to email','ईमेल भेजें') }}</span></a></p>@endif
<div class="service-actions">


@if($service['address'])<a href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($service['name']['en'].' '.$service['address']['en']) }}" target="_blank" rel="noopener noreferrer">{{ $t('Find on map', 'नक्शे पर खोजें') }}</a>@endif
</div>
<details class="service-source"><summary>{{ $t('Source','स्रोत') }}</summary>
<p class="place-note">{{ $service['scope'] === 'village' ? $t('Village contact', 'गाँव का संपर्क') : $t('District service', 'जिले की सेवा') }} · {{ $service['scope'] === 'village' ? $context['village']['name'] : $context['district']['name'] }}</p>
<small>{{ $t('Directory checked:', 'सूची की जाँच:') }} {{ $service['checked_on'] ?? $t('Date unavailable', 'तारीख उपलब्ध नहीं') }}</small>
@if($service['source_url'])<p><a href="{{ $service['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('Official directory','आधिकारिक निर्देशिका') }} ↗</a></p>@endif
</details>
</article>
@endforeach
</div>
<p data-service-empty hidden>{{ $t('No matching services. Try another category or search.', 'कोई सेवा नहीं मिली। दूसरी श्रेणी या खोज आज़माएँ।') }}</p>
@else
<p>{{ $t('A local contact list is not available for this selected area yet.', 'इस चुने क्षेत्र के सेवा संपर्क अभी उपलब्ध नहीं हैं।') }}</p>
@endif
</section>
