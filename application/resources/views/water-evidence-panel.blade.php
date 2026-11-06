@php
    $waterLanguage = $waterLanguage ?? (app()->getLocale()==='hi' ? 'hi' : 'en');
    $waterTranslate = fn($en,$hi) => $waterLanguage==='hi' ? $hi : $en;
    $waterQuery = array_filter(['state'=>$waterState??null,'district'=>$waterDistrict??null,'village'=>$waterVillage??null,'census_state'=>$waterCensusState??null,'census_district'=>$waterCensusDistrict??null],fn($value)=>$value!==null && $value!=='');
@endphp
@once
<link rel="stylesheet" href="{{ asset('css/water-evidence.css') }}?v=1">
<script type="module" src="{{ asset('js/water-evidence.js') }}?v=1"></script>
@endonce
<div class="water-evidence" data-water-evidence data-identity-pending="{{ empty($waterQuery) ? '1' : '0' }}" data-language="{{ $waterLanguage }}" data-url="{{ route('water.groundwater-records',$waterQuery) }}">
@foreach(['level'=>['groundwater-levels','Groundwater levels','भूजल स्तर'],'chemistry'=>['groundwater-chemistry','Groundwater chemistry','भूजल रसायन'],'quality'=>['drinking-water-quality','Drinking water quality','पीने के पानी की गुणवत्ता']] as $waterKind=>[$waterAnchor,$waterEnglish,$waterHindi])
<section id="{{ $waterAnchor }}" class="panel card coverage place-panel water-evidence-section" data-water-kind="{{ $waterKind }}">
<p class="eyebrow">{{ $waterTranslate('Water observations','जल संबंधी अवलोकन') }}</p><h2>{{ $waterTranslate($waterEnglish,$waterHindi) }}</h2>
<p data-water-status role="status">{{ $waterTranslate('Loading available evidence for this selected place…','चुने क्षेत्र के उपलब्ध रिकॉर्ड लोड हो रहे हैं…') }}</p>
<div data-water-controls></div><div data-water-records></div>
<details class="place-methodology"><summary>{{ $waterTranslate('Sources dates and limitations','स्रोत तारीखें और सीमाएँ') }}</summary>
@if($waterKind==='level')<p>{{ $waterTranslate('Historical monitoring-well observations in metres. Well depth, aquifer and depth/elevation convention need verification before interpreting rise or fall. These are not area-wide water availability estimates.','मीटर में ऐतिहासिक निगरानी-कूप रिकॉर्ड। बढ़त या गिरावट समझने से पहले कूप की गहराई, जलभृत और माप की परिभाषा जाँचना ज़रूरी है। ये पूरे क्षेत्र में उपलब्ध पानी का अनुमान नहीं हैं।') }}</p>
@elseif($waterKind==='chemistry')<p>{{ $waterTranslate('Measurements apply to the sampled monitoring location and date. Missing tests remain unavailable. Nitrate N in mgN/L differs from nitrate in mg/L. No drinking-water safety classification is assigned.','माप केवल नमूना लिए गए निगरानी स्थान और तारीख के हैं। गायब जाँच उपलब्ध नहीं है। नाइट्रेट नाइट्रोजन की इकाई नाइट्रेट से अलग है। पीने योग्य होने का निर्णय नहीं दिया गया है।') }}</p>
@else<p>{{ $waterTranslate('WQMIS laboratory or field-test results are kept separate from groundwater chemistry. No verified sample-level results are imported yet. Missing reports do not establish safe or unsafe water.','WQMIS की प्रयोगशाला या फील्ड जाँच भूजल रसायन से अलग है। अभी पुष्ट नमूना रिकॉर्ड आयात नहीं हुए हैं। गायब रिपोर्ट से पानी को सुरक्षित या असुरक्षित नहीं कह सकते।') }}</p><a href="https://ejalshakti.gov.in/WQMIS/Main/report" target="_blank" rel="noopener">{{ $waterTranslate('Official WQMIS reports','आधिकारिक WQMIS रिपोर्ट') }} ↗</a>
@endif
<p>{{ $waterTranslate('Village links require source locality and unique polygon LGD identity to agree. Source boundary editions remain unverified. A monitoring-location association is not a household water-supply link.','गाँव संबंध के लिए स्रोत का नाम और एकमात्र बहुभुज LGD पहचान समान होना चाहिए। स्रोत सीमा का संस्करण अपुष्ट है। निगरानी स्थान का संबंध घरेलू जलापूर्ति का संबंध नहीं है।') }}</p><div data-water-sources></div>
</details></section>
@endforeach
</div>
