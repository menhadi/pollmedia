@if(!empty($panchayat['payment_totals']))
<section class="panchayat-report" data-payment-totals>
    <h3>{{ $t('Total payments · source summary','कुल भुगतान · स्रोत का सारांश') }}</h3>
    <div class="gp-money-grid">
        @foreach($panchayat['payment_totals'] as $total)
        <article class="gp-money-card gp-money-payments" data-payment-total="{{ $total['panchayat_code'] }}|{{ $total['year'] }}">
            <h5>{{ $t('Total payments','कुल भुगतान') }} · {{ $total['year'] }}</h5>
            <strong translate="no">{{ $total['unit'] === 'INR' ? '₹' : '' }}{{ $total['source_amount'] }}</strong>
            @if(str_starts_with($total['source_amount'], '-'))<span>{{ $t('Negative amount as reported by the source; reason not established.','स्रोत में बताई गई ऋणात्मक राशि; कारण स्थापित नहीं है।') }}</span>@endif
            @if($total['unit'] === null)<span>{{ $t('Source unit unconfirmed · original number shown','स्रोत की इकाई अपुष्ट · मूल संख्या दिखाई गई है') }}</span>@endif
            <a href="{{ $total['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('Official payment report','आधिकारिक भुगतान रिपोर्ट') }}</a>
            <details class="gp-methodology"><summary>{{ $t('Source details','स्रोत का विवरण') }}</summary>
            <small>{{ $t('Source snapshot captured','स्रोत का विवरण लिया गया') }}: {{ $total['captured_on'] }}</small>
            @if(!empty($total['source_generated_text']))<small>{{ $t('Source report date','स्रोत रिपोर्ट की तारीख') }}: {{ \Illuminate\Support\Str::after($total['source_generated_text'], ' on ') }}</small>@endif
            </details>
        </article>
        @endforeach
    </div>
<details class="gp-methodology"><summary>{{ $t('Disclaimer','अस्वीकरण') }}</summary>
    <p class="place-note">{{ $t('Source-reported · not independently verified','स्रोत के अनुसार · स्वतंत्र रूप से सत्यापित नहीं') }}</p>
    <p class="place-note">{{ $t('The official summary covers the whole Panchayat. This dated total is separate from the sum of captured vouchers below; it is not a final annual account, budget or bank balance.','आधिकारिक सारांश पूरी पंचायत का है। इस तारीख का कुल भुगतान नीचे उपलब्ध वाउचर के योग से अलग है; यह अंतिम वार्षिक खाता, बजट या बैंक शेष नहीं है।') }}</p>
</details>
</section>
@endif
