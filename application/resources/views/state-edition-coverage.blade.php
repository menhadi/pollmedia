<details class="state-edition-history">
<summary>{{ app()->getLocale()==='hi'?'ऐतिहासिक कवरेज और मूल संस्करण':'Historical coverage and original editions' }}</summary>
<div id="{{ $kind }}-coverage" class="table-scroll" role="region" tabindex="0" aria-label="Election coverage by report">
<table data-sortable><thead><tr>
<th scope="col">Year</th><th scope="col">Report</th><th scope="col">Turnout (%)</th><th scope="col">Constituencies</th><th scope="col">With turnout</th><th scope="col">With margins</th><th scope="col">With party votes</th><th scope="col">Candidate rows</th>
</tr></thead><tbody>@foreach($history as $row)
<tr><th scope="row">{{ $row['year'] }}</th><td><a href="{{ route($kind==='pc'?'elections.history':'elections.assembly',['edition'=>$row['id'],'state'=>$row['state']]) }}">{{ $row['label'] }}</a></td>
<td data-sort-value="{{ $row['turnout'] }}">{{ $row['turnout']===null?'—':number_format($row['turnout'],2) }}@if($row['turnout_review_count']??0) †@endif</td>
@foreach(['tables','turnout_count','margin_count','party_count','candidate_rows'] as $field)
<td data-sort-value="{{ $row[$field]??'' }}">{{ isset($row[$field])?number_format($row[$field]):'—' }}</td>
@endforeach</tr>
@endforeach</tbody></table></div></details>
