@if(!empty($employment))
@once
<link rel="stylesheet" href="{{ asset('css/rural-employment.css') }}?v={{ substr(hash_file('sha256', public_path('css/rural-employment.css')), 0, 12) }}">
<script type="module" src="{{ asset('js/rural-employment.js') }}?v={{ substr(hash_file('sha256', public_path('js/rural-employment.js')), 0, 12) }}"></script>
@endonce
@php
    $rhi = $hi ?? app()->getLocale() === 'hi';
    $rt = fn ($en, $hn) => $rhi ? $hn : $en;
    $rnum = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
    $rnum->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
    $rnum->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
    $rupees = fn ($paise) => '₹'.$rnum->format($paise / 100);
    $labels = [
        'unskilled_wage_expenditure' => ['Unskilled wage expenditure', 'अकुशल मजदूरी व्यय'],
        'skilled_wage_expenditure' => ['Skilled / semi-skilled wage expenditure', 'कुशल / अर्धकुशल मजदूरी व्यय'],
        'material_expenditure' => ['Material expenditure', 'सामग्री व्यय'],
        'total_expenditure' => ['Total scheme expenditure', 'योजना का कुल व्यय'],
        'unskilled_wage_due' => ['Unskilled wage payment due', 'अकुशल मजदूरी भुगतान देय'],
        'skilled_wage_due' => ['Skilled / semi-skilled wage payment due', 'कुशल / अर्धकुशल मजदूरी भुगतान देय'],
        'material_due' => ['Material payment due', 'सामग्री भुगतान देय'],
        'total_due' => ['Total payment due', 'कुल भुगतान देय'],
    ];
