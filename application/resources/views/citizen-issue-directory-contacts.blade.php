@php
$routing = app(\App\Services\IssueAuthorities::class)->routing($record->id, $record->category);
$routingHolders = app(\App\Services\IssueAuthorities::class)->holders($routing['offices']->pluck('id')->all());
@endphp
<p>{{ $routing['department'] }}</p><details class="post-disclaimer"><summary>Contact information & disclaimer</summary><p><strong>Responsible department not confirmed.</strong> These are automatic routing suggestions based on the problem location and type. A listed office has not accepted responsibility. Displaying a contact does not send the report.</p><p>The exact department depends on the owner of the road, asset or facility.</p></details>
@forelse($routing['contacts'] as $contact)
<article class="issue-office"><h3>{{ $contact['office'] }}</h3>@include('authority-contact-person', ['personContact' => $contact])<p><strong>Department</strong><br>{{ $contact['department'] }}</p><p><strong>{{ $contact['role'] }}</strong> · Suggested routing contact</p><p>{{ $contact['contact_label'] }}<br>
@if(!empty($contact['phone']))
@if($contact['phone_href'])<a href="{{ $contact['phone_href'] }}">{{ $contact['phone'] }}</a>
@else{{ $contact['phone'] }}
@endif
@endif

@if(!empty($contact['email']))<br><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>
@endif</p><p><a href="{{ $contact['website'] }}" target="_blank" rel="noopener noreferrer">Official website / directory ↗</a></p><details class="post-disclaimer"><summary>Source & freshness</summary><p>
@if(($contact['freshness'] ?? '') === 'outdated')Older official listing — may be outdated.
@elseif(($contact['freshness'] ?? '') === 'undated')Update date unavailable — contact unconfirmed.
@else
Contact availability unconfirmed.
@endif</p><p class="issue-muted">{{ $contact['note'] }}</p><small>Official listing checked {{ $contact['checked_on'] }} · <a href="{{ $contact['source_url'] }}" target="_blank" rel="noopener noreferrer">Source ↗</a></small></details></article>

@empty
<p class="issue-empty">No verified official phone or email is available in Pollmedia for this location and problem type yet.</p>

@endforelse
@foreach($routing['offices']->reject(fn ($office) => $authorities->contains('office_id', $office->id)) as $office)
<article class="issue-office"><h3>{{ $office->title }}</h3>@include('authority-office-people', ['officePeople' => $routingHolders->get($office->id, collect())])<p class="issue-muted">Office with recorded jurisdiction here — a local routing or representation contact, not a confirmed service department.</p><a href="{{ $office->url }}" target="_blank" rel="noopener noreferrer">Official jurisdiction source ↗</a><p class="issue-muted">No verified direct contact recorded.</p></article>

@endforeach
