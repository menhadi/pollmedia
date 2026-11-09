@php
    $hi = app()->getLocale() === 'hi';
@endphp
@forelse($rows as $row)
<tr>
<th scope="row">@if(filled($row['name']))
@php
    [$destination, $fragment] = array_pad(explode('#', $row['url'], 2), 2, null);
    if ($hi) $destination .= (str_contains($destination, '?') ? '&' : '?').'lang=hi';
@endphp
<a href="{{ $destination.($fragment!==null?'#'.$fragment:'') }}">{{ $row['name'] }}</a>
@else — @endif</th>
<td>{{ $row['state']?:'—' }}</td><td>{{ $row['year']?:'—' }}</td>
<td><span class="record-type type-{{ $row['type'] }}">@if($row['type']==='by'){{ $hi?'उपचुनाव':'By-election' }}@if(in_array($row['kind'],['pc','ac'],true)) · {{ strtoupper($row['kind']) }}@endif @else{{ $row['type']==='pc'?($hi?'लोक सभा (पीसी)':'Lok Sabha (PC)'):($hi?'विधान सभा (एसी)':'Assembly (AC)') }}@endif</span></td>
<td>{{ $row['winner']?:'—' }}</td><td>{{ $row['party']?:'—' }}</td><td>{{ $row['candidates']===null?'—':number_format($row['candidates']) }}</td></tr>
@empty<tr><td colspan="7">{{ $hi?'इन फ़िल्टर से कोई रिकॉर्ड नहीं मिला।':'No constituency records match these filters.' }}</td></tr>
@endforelse
