@php
$detailTables=app(\App\Services\NationalPanchayatDetails::class)->tables((string)$detailsState,(string)$detailsGp,(int)request('voucherPage',1),(int)request('activityPage',1));
$detailAnchor='national-details-'.$detailsGp;
@endphp
@if($detailTables && ($detailTables['vouchers']['total'] || $detailTables['activities']['total'] || $detailTables['notes']))
<section id="{{ $detailAnchor }}" class="national-panchayat-details">
@foreach($detailTables['notes'] as $note)<p class="place-note">{{ $note['note'] }} @if($note['url'])<a href="{{ $note['url'] }}" target="_blank" rel="noopener noreferrer">Official source ↗</a>@endif</p>@endforeach
@if($detailTables['vouchers']['total'])
<h3>Payments &amp; vouchers</h3>
<div class="national-summary-scroll"><table><caption>{{ number_format($detailTables['vouchers']['total']) }} records · 2026–2027</caption><thead><tr><th>Date</th><th>Voucher</th><th>Record type</th><th>Voucher type</th><th>Amount (₹)</th><th>Reference</th></tr></thead><tbody>
@foreach($detailTables['vouchers']['items'] as $row)<tr><td>{{ $row['date'] }}</td><th scope="row">{{ $row['voucher'] }}</th><td>{{ ['payments'=>'Payment','receipts'=>'Receipt','contra'=>'Transfer','journal'=>'Journal'][$row['kind']] }}</td><td>{{ $row['voucher_type'] ?? '—' }}</td><td>{{ number_format($row['amount_paise']/100,2) }}</td><td><a href="{{ $row['detail_url'] ?: $row['source_url'] }}" target="_blank" rel="noopener noreferrer">Official report ↗</a>@if(!empty($row['report_date']))<span class="place-note">Report {{ $row['report_date'] }}</span>@endif<span class="place-note">Retrieved {{ $row['captured_on'] }}</span>@foreach($row['notes'] ?? [] as $note)<span class="place-note">{{ $note }}</span>@endforeach</td></tr>@endforeach
</tbody></table></div>
@include('national-panchayat-pagination',['detailPage'=>$detailTables['vouchers'],'detailParameter'=>'voucherPage','detailAnchor'=>$detailAnchor])
@endif
@if($detailTables['activities']['total'])
<h3>Development activities</h3>
<div class="national-summary-scroll"><table><caption>{{ number_format($detailTables['activities']['total']) }} activities · 2026–2027</caption><thead><tr><th>Activity</th><th>Status</th><th>Focus area</th><th>Reported cost</th><th>Reference</th></tr></thead><tbody>
@foreach($detailTables['activities']['items'] as $row)<tr><th scope="row"><a href="{{ route('panchayat-accounts.activity',['state'=>$detailsState,'gp'=>$detailsGp,'activity'=>$row['record_key']]) }}">{{ $row['name'] }}</a></th><td>{{ $row['status'] ?? 'Unavailable' }}</td><td>{{ $row['focus_area'] ?? '—' }}</td><td>{{ $row['reported_cost'] ?? '—' }}</td><td><a href="{{ $row['source_url'] }}" target="_blank" rel="noopener noreferrer">Official source ↗</a><span class="place-note">Retrieved {{ $row['captured_on'] }}</span>@foreach($row['notes'] ?? [] as $note)<span class="place-note">{{ $note }}</span>@endforeach</td></tr>@endforeach
</tbody></table></div>
@include('national-panchayat-pagination',['detailPage'=>$detailTables['activities'],'detailParameter'=>'activityPage','detailAnchor'=>$detailAnchor])
<details class="gp-methodology"><summary>Methodology & sources</summary><p>Activity costs retain the source value; the source does not state its unit. An activity budget is not a payment. Details queued means the official plan lists the activity and its individual details are still being collected.</p></details>
@endif
</section>
@endif
