@if(!empty($hub['panchayat_reports']))
<section class="hub-panchayat" aria-labelledby="panchayat-title" translate="no">
    <h3 id="panchayat-title">{{ $t('Money received and spent', 'प्राप्त और खर्च की गई राशि') }}</h3>
    <p class="place-note">{{ $t('Source-reported · not independently verified','स्रोत के अनुसार · स्वतंत्र रूप से सत्यापित नहीं') }}</p>
    <p class="place-note">{{ $context['village'] ? $t('Reports for the Panchayat linked to this village. Figures cover the whole Panchayat.', 'इस गाँव से जुड़ी पंचायत की रिपोर्ट। आँकड़े पूरी पंचायत के हैं।') : $t('Available Panchayat reports within this district.', 'इस जिले की उपलब्ध पंचायत रिपोर्ट।') }}</p>
    @foreach($hub['panchayat_reports'] as $report)
        @php
            $countValue=fn($value)=>$value===null?$t('Unavailable','उपलब्ध नहीं'):number_format($value);
            $extraCounts=collect($report['months'])->contains(fn($month)=>$month['contra']!==null||$month['journal']!==null);
            $money=fn($value)=>$value===null?$t('Unavailable','उपलब्ध नहीं'):'₹'.number_format($value/100,2);
            $summary=$report['financial_summary'];
            $amountLabels=['receipts'=>$t('Recorded receipts','दर्ज प्राप्तियाँ'),'payments'=>$t('Recorded payments','दर्ज भुगतान'),'receipts_reversals'=>$t('Receipt reversals','प्राप्ति की उलटी प्रविष्टियाँ'),'payments_reversals'=>$t('Payment reversals','भुगतान की उलटी प्रविष्टियाँ'),'receipts_adjustments'=>$t('Receipt-side balance adjustments','प्राप्ति पक्ष के शेष समायोजन'),'payments_adjustments'=>$t('Payment-side balance adjustments','भुगतान पक्ष के शेष समायोजन'),'contra'=>$t('Transfers','अंतरण'),'journal'=>$t('Journal entries','जर्नल प्रविष्टियाँ')];
            $amountLabels['receipts_cancellations']=$t('Cancelled entries · receipts','रद्द प्रविष्टियाँ · प्राप्तियाँ');
            $amountLabels['payments_cancellations']=$t('Cancelled entries · payments','रद्द प्रविष्टियाँ · भुगतान');
            $chartMax=max(array_merge([1],array_column($summary['months'],'receipts'),array_column($summary['months'],'payments')));
        @endphp
        <article class="panchayat-report">
            <header><h4>{{ $report['name'] }}</h4>
                @if($report['native_name'] && $report['native_name']!==$report['name'])<p>{{ $report['native_name'] }}</p>@endif
                <p class="place-note">{{ $report['block_name'] }} · {{ $t('Financial year','वित्त वर्ष') }} {{ $report['financial_year'] }}</p>
            </header>
            @if($summary['totals'])
            <div class="gp-money-grid">
                @foreach($amountLabels as $kind=>$label)
                @if(isset($summary['totals'][$kind]))
                @php($total=$summary['totals'][$kind])
                <article class="gp-money-card gp-money-{{ $kind }}"><h5>{{ $label }}</h5><strong>{{ $money($total['amount_paise']) }}</strong>
                    <span>{{ $total['count'] }} {{ $t('recorded vouchers','दर्ज वाउचर') }}</span><small>{{ $total['from'] }} → {{ $total['to'] }}</small>
                </article>
                @endif
                @endforeach
            </div>
            <p class="place-note">{{ $t('Totals from available vouchers · not the annual budget or bank balance.','उपलब्ध वाउचर का योग · वार्षिक बजट या बैंक शेष नहीं।') }}</p>
            @if(!empty($report['receipts_reversals']) || !empty($report['payments_reversals']) || !empty($report['receipts_adjustments']) || !empty($report['payments_adjustments']) || !empty($report['receipts_cancellations']) || !empty($report['payments_cancellations']))
            <p class="place-note">{{ $t('Reversals, cancellations and balance adjustments are shown separately and excluded from receipt/payment totals and the monthly chart. No net balance is inferred.','उलटी प्रविष्टियाँ, रद्द प्रविष्टियाँ और शेष समायोजन अलग दिखाए गए हैं और प्राप्ति/भुगतान के योग तथा मासिक चार्ट में शामिल नहीं हैं। शुद्ध शेष का अनुमान नहीं लगाया गया है।') }}</p>
            @endif
            @endif
            @if($summary['months'])
            <figure class="gp-money-chart"><figcaption>{{ $t('Monthly money flow · ₹','मासिक प्राप्तियाँ और भुगतान · ₹') }}</figcaption>
                <p class="gp-chart-key"><span class="gp-key-receipts">{{ $amountLabels['receipts'] }}</span><span class="gp-key-payments">{{ $amountLabels['payments'] }}</span></p>
                @foreach($summary['months'] as $month)
                <div class="gp-chart-month"><h5>{{ $month['month'] }}</h5>
                    @foreach(['receipts','payments'] as $kind)
                    @php($amount=$month[$kind])
                    <div class="gp-chart-row gp-chart-{{ $kind }}" tabindex="0" title="{{ $month['month'] }} · {{ $amountLabels[$kind] }}: {{ $money($amount) }}" aria-label="{{ $month['month'] }} · {{ $amountLabels[$kind] }}: {{ $money($amount) }}">
                        <span class="gp-chart-track" aria-hidden="true">@if($amount!==null)<span style="width:{{ round($amount/$chartMax*100,2) }}%"></span>@endif</span>
                        <strong>{{ $amount===null?'—':$money($amount) }}</strong>
                        <span class="gp-chart-tooltip" aria-hidden="true">{{ $amountLabels[$kind] }}: {{ $money($amount) }}</span>
                    </div>
                    @endforeach
                </div>
                @endforeach
            </figure>
            @endif
            @if($report['months'])
            <div class="panchayat-table" tabindex="0" role="region" aria-label="{{ $t('Monthly voucher counts','मासिक वाउचर संख्या') }}">
                <table @if($extraCounts) style="min-width:480px" @endif><caption>{{ $t('Monthly activity · voucher counts','मासिक गतिविधि · वाउचर संख्या') }} · {{ $report['sources']['monthly']['report_date'] }}</caption>
                    <thead><tr><th scope="col">{{ $t('Month','महीना') }}</th><th scope="col">{{ $t('Receipt vouchers','प्राप्ति वाउचर') }}</th><th scope="col">{{ $t('Payment vouchers','भुगतान वाउचर') }}</th>@if($extraCounts)<th scope="col">{{ $t('Contra vouchers','कॉन्ट्रा वाउचर') }}</th><th scope="col">{{ $t('Journal vouchers','जर्नल वाउचर') }}</th>@endif</tr></thead>
                    <tbody>@foreach($report['months'] as $month)<tr><th scope="row">{{ $month['month'] }}</th><td>{{ $countValue($month['receipts']) }}</td><td>{{ $countValue($month['payments']) }}</td>@if($extraCounts)<td>{{ $countValue($month['contra']) }}</td><td>{{ $countValue($month['journal']) }}</td>@endif</tr>@endforeach</tbody>
                </table>
            </div>
            <p class="place-note">{{ $t('These are counts of recorded vouchers, not rupee amounts.','ये दर्ज वाउचर की संख्या हैं, रुपये की राशि नहीं।') }}</p>
            @endif
            @foreach(['payments'=>$t('Reported payments','रिपोर्ट किए गए भुगतान'),'receipts'=>$t('Reported receipts','रिपोर्ट की गई प्राप्तियाँ'),'receipts_reversals'=>$amountLabels['receipts_reversals'],'payments_reversals'=>$amountLabels['payments_reversals'],'receipts_adjustments'=>$amountLabels['receipts_adjustments'],'payments_adjustments'=>$amountLabels['payments_adjustments'],'receipts_cancellations'=>$amountLabels['receipts_cancellations'],'payments_cancellations'=>$amountLabels['payments_cancellations'],'contra'=>$t('Contra transfers','कॉन्ट्रा अंतरण'),'journal'=>$t('Journal entries','जर्नल प्रविष्टियाँ')] as $kind=>$label)
            @if($report[$kind])
            <div class="panchayat-table" tabindex="0" role="region" aria-label="{{ $label }}">
                <table><caption>{{ $label }} · ₹ · {{ $t('Report dated','रिपोर्ट की तारीख') }} {{ collect($report[$kind])->max('report_date') }}</caption>
                    <thead><tr><th scope="col">{{ $t('Date','तारीख') }}</th><th scope="col">{{ $t('Voucher','वाउचर') }}</th><th scope="col">{{ $t('Amount','राशि') }}</th></tr></thead>
                    <tbody>@foreach($report[$kind] as $entry)<tr><td>{{ $entry['date'] }}</td><td>{{ $entry['voucher'] }}@if(!empty($entry['voucher_type']))<br><small>{{ $t('Type','प्रकार') }}: {{ $entry['voucher_type'] }}</small>@endif</td><td>{{ $entry['amount_paise']===null?$t('Unavailable','उपलब्ध नहीं'):'₹'.number_format($entry['amount_paise']/100,2) }}</td></tr>@endforeach</tbody>
                </table>
            </div>
            @endif
            @endforeach
        </article>
    @endforeach
</section>
@endif
