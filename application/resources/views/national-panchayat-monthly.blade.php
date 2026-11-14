@php($summary=$nationalSummary['summary'] ?? null)
@if($summary)
<section class="panchayat-report national-monthly-summary" data-national-monthly-summary>
<h3>Monthly accounts · {{ $summary['financial_year'] }}</h3>
@if($summary['name']!==($nationalSummary['native_name']??$summary['name']))<p class="place-note">Source name: {{ $summary['name'] }}</p>@endif
@if($summary['source_district'])<p class="place-note">{{ $summary['source_district'] }}</p>@endif
@foreach($summary['notes'] as $note)<p class="place-note">{{ $note }}</p>@endforeach
@if($summary['monthly'])
<div class="panchayat-table national-summary-scroll"><table><caption>Monthly voucher counts</caption><thead><tr><th>Month</th><th>Receipt vouchers</th><th>Payment vouchers</th><th>Transfer vouchers</th><th>Journal vouchers</th><th>Source note</th></tr></thead><tbody>
@foreach($summary['monthly'] as $month)<tr data-national-month><th scope="row">{{ $month['month'] }}</th>@foreach(['receipts','payments','contra','journal'] as $kind)<td>{{ $month[$kind] ?? 'Unavailable' }}</td>@endforeach<td>{{ $month['note'] ?? '' }}</td></tr>@endforeach
</tbody></table></div>
@endif
<p class="place-note"><a href="{{ $summary['source_url'] }}" target="_blank" rel="noopener noreferrer">Official eGramSwaraj report ↗</a>@if($summary['report_date']) · Report: {{ $summary['report_date'] }}@endif · Retrieved: {{ $summary['captured_on'] }}</p>
</section>
@endif
