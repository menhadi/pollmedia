@php($directoryFeed = $communityFeed ?? app(\App\Services\CommunityAreaIssues::class)->feed($issueArea))
@php($directoryHolders = app(\App\Services\IssueAuthorities::class)->holders($directoryFeed['authorities']->pluck('id')->all()))
<section class="authority-directory" id="district-authority-contacts" aria-labelledby="authority-directory-title">
<header class="authority-directory-heading"><div><p class="authority-eyebrow">YOUR DISTRICT · PUBLIC OFFICES</p><h2 id="authority-directory-title">Authorities in {{ $issueArea['name'] }}</h2><p>Find the right office. Explore departments, contact details and official sources.</p></div><span class="authority-count">{{ $directoryFeed['authorities']->count() + $directoryFeed['contacts']->count() }} <small>listed offices</small></span></header>
<div class="authority-card-grid">
@foreach($directoryFeed['authorities'] as $office)
<article class="authority-card"><span class="authority-category">{{ ucfirst($office->kind) }}</span><h3>{{ $office->title }}</h3>@include('authority-office-people', ['officePeople' => $directoryHolders->get($office->id, collect())])<p class="authority-description">Public office serving this district.</p><a class="authority-action" href="{{ $office->url }}" target="_blank" rel="noopener">View official directory <span aria-hidden="true">↗</span></a><small class="authority-footnote">Office jurisdiction verified from the listed source. Current officeholder details may have changed.</small></article>
@endforeach
@foreach($directoryFeed['contacts'] as $contact)
<article class="authority-card"><span class="authority-category">{{ $contact['general_routing'] ?? false ? 'General enquiries' : ucfirst($contact['categories'][0] ?? 'Department') }}</span><h3>{{ $contact['office'] }}</h3>@include('authority-contact-person', ['personContact' => $contact])<p class="authority-description">{{ $contact['department'] ?? $contact['role'] ?? '' }}</p><div class="authority-actions">
@if(!empty($contact['phone']))@if(!empty($contact['phone_href']))<a class="authority-action" href="{{ $contact['phone_href'] }}">Call {{ $contact['phone'] }}</a>@else<span class="authority-action">{{ $contact['phone'] }}</span>@endif @endif
@if(!empty($contact['email']))<a class="authority-email" href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>@endif
</div><details class="authority-source"><summary>Official source &amp; freshness</summary><p>{{ ($contact['freshness'] ?? '') === 'outdated' ? 'Older listing — confirm before use.' : 'Contact availability and responsibility unconfirmed.' }}</p><p>{{ $contact['note'] ?? '' }}</p><a href="{{ $contact['source_url'] }}" target="_blank" rel="noopener">View official source ↗</a></details></article>
@endforeach
</div>
@if($directoryFeed['authorities']->isEmpty() && $directoryFeed['contacts']->isEmpty())<p class="authority-empty">No verified directory contact is available for this district yet.</p>@endif
<p class="authority-directory-note">These offices serve this district. Open a report for contacts matched to its problem and location.</p>
</section>