@endphp
<section class="pb-panel pb-wide rural-employment" id="rural-employment" aria-labelledby="rural-employment-title">
<div class="rural-heading"><h2 id="rural-employment-title">{{ $rt('MGNREGA & rural employment', 'मनरेगा और ग्रामीण रोज़गार') }}</h2><a class="rural-action" href="{{ $employment['lookup_url'] }}" target="_blank" rel="noopener noreferrer">{{ $rt('Job card & wage lookup', 'जॉब कार्ड और मजदूरी खोजें') }} ↗</a></div>
<p class="rural-scope">{{ $rt('Gram Panchayat', 'ग्राम पंचायत') }}: <a href="{{ app(\App\Services\GramPanchayatUrls::class)->link($employment['state'], $employment['name'], $employment['gp']) }}">{{ $employment['name'] }}</a> · {{ $employment['scheme'] }} · {{ $employment['financial_year'] }} · <time datetime="{{ $employment['as_of'] }}">{{ $employment['as_of'] }}</time></p>
<div class="rural-table-scroll"><table id="rural-employment-finance"><thead><tr><th scope="col">{{ $rt('Measure', 'माप') }}</th><th scope="col">{{ $rt('Reported amount (₹ lakh)', 'दर्ज राशि (₹ लाख)') }}</th><th scope="col">{{ $rt('Reference', 'संदर्भ') }}</th></tr></thead><tbody>
@foreach($employment['finance'] as $key => $value)
<tr><th scope="row">{{ $labels[$key][$rhi ? 1 : 0] }}</th><td>{{ $value ?? '—' }}</td><td><a href="{{ $employment['finance_source'] }}" target="_blank" rel="noopener noreferrer">{{ $rt('Official financial report', 'आधिकारिक वित्तीय रिपोर्ट') }} ↗</a></td></tr>
@endforeach
</tbody></table></div>
@if($employment['works'])
<h3>{{ $rt('Works & reported wages', 'कार्य और दर्ज मजदूरी') }}</h3>
<div class="rural-table-scroll"><table id="rural-employment-works"><thead><tr><th scope="col">{{ $rt('Work', 'कार्य') }}</th><th scope="col">{{ $rt('Muster rolls', 'मस्टर रोल') }}</th><th scope="col">{{ $rt('Reported wage amount (₹)', 'दर्ज मजदूरी राशि (₹)') }}</th><th scope="col">{{ $rt('Reference', 'संदर्भ') }}</th></tr></thead><tbody>
@foreach($employment['works'] as $work)
<tr><th scope="row">{{ $work['name'] }}</th><td>{{ count($work['musters']) }}</td><td>{{ $rupees($work['amount_paise']) }}</td><td><a href="{{ $employment['work_source'] }}" target="_blank" rel="noopener noreferrer">{{ $rt('Official work report', 'आधिकारिक कार्य रिपोर्ट') }} ↗</a></td></tr>
@endforeach
</tbody></table></div>
<h3>{{ $rt('Muster-roll payment entries', 'मस्टर रोल भुगतान प्रविष्टियाँ') }}</h3>
<div class="rural-table-scroll"><table id="rural-employment-musters" data-rural-paginate><thead><tr><th scope="col">{{ $rt('Work', 'कार्य') }}</th><th scope="col">{{ $rt('Muster roll', 'मस्टर रोल') }}</th><th scope="col">{{ $rt('Reported payment date', 'दर्ज भुगतान तिथि') }}</th><th scope="col">{{ $rt('Amount (₹)', 'राशि (₹)') }}</th><th scope="col">{{ $rt('Reference', 'संदर्भ') }}</th></tr></thead><tbody>
@foreach($employment['works'] as $work)@foreach($work['musters'] as $muster)
<tr><th scope="row">{{ $work['name'] }}</th><td>{{ $muster['number'] }}</td><td><time datetime="{{ $muster['reported_payment_date'] }}">{{ $muster['reported_payment_date'] }}</time></td><td>{{ $rupees($muster['amount_paise']) }}</td><td><a href="{{ $employment['work_source'] }}" target="_blank" rel="noopener noreferrer">{{ $rt('Official report', 'आधिकारिक रिपोर्ट') }} ↗</a></td></tr>
@endforeach @endforeach
</tbody></table></div>
@endif
<details class="rural-notes"><summary>{{ $rt('Methodology, disclaimer & sources', 'पद्धति, अस्वीकरण और स्रोत') }}</summary>
<p>{{ $rt('Figures cover this Gram Panchayat, including implementing agencies working at Panchayat level. They are not village-only totals. Financial amounts are reported in lakh rupees, rounded by the source; detailed muster-roll amounts are in rupees.', 'आँकड़े इस ग्राम पंचायत और यहाँ काम करने वाली कार्यान्वयन संस्थाओं के हैं, केवल गाँव के नहीं। वित्तीय रिपोर्ट की राशि लाख रुपये में और मस्टर रोल की राशि रुपये में है।') }}</p>
<p>{{ $rt('Payment due means a muster roll, bill or voucher has been entered without a payment date in the official MIS. A reported payment date is not independent confirmation of bank credit. Individual job-card and worker payment lookup opens the official portal; personal job-card, Aadhaar and bank-account records are not copied here.', 'भुगतान देय का अर्थ है कि आधिकारिक MIS में मस्टर रोल, बिल या वाउचर दर्ज है, लेकिन भुगतान तिथि दर्ज नहीं है। दर्ज भुगतान तिथि बैंक खाते में राशि आने की स्वतंत्र पुष्टि नहीं है। व्यक्तिगत जॉब कार्ड और मजदूरी की खोज आधिकारिक पोर्टल पर होती है; व्यक्तिगत जॉब कार्ड, आधार और बैंक रिकॉर्ड यहाँ कॉपी नहीं किए गए हैं।') }}</p>
<p>{{ $rt('The source labels these 2026–27 records VB-G RAM G. Historical MGNREGA records are not relabelled. Some projects may be carried forward. Only uniquely matched Panchayats are included; missing figures are not zero.', 'स्रोत इन 2026–27 रिकॉर्ड को VB-G RAM G कहता है। ऐतिहासिक मनरेगा रिकॉर्ड का नाम नहीं बदला गया है। कुछ कार्य पिछले समय से जारी हो सकते हैं। केवल स्पष्ट मिलान वाली पंचायतें शामिल हैं; अनुपलब्ध आँकड़े शून्य नहीं हैं।') }}</p>
<p>{{ $rt('Captured', 'संग्रह तिथि') }}: {{ $employment['captured_on'] }} · <a href="{{ $employment['finance_source'] }}" target="_blank" rel="noopener noreferrer">{{ $rt('Official financial statement', 'आधिकारिक वित्तीय विवरण') }} ↗</a></p>
</details>
</section>
@endif
