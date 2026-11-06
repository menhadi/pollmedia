@if($panchayat['directory'])
@if(count($panchayat['directory'])>1)
<form data-local-data-form class="local-data-filters">
    <label>{{ $t('Gram Panchayat','ग्राम पंचायत') }}
        <select name="panchayat" required translate="no"><option value="">{{ $t('Choose a Panchayat','पंचायत चुनें') }}</option>
            @foreach($panchayat['directory'] as $gp)<option value="{{ $gp['code'] }}" @selected(($panchayat['selected']['code']??'')===$gp['code'])>{{ $gp['name'] }} · {{ implode(', ',$gp['blocks']) }}</option>@endforeach
        </select>
    </label><button type="submit">{{ $t('Show information','जानकारी देखें') }}</button>
</form>
@endif
<p class="place-note">{{ count($panchayat['directory']) }} {{ $t('Panchayat records available for this selection.','पंचायत रिकॉर्ड इस चयन के लिए उपलब्ध हैं।') }}</p>
@if($panchayat['selected'])
@php($gp=$panchayat['selected'])
<article class="gp-identity" translate="no"><p class="gp-eyebrow">{{ $t('Panchayat overview','पंचायत परिचय') }}</p><h3>{{ $gp['name'] }}</h3>
<dl class="panchayat-status"><div><dt>{{ $t('Panchayat identifier','पंचायत पहचान') }}</dt><dd>{{ $gp['code'] }}</dd><small>{{ $gp['namespace'] }}</small></div>
<div><dt>{{ $t('Block','ब्लॉक') }}</dt><dd>{{ implode(' · ',$gp['blocks']) }}</dd></div>
@if($gp['villages'])<div><dt>{{ $t('Linked villages','जुड़े गाँव') }}</dt><dd>{{ count($gp['villages']) }}</dd></div>@endif</dl>
@if($gp['checked_on'])<p class="place-note">{{ $t('Directory checked','निर्देशिका जाँच') }}: {{ $gp['checked_on'] }}</p>@endif
@if($gp['villages'])
<h4>{{ $t('Villages covered','संबंधित गाँव') }}</h4><ul class="gp-villages">
@foreach($gp['villages'] as $village)<li><a href="{{ route('my-place.index',['state'=>$context['state']['code'],'district'=>$context['district']['code'],'village'=>$village['code'],'lang'=>$language]) }}">{{ $village['name'] }}</a></li>@endforeach
</ul>
@endif
</article>
@include('my-place-panchayat-payment-totals')
@include('my-place-panchayat-allocations')
@include('my-place-panchayat',['hub'=>['panchayat_reports'=>$panchayat['reports']]])
@include('my-place-panchayat-activities')
<details class="gp-methodology"><summary>{{ $t('Disclaimer, methodology & sources','अस्वीकरण, कार्यप्रणाली और स्रोत') }}</summary>
<p>{{ $t('Approval is the source status, not confirmation that a work is complete. Original activity text is preserved.','स्वीकृति स्रोत की स्थिति है, कार्य पूरा होने की पुष्टि नहीं। गतिविधि का मूल पाठ सुरक्षित है।') }}</p>
    <h4>{{ $t('Available information','उपलब्ध जानकारी') }}</h4>
    @if(!$panchayat['reports'])<p>{{ $t('Accounts have not yet been retrieved for this Panchayat. This does not mean there is no financial activity.','इस पंचायत के खाते अभी प्राप्त नहीं हुए हैं। इसका मतलब वित्तीय गतिविधि न होना नहीं है।') }}</p>@endif
    <p>{{ $t('Available records are shown as reported by the source, without independent verification of the underlying activities. Representative details, completion evidence and an asset register have not been retrieved. An approved activity is a plan entry, not proof of completion or payment.','उपलब्ध रिकॉर्ड स्रोत के अनुसार दिखाए गए हैं; गतिविधियों की स्वतंत्र जाँच नहीं की गई है। प्रतिनिधियों का विवरण, कार्य पूरा होने के प्रमाण और संपत्ति रजिस्टर प्राप्त नहीं हुए हैं। स्वीकृत गतिविधि योजना की प्रविष्टि है, कार्य पूरा होने या भुगतान का प्रमाण नहीं।') }}</p>
    @if(collect($panchayat['reports'])->contains(fn($report)=>!empty($report['activities'])))
    <p>{{ $t('Activity names, codes and status are preserved as reported. The activity report does not state a cost unit; its numeric costs are displayed unchanged and are not added to voucher totals. Blank costs remain unavailable. Repeated names with different activity codes are separate source records. The source link opens the official lookup, which may require selecting the Panchayat and completing a CAPTCHA.','गतिविधियों के नाम, कोड और स्थिति मूल रूप में रखे गए हैं। गतिविधि रिपोर्ट में लागत की इकाई नहीं दी गई है; संख्याएँ ज्यों की त्यों दिखाई गई हैं और वाउचर के योग में नहीं जोड़ी गई हैं। खाली लागत अनुपलब्ध रहती है। अलग कोड वाले समान नाम अलग स्रोत रिकॉर्ड हैं। स्रोत लिंक आधिकारिक खोज खोलता है, जिसमें पंचायत चयन और कैप्चा की जरूरत हो सकती है।') }}</p>
    @endif
    <h4>{{ $t('How to read the figures','आँकड़े कैसे पढ़ें') }}</h4>
    <p>{{ $t('Plan details show descriptions, proposed assets or training, and funding allocations only where the returned record identifies the same activity, Panchayat and financial year. Empty detail templates remain unavailable. Funding allocations and planned asset costs are not spending or payments. No Data Found in an activity expenditure panel does not mean zero expenditure; the separately dated vouchers remain the accounting evidence. Details checked is the retrieval date. Units are not inferred when the source leaves them unstated.','योजना का विवरण, प्रस्तावित परिसंपत्ति या प्रशिक्षण और धन आवंटन तभी दिखते हैं जब रिकॉर्ड में वही गतिविधि, पंचायत और वित्तीय वर्ष हो। खाली विवरण अनुपलब्ध रहते हैं। आवंटन और प्रस्तावित परिसंपत्ति की लागत खर्च या भुगतान नहीं है। गतिविधि व्यय में No Data Found का अर्थ शून्य व्यय नहीं; अलग तारीख वाले वाउचर लेखा साक्ष्य हैं। विवरण जाँचा गया प्राप्ति की तारीख है। स्रोत में इकाई न होने पर अनुमान नहीं लगाया जाता।') }}</p>
    <p>{{ $t('Receipt and payment totals add only the dated vouchers shown here. Transfers and journal entries are kept separate; no balance or annual budget is calculated. A dash means no verified amount is available, not zero. If any included voucher has an unavailable amount, its total stays unavailable. The monthly activity table counts vouchers; the chart shows rupee amounts.','प्राप्ति और भुगतान के योग में यहाँ दिखाए गए तारीख वाले वाउचर ही शामिल हैं। अंतरण और जर्नल प्रविष्टियाँ अलग हैं; शेष राशि या वार्षिक बजट नहीं निकाला जाता। डैश का मतलब पुष्ट राशि उपलब्ध नहीं है, शून्य नहीं। किसी शामिल वाउचर की राशि अनुपलब्ध होने पर योग भी अनुपलब्ध रहता है। मासिक गतिविधि तालिका में वाउचर की संख्या है; चार्ट में रुपये की राशि है।') }}</p>
    <p>{{ $t('Future months and conflicting duplicate records are excluded. Panchayat figures cover the whole Panchayat, even when opened from a village. Original names and official identifier namespaces are preserved.','भविष्य के महीने और टकराते दोहरे रिकॉर्ड शामिल नहीं हैं। गाँव से खोलने पर भी आँकड़े पूरी पंचायत के हैं। मूल नाम और आधिकारिक पहचान की अलग प्रणालियाँ सुरक्षित रखी जाती हैं।') }}</p>
    @if($gp['source_url'])<p><a href="{{ $gp['source_url'] }}" target="_blank" rel="noopener noreferrer">LGD · {{ $t('Village–Panchayat mapping','गाँव–पंचायत संबंध') }}</a> · {{ $gp['checked_on'] }}</p>@endif
    @foreach($panchayat['reports'] as $report)
    <h4 translate="no">{{ $report['name'] }} · {{ $report['financial_year'] }}</h4>
    @if(isset($report['sources']['accounting']))
    @php($yesNo=fn($value)=>$value===null?$t('Unavailable','उपलब्ध नहीं'):($value?$t('Yes','हाँ'):$t('No','नहीं')))
    <dl class="panchayat-status">
        <div><dt>{{ $t('Opening balance entered','प्रारंभिक शेष दर्ज') }}</dt><dd>{{ $yesNo($report['opening_balance_entered']) }}</dd></div>
        <div><dt>{{ $t('Month book closed','मासिक खाता बंद') }}</dt><dd>{{ $yesNo($report['month_book_closed']) }}</dd></div>
        <div><dt>{{ $t('Year book closed','वार्षिक खाता बंद') }}</dt><dd>{{ $yesNo($report['year_book_closed']) }}</dd></div>
    </dl>
    @endif
    <ul>@foreach($report['sources'] as $kind=>$source)<li><a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">eGramSwaraj · {{ str_replace('_',' ',$kind) }}</a> · {{ $source['report_date'] }}</li>@endforeach</ul>
    @endforeach
</details>
@else
<p>{{ $t('Choose a Panchayat to see its own information.','पंचायत की जानकारी देखने के लिए उसे चुनें।') }}</p>
@endif
@else
<p class="local-data-empty">{{ $context['village'] ? $t('A verified Gram Panchayat link is not yet available for this village.','इस गाँव का पुष्ट ग्राम पंचायत संबंध अभी उपलब्ध नहीं है।') : $t('Panchayat records are not yet available for this district.','इस जिले के पंचायत रिकॉर्ड अभी उपलब्ध नहीं हैं।') }}</p>
@endif
