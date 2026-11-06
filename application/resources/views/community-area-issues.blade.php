@php
$communityFeed = app(\App\Services\CommunityAreaIssues::class)->feed($issueArea);
app(\App\Services\IssuePostCards::class)->prepare($communityFeed['issues'] instanceof \Illuminate\Contracts\Pagination\Paginator ? $communityFeed['issues']->items() : $communityFeed['issues']);
$communityHolders = app(\App\Services\IssueAuthorities::class)->holders($communityFeed['authorities']->pluck('id')->all());
@endphp
<link rel="stylesheet" href="{{ asset('css/community-area-issues.css') }}?v={{ substr(hash_file('sha256', public_path('css/community-area-issues.css')),0,12) }}">
<link rel="stylesheet" href="{{ asset('css/issue-post.css') }}?v={{ substr(hash_file('sha256', public_path('css/issue-post.css')),0,12) }}">
<script src="{{ asset('js/issue-post.js') }}" defer></script>
<section class="community-area" data-community-area id="community-issues" aria-label="Place views">
<nav class="community-tabs" role="tablist" aria-label="Place views">
<button type="button" role="tab" aria-selected="true" data-community-tab="overview">Overview & data</button>
<button type="button" role="tab" aria-selected="false" aria-controls="community-area-panel" data-community-tab="issues">Community issues <span>{{ $communityFeed['total'] }}</span></button>
</nav>
<div id="community-area-panel" data-community-panel hidden>
<div class="community-heading"><div><h2>Community issues · {{ $issueArea['name'] }}</h2><p>One report, shared across its linked places. Support and updates stay together.</p></div><a class="community-report" href="{{ route('issues.index') }}">Report a problem</a></div>
<div class="community-columns"><div>
@if(!$issueArea['available'])<p role="status">A unique current area link has not been established for this page. Only reports explicitly linked to this place are shown.</p>@endif
@forelse($communityFeed['issues'] as $communityIssue)
@include('issue-post-card', ['post' => $communityIssue])
@empty<p class="community-empty">No published community issues are linked to this area yet.</p>@endforelse
@if($communityFeed['issues'] instanceof \Illuminate\Contracts\Pagination\Paginator){{ $communityFeed['issues']->links() }}@endif
</div><aside class="community-authorities" aria-label="Concerned authorities"><h2>Concerned authorities</h2><p>Offices for {{ $issueArea['name'] }} · {{ ucfirst(str_replace('-', ' ', $issueArea['kind'])) }} level. Individual reports show their specific concerned authorities.</p>
@forelse($communityFeed['authorities'] as $communityOffice)<div><h3><a href="{{ $communityOffice->url }}">{{ $communityOffice->title }}</a></h3>@include('authority-office-people', ['officePeople' => $communityHolders->get($communityOffice->id, collect())])<p>{{ ucfirst($communityOffice->kind) }} · Official jurisdiction for this area</p></div>@empty @endforelse
@foreach($communityFeed['contacts'] as $contact)
<div class="community-contact"><h3>{{ $contact['office'] }}</h3>@include('authority-contact-person', ['personContact' => $contact])<p>{{ $contact['department'] ?? $contact['role'] ?? '' }}</p>
@if(!empty($contact['phone']))<p>@if(!empty($contact['phone_href']))<a href="{{ $contact['phone_href'] }}">{{ $contact['phone'] }}</a>@else{{ $contact['phone'] }}@endif</p>@endif
@if(!empty($contact['email']))<p><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></p>@endif
<details class="post-disclaimer"><summary>Source & freshness</summary><p>{{ ($contact['freshness'] ?? '') === 'outdated' ? 'Older listing — may be outdated.' : 'Contact and responsibility unconfirmed.' }}</p><p>{{ $contact['note'] ?? '' }}</p>
<a href="{{ $contact['source_url'] }}" target="_blank" rel="noopener">Official source ↗</a></details></div>
@endforeach
@if($communityFeed['authorities']->isEmpty() && $communityFeed['contacts']->isEmpty())<p>No verified directory contact is available for this page’s jurisdiction yet. Open an individual report for its location-specific contacts.</p>@endif
<details class="post-disclaimer"><summary>About these contacts</summary><p>Responsibility depends on the problem category and jurisdiction. Open a report to see its assigned office, evidence and official response.</p><p>Appearing in an AC or PC feed does not assign responsibility to the MLA or MP.</p><a href="https://pgportal.gov.in/" target="_blank" rel="noopener">Official CPGRAMS portal ↗</a><p>Government submission is separate from publication on Pollmedia. Official tracking appears on the report when linked.</p></details></aside></div>
</div></section>
@if(($issueArea['kind'] ?? '') === 'district' && !($deferDistrictAuthorities ?? false))
@include('district-authority-directory')
@endif

<script src="{{ asset('js/community-area-issues.js') }}?v={{ substr(hash_file('sha256', public_path('js/community-area-issues.js')),0,12) }}" defer></script>
