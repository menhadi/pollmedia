@if(!empty($panchayat['allocations']))
<section class="hub-panchayat gp-state-allocations" aria-labelledby="gp-allocation-title" translate="no">
    <h3 id="gp-allocation-title">{{ $t('State Finance Commission allocations','राज्य वित्त आयोग के आवंटन') }}</h3>
    @foreach($panchayat['allocations'] as $allocation)
    <article data-allocation-year="{{ $allocation['year'] }}">
        <h4>{{ $allocation['year'] }} · {{ $allocation['name'] }}</h4>
        <div class="panchayat-table" tabindex="0" role="region" aria-label="{{ $t('Allocation installments','आवंटन की किश्तें') }} {{ $allocation['year'] }}">
            <table><caption>{{ $t('Allocation installments','आवंटन की किश्तें') }} · {{ $allocation['year'] }} · {{ $allocation['unit']==='INR'?$t('Rupees (INR)','रुपये (INR)'):$t('Source units unconfirmed','स्रोत की इकाई अपुष्ट') }}</caption>
                <thead><tr><th scope="col">{{ $t('Installment · original label','किश्त · मूल लेबल') }}</th><th scope="col">{{ $t('Source amount','स्रोत की राशि') }}</th></tr></thead>
                <tbody>@foreach($allocation['installments'] as $installment)<tr data-state-allocation><th scope="row">{{ $installment['label'] }}</th><td>{{ $installment['amount']===null?$t('Unavailable','उपलब्ध नहीं'):$installment['amount'] }}</td></tr>@endforeach</tbody>
            </table>
        </div>
        <details class="gp-methodology"><summary>{{ $t('Allocation source and coverage','आवंटन का स्रोत और कवरेज') }}</summary>
        <p class="place-note">{{ $allocation['district'] }} · {{ $allocation['block'] }} · {{ $t('Source names','स्रोत के नाम') }}</p>
        @if($allocation['unit']===null)<p>{{ $t('The source uses a legacy Hindi font for its unit label. The unit has not been validated; values are displayed unchanged.','स्रोत की इकाई का लेबल पुराने हिंदी फ़ॉन्ट में है। इकाई की पुष्टि नहीं हुई है; मूल संख्याएँ बिना बदलाव दिखाई गई हैं।') }}</p>@endif
            <p><a href="{{ $allocation['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('UP Panchayati Raj · official allocation workbook','उत्तर प्रदेश पंचायती राज · आधिकारिक आवंटन कार्यपुस्तिका') }}</a></p>
            <p>{{ $t('Retrieved','प्राप्त किया गया') }}: {{ $allocation['captured_on'] }} · {{ $t('Sheet','शीट') }}: {{ $allocation['sheet'] }} · {{ $t('Row','पंक्ति') }}: {{ $allocation['row'] }}</p>
            <p style="overflow-wrap:anywhere">SHA-256: {{ $allocation['source_sha256'] }}</p>
            <p>{{ $t('Original installment labels, combined installments and missing amounts are preserved. No complete annual total is inferred.','मूल किश्त लेबल, संयुक्त किश्तें और अनुपलब्ध राशि सुरक्षित रखी गई हैं। पूरे वर्ष का योग अनुमान से नहीं बनाया गया है।') }}</p>
        </details>
    </article>
    @endforeach
<details class="gp-methodology"><summary>{{ $t('Disclaimer','अस्वीकरण') }}</summary>
    <p class="place-note">{{ $t('Source-reported · not independently verified','स्रोत के अनुसार · स्वतंत्र रूप से सत्यापित नहीं') }}</p>
    <p>{{ $t('These are Panchayat allocation schedules, not receipt vouchers, payments or expenditure. They are excluded from money-flow charts and voucher totals. Only the installments in the captured source are shown.','ये पंचायत के आवंटन विवरण हैं, प्राप्ति वाउचर, भुगतान या व्यय नहीं। इन्हें प्राप्ति-भुगतान के चार्ट और वाउचर के योग में शामिल नहीं किया गया है। केवल प्राप्त स्रोत की किश्तें दिखाई गई हैं।') }}</p>
    <p class="place-note">{{ $t('Matched by official Gram Panchayat LGD code. Village links use the current directory; historical village boundaries are not established. Amounts cover the whole Panchayat.','आधिकारिक ग्राम पंचायत LGD कोड से मिलान किया गया है। गाँव के संबंध वर्तमान निर्देशिका के अनुसार हैं; ऐतिहासिक गाँव सीमाएँ पुष्ट नहीं हैं। राशि पूरी पंचायत की है।') }}</p>
</details>
</section>
@endif
