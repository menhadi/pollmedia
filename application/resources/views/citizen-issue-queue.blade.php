@extends('seo-layout')
@section('content')
<h1>Citizen issue moderation</h1><p>Check accuracy, relevance, private information and evidence links before publishing. Publishing approves visibility; it does not certify the claims.</p>
@php($pendingContributions = DB::table('citizen_issue_contributions as c')->join('citizen_issues as i','i.id','=','c.issue_id')->where('c.status','pending')->select('i.id','i.title')->distinct()->limit(50)->get())
<section class="card"><h2>Issues with contributions awaiting review</h2>@forelse($pendingContributions as $pending)<p><a href="{{ route('issues.review',$pending->id) }}">{{ $pending->title }}</a></p>@empty<p>No contributions awaiting review.</p>@endforelse</section>
<form class="filters"><label for="status">Status</label><select id="status" name="status">@foreach(['pending','open','in_progress','resolved','rejected'] as $option)<option value="{{ $option }}" @selected($status === $option)>{{ \Illuminate\Support\Str::headline($option) }}</option>@endforeach</select><button>Filter</button></form>
<section class="card">@forelse($issues as $issue)<div class="row"><a href="{{ route('issues.review', $issue->id) }}">{{ $issue->title }}</a><p>{{ $issue->created_at }} UTC · {{ $issue->category }}</p></div>@empty<p>No reports in this queue.</p>@endforelse{{ $issues->links() }}</section>
@endsection
