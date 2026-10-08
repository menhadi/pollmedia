@if(! empty($record['source_review']))
<section class="notice" aria-label="Reviewed source values">
<h3>Reviewed source values †</h3>
<p>These values were checked against the numeric values saved in the official workbook. The original extraction and its warnings are retained below.</p>
<table><caption>Reviewed candidate votes</caption><thead><tr><th>Candidate as reported</th><th>Votes</th><th>Workbook cell</th></tr></thead><tbody>
@foreach($record['source_review']['candidates'] as $candidate)
<tr><td>{{ $candidate['name'] }}</td><td>{{ $candidate['votes'] === null ? 'Not available †' : number_format($candidate['votes']) }}</td><td>{{ $candidate['cell'] }}</td></tr>
@endforeach
</tbody></table>
<p>Registered electors: {{ number_format($record['source_review']['totals']['electors']) }} · Votes polled: {{ number_format($record['source_review']['totals']['votes_polled']) }} · Valid candidate votes: {{ number_format($record['source_review']['totals']['valid_candidate_votes']) }}</p>
<ul>@foreach($record['source_review']['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>
<p><a href="{{ $record['source_url'] }}">Official workbook source</a> · Worksheet: {{ $record['source_review']['sheet'] }}</p>
</section>
@endif
