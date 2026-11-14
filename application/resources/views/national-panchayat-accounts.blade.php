@php($hi=false)
@php($canonicalUrl=route('panchayat-accounts.index',array_filter(['state'=>$state,'page'=>$page>1?$page:null])))
@php($title='Panchayat accounts')
@extends('gram-panchayat-layout',compact('hi','title'))
@section('directory-content')
<h1>Panchayat accounts</h1>
<form method="get"><label>State / Union Territory<select name="state"><option value="">All states</option>@foreach($coverage['states'] ?? [] as $code=>$entry)<option value="{{ $code }}" @selected((string)$state===(string)$code)>{{ $entry['name'] }}</option>@endforeach</select></label><label>Panchayat or district<input type="search" name="q" value="{{ $q }}" maxlength="120"></label><button>Show accounts</button></form>
@if(!$state)
<div class="national-summary-scroll"><table><thead><tr><th>State / Union Territory</th><th>Records</th><th>Monthly summaries</th><th>Unavailable at source</th><th>Other responses</th><th>Pending</th><th>Retrieved</th></tr></thead><tbody>
@foreach($coverage['states'] ?? [] as $code=>$entry)<tr><th scope="row"><a href="{{ route('panchayat-accounts.index',['state'=>$code]) }}">{{ $entry['name'] }}</a></th><td>{{ $entry['total'] ?: '—' }}</td><td>{{ $entry['counts']['available'] ?? 0 }}</td><td>{{ $entry['counts']['unavailable'] ?? 0 }}</td><td>@if(!empty($entry['note']))<span class="place-note">No records returned by source.</span>@else{{ ($entry['counts']['format_note']??0)+($entry['counts']['fetch_failed']??0) }}@endif</td><td>{{ $entry['counts']['pending'] ?? 0 }}</td><td>{{ $entry['captured_on'] ?? '—' }}</td></tr>@endforeach
</tbody></table></div>
@else
<h2>{{ $coverage['states'][$state]['name'] }}</h2>
<div class="national-summary-scroll"><table><thead><tr><th>Panchayat / local body</th><th>District</th><th>Monthly report</th><th>Retrieved</th></tr></thead><tbody>
@forelse($listing['items'] as $item)<tr><th scope="row"><a href="{{ route('panchayat-accounts.show',['state'=>$state,'gp'=>$item['gp']]) }}">{{ $item['name'] }}</a></th><td>{{ $item['district'] }}</td><td>{{ ['available'=>'Available','unavailable'=>'Unavailable at source','format_note'=>'Published with source note','fetch_failed'=>'Retrieval note','pending'=>'Pending import'][$item['status']] ?? $item['status'] }}</td><td>{{ $item['captured_on'] ?? '—' }}</td></tr>@empty<tr><td colspan="4">No source records available for this selection.</td></tr>@endforelse
</tbody></table></div>
<nav class="gp-pagination">@if($page>1)<a href="{{ route('panchayat-accounts.index',['state'=>$state,'q'=>$q,'page'=>$page-1]) }}">Previous</a>@endif<span>Page {{ $page }}</span>@if($listing['more'])<a href="{{ route('panchayat-accounts.index',['state'=>$state,'q'=>$q,'page'=>$page+1]) }}">Next</a>@endif</nav>
@endif
@endsection
