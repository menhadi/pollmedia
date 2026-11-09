@php
    $hi = app()->getLocale()==='hi';
    $tr = fn ($en,$hn) => $hi ? $hn : $en;
    $lang = $hi ? ['lang'=>'hi'] : [];
    $labels = ['pc'=>$tr('Lok Sabha (PC)','लोक सभा (पीसी)'), 'ac'=>$tr('Assembly (AC)','विधान सभा (एसी)'), 'by'=>$tr('By-election','उपचुनाव')];
@endphp
<section id="national-records" class="hub-records" data-first="{{ $first }}" aria-labelledby="results-heading">
<div class="records-heading"><h2 id="results-heading">{{ $tr('Constituency records','निर्वाचन क्षेत्र रिकॉर्ड') }}</h2><span data-count>{{ number_format($total) }} {{ $tr($total===1?'constituency record':'constituency records','निर्वाचन क्षेत्र रिकॉर्ड') }}</span></div>
<form class="records-filters" method="get" action="{{ route('elections.all-india') }}#national-records" data-record-filters>
@if($hi)<input type="hidden" name="lang" value="hi">@endif
<label>{{ $tr('Year','वर्ष') }}<select name="year"><option value="">{{ $tr('All years','सभी वर्ष') }}</option>@foreach($years as $y)<option value="{{ $y }}" @selected(isset($filters['year']) && (int)$filters['year']===$y)>{{ $y ?: $tr('Year unreported','वर्ष अनुपलब्ध') }}</option>@endforeach</select></label>
<label>{{ $tr('Election type','चुनाव प्रकार') }}<select name="type"><option value="">{{ $tr('All elections','सभी चुनाव') }}</option>@foreach($labels as $key=>$label)@if($types->contains($key))<option value="{{ $key }}" @selected(($filters['type']??null)===$key)>{{ $label }}</option>@endif
@endforeach</select></label>
<label>{{ $tr('State / UT','राज्य / यूटी') }}<select name="state"><option value="">{{ $tr('All areas','सभी क्षेत्र') }}</option>@foreach($states as $area)<option @selected(($filters['state']??null)===$area)>{{ $area }}</option>@endforeach</select></label>
@if(isset($filters['year']) || isset($filters['state']) || isset($filters['seat']))
<label>{{ $tr('Constituency','निर्वाचन क्षेत्र') }}<select name="seat"><option value="">{{ $tr('All constituencies','सभी निर्वाचन क्षेत्र') }}</option>@foreach($seats as $name)<option @selected(($filters['seat']??null)===$name)>{{ $name }}</option>@endforeach</select></label>
@endif
@if((!isset($filters['year']) && !isset($filters['state']) && !isset($filters['seat'])) || filled($filters['q']??null))
<label>{{ $tr('Constituency search','निर्वाचन क्षेत्र खोज') }}<input type="search" name="q" maxlength="120" value="{{ $filters['q']??'' }}" placeholder="{{ $tr('Seat name','क्षेत्र का नाम') }}"></label>
@endif
<button type="submit">{{ $tr('Show results','परिणाम देखें') }}</button>
</form>
<div class="national-scroll" role="region" aria-labelledby="results-heading" tabindex="0"><table class="records-table">
<thead><tr>@foreach([['Constituency','निर्वाचन क्षेत्र'],['State / UT','राज्य / यूटी'],['Year','वर्ष'],['Election','चुनाव'],['Winning candidate','विजयी उम्मीदवार'],['Party','दल'],['Candidates','उम्मीदवार']] as [$en,$hn])<th scope="col">{{ $tr($en,$hn) }}</th>@endforeach</tr></thead>
<tbody>@include('all-india-election-rows')</tbody></table></div>
<nav class="national-pager" aria-label="{{ $tr('Election records','चुनाव रिकॉर्ड') }}">
@if($previousUrl)<a href="{{ $previousUrl }}#national-records">{{ $tr('Previous records','पिछले रिकॉर्ड') }}</a>@endif
<span role="status" data-national-status>{{ $rows->isEmpty()?'0':number_format($first).'–'.number_format($last) }} / {{ number_format($total) }}</span>
@if($nextUrl)<a data-national-more href="{{ $nextUrl }}#national-records">{{ $tr('Load more','और दिखाएँ') }}</a>@endif
</nav>
@if(isset($filters['seat']))
@foreach($rows as $row)
@if(!empty($row['candidate_rows']))
<details class="candidate-breakdown" @if($rows->count()===1) open @endif><summary>{{ $row['name'] }} · {{ $row['year'] ?: '—' }} · {{ $tr('Candidates','उम्मीदवार') }}</summary>
<div class="national-scroll" tabindex="0"><table><thead><tr><th>{{ $tr('Candidate','उम्मीदवार') }}</th><th>{{ $tr('Party','दल') }}</th><th>{{ $tr('Recorded votes','दर्ज मत') }}</th></tr></thead><tbody>
@foreach($row['candidate_rows'] as $candidate)<tr><th scope="row">{{ $candidate['name'] ?? '—' }}</th><td>{{ $candidate['party'] ?? '—' }}</td><td>{{ is_numeric($candidate['votes']??null)?number_format($candidate['votes']):'—' }}</td></tr>@endforeach
</tbody></table></div></details>
@endif
@endforeach
@endif
</section>
