@extends('seo-layout')
@section('content')
<h1>Authority contact coverage</h1>
<p>Published contact coverage by district and department. Revenue, agriculture, welfare, transport and environment are tracked alongside the existing service departments. Updates appear here when the continuing collection job publishes a batch. This page does not start jobs or send complaints.</p>
<p><strong>{{ count($rows) }} districts in the current LGD inventory · {{ number_format($contactCount) }} district contact listings</strong><br>Published files updated {{ $updated }}. <a href="{{ request()->fullUrl() }}">Refresh coverage</a></p>
<p class="notice">Available means a department contact is listed, not that its responsibility or current availability is confirmed. Routing only means a district administration contact exists but a department contact is still missing. Older and undated details remain flagged. Village-only contacts are not counted as district-wide coverage.</p>
<section class="card"><h2>Department coverage — all districts</h2><div style="overflow-x:auto"><table><thead><tr><th>Department</th><th>Contact available</th><th>Routing only</th><th>No contact</th></tr></thead><tbody>
@foreach($totals as $type => $counts)
<tr><th>{{ ucfirst($type) }}</th>
@foreach($counts as $status => $count)
<td><a href="{{ route('issues.authority-coverage',['department'=>$type,'coverage'=>$status]) }}">{{ $count }}</a></td>
@endforeach
</tr>
@endforeach
</tbody></table></div></section>
<form class="filters" method="get"><label>State / UT<select name="state"><option value="">All states / UTs</option>
@foreach($states as $state)<option @selected(($filters['state'] ?? '') === $state)>{{ $state }}</option>@endforeach
</select></label><label>District or LGD code<input name="q" value="{{ $filters['q'] ?? '' }}"></label><label>Department<select name="department"><option value="">All types</option>
@foreach($types as $type)<option value="{{ $type }}" @selected(($filters['department'] ?? '') === $type)>{{ ucfirst($type) }}</option>@endforeach
</select></label><label>Coverage<select name="coverage"><option value="">All coverage</option>
@foreach(['available'=>'Contact available','routing_only'=>'Routing only','missing'=>'No contact','incomplete'=>'Any department missing'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['coverage'] ?? '') === $value)>{{ $label }}</option>@endforeach
</select></label><button>Filter</button><a href="{{ route('issues.authority-coverage') }}">Reset</a></form>
<section class="card"><h2>District coverage ({{ $districts->total() }} matching)</h2><div style="overflow-x:auto"><table><thead><tr><th>District</th>
@foreach($types as $type)<th>{{ ucfirst($type) }}</th>@endforeach
<th>Source freshness</th></tr></thead><tbody>
@foreach($districts as $district)
<tr><th>{{ $district['name'] }}<br><small>{{ $district['state'] }} · {{ $district['key'] }}</small></th>
@foreach($district['types'] as $type)
<td>{{ ['available'=>'Available','routing_only'=>'Routing only','missing'=>'Missing'][$type['status']] }} @if($type['count']) ({{ $type['count'] }}) @endif</td>
@endforeach
<td>{{ $district['older'] }} older · {{ $district['undated'] }} undated</td></tr>
<tr><td colspan="{{ count($types) + 2 }}"><details><summary>Offices and official sources ({{ count($district['contacts']) }})</summary>
@forelse($district['contacts'] as $contact)
<p><strong>{{ $contact['office'] }}</strong> · {{ implode(', ', $contact['categories']) }}<br>{{ $contact['phone'] ?? '' }} {{ $contact['email'] ?? '' }}<br>
@if($contact['older'])<strong>Older listing — may be outdated.</strong>
@elseif(empty($contact['source_updated_on']))<strong>Undated — contact unconfirmed.</strong>
@else Contact availability unconfirmed.
@endif
{{ $contact['note'] }} <a href="{{ $contact['source_url'] }}" target="_blank" rel="noopener noreferrer">Official source</a> · Checked {{ $contact['checked_on'] }}</p>
@empty
<p>No district-wide contact published yet.</p>
@endforelse
</details></td></tr>
@endforeach
</tbody></table></div>{{ $districts->links() }}</section>
<details class="card"><summary>Unresolved official district names ({{ count($unresolved) }})</summary><p>These source identities need supporting evidence before their contacts can be assigned. Some may already have other usable contacts.</p>
@foreach($unresolved as [$state,$district])<p>{{ $state }} — {{ $district }}</p>@endforeach
</details>
<p>Refreshes every 60 seconds while this page is idle. A collection run is not counted as completed until its contacts are published.</p>
<script>setInterval(function(){if(!document.hidden && document.activeElement===document.body){window.location.reload();}},60000);</script>
@endsection
