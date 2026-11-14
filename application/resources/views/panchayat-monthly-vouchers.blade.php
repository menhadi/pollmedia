@php
    $monthlyReports = collect($reports)->filter(fn ($report) => !empty($report['months']));
    $mt = fn ($en, $hn) => $hi ? $hn : $en;
@endphp
@if($monthlyReports->isNotEmpty())
<h3>{{ $mt('Monthly vouchers', 'मासिक वाउचर') }}</h3>
<div class="gp-table-scroll"><table id="gp-monthly-vouchers" data-gp-paginate><thead><tr>
<th scope="col">{{ $mt('Month', 'महीना') }}</th><th scope="col">{{ $mt('Receipt vouchers', 'प्राप्ति वाउचर') }}</th><th scope="col">{{ $mt('Payment vouchers', 'भुगतान वाउचर') }}</th><th scope="col">{{ $mt('Transfers', 'अंतरण') }}</th><th scope="col">{{ $mt('Journal entries', 'जर्नल प्रविष्टियाँ') }}</th><th scope="col">{{ $mt('Reference', 'संदर्भ') }}</th>
</tr></thead><tbody>
@foreach($monthlyReports as $report)@foreach($report['months'] as $month)
<tr><th scope="row">{{ $month['month'] }}</th>
@foreach(['receipts', 'payments', 'contra', 'journal'] as $kind)<td>{{ $month[$kind] === null ? '—' : number_format($month[$kind]) }}</td>@endforeach
<td><a href="{{ $report['sources']['monthly']['url'] }}" target="_blank" rel="noopener noreferrer">{{ $mt('Official report', 'आधिकारिक रिपोर्ट') }} ↗</a><time datetime="{{ $report['sources']['monthly']['report_date'] }}">{{ $report['sources']['monthly']['report_date'] }}</time></td></tr>
@endforeach @endforeach
</tbody></table></div>
@endif
