@php($hi=app()->getLocale()==='hi')
@forelse($rows as $row)
<tr>
<td>{{ $row['year']?:'—' }}</td>
<td>@if($row['type']==='by'){{ $hi?'उपचुनाव':'By-election' }}@if(in_array($row['kind'],['pc','ac'],true)) · {{ strtoupper($row['kind']) }}@endif @else{{ $row['type']==='pc'?($hi?'लोक सभा (पीसी)':'Lok Sabha (PC)'):($hi?'विधान सभा (एसी)':'Assembly (AC)') }}@endif</td>
<td>{{ $row['state']?:'—' }}</td><th scope="row"><a href="{{ $row['url'] }}">{{ $row['name']?:($hi?'मूल रिकॉर्ड':'Source record') }}</a></th>
<td>{{ $row['winner']?:'—' }}</td><td>{{ $row['party']?:'—' }}</td><td>{{ $row['candidates']===null?'—':number_format($row['candidates']) }}</td>
<td>{{ $row['edition']?:'—' }}@if($row['status']==='needs_review') <small>{{ $hi?'समीक्षाधीन':'Under review' }}</small>@endif</td>
</tr>
@empty
<tr><td colspan="8">{{ $hi?'इन फ़िल्टर से कोई रिकॉर्ड नहीं मिला।':'No constituency records match these filters.' }}</td></tr>
@endforelse
