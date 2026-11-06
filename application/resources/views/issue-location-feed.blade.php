<section class="card" id="issue-selected-feed" aria-live="polite"><h2>{{ $areaName ? 'Issues in '.$areaName : 'Community issues' }}</h2>
<p>Review existing reports here before posting. If your problem is already listed, open it to support it or add evidence.</p>
@php(app(\App\Services\IssuePostCards::class)->prepare($issues->items()))

@forelse($issues as $issue)@include('issue-post-card', ['post'=>$issue])@empty<p>{{ $area ? 'No reports at this location yet.' : 'Choose a problem location to see reports from that place.' }}</p>@endforelse{{ $issues->links() }}</section>
