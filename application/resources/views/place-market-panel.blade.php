@php
 $marketLanguage = request()->query('lang', app()->getLocale()) === 'hi' ? 'hi' : 'en';
 $marketT = fn($en,$hi) => $marketLanguage === 'hi' ? $hi : $en;
 $marketOptions = $marketPageScope['options'] ?? [];
 $marketTitle = $marketT('Mandi prices & trends','मंडी भाव और रुझान');
@endphp
<link rel="stylesheet" href="{{ asset('css/market-prices.css') }}?v={{ substr(hash_file('sha256',public_path('css/market-prices.css')),0,12) }}">
<link rel="stylesheet" href="{{ asset('css/page-market-prices.css') }}?v={{ substr(hash_file('sha256',public_path('css/page-market-prices.css')),0,12) }}">
<section id="mandi-prices" class="panel coverage card page-market-panel" lang="{{ $marketLanguage }}" translate="no" data-page-market data-nav-label="{{ $marketTitle }}" data-language="{{ $marketLanguage }}" data-market-url="{{ route('my-place.markets') }}" data-history-url="{{ route('my-place.market-history') }}">
 <h2>{{ $marketTitle }}</h2>
 <p>{{ $marketT('District market reports, with their reporting dates and price units.','जिले के बाज़ारों की रिपोर्ट, उनकी रिपोर्ट की तारीख और भाव की इकाई के साथ।') }}</p>
 @if($marketOptions)
  <label class="page-market-district">{{ $marketT('Current market district','वर्तमान बाज़ार जिला') }}<select data-market-page-district>
   @if(count($marketOptions)>1)<option value="">{{ $marketT('Choose a linked current district','संबंधित वर्तमान जिला चुनें') }}</option>@endif
   @foreach($marketOptions as $option)<option value="{{ $option['key'] }}" data-state="{{ $option['state'] }}" data-district="{{ $option['district'] }}">{{ $marketLanguage==='hi'?\App\Services\PublicLanguage::placeText($option['district_name']):$option['district_name'] }} · {{ $marketLanguage==='hi'?\App\Services\PublicLanguage::placeText($option['state_name']):$option['state_name'] }}</option>@endforeach
  </select></label>
  <details data-market-page-details open><summary>{{ $marketT('Browse mandi prices and yearly trends','मंडी भाव और सालाना रुझान देखें') }}</summary><div data-market-page-mount></div></details>
 @else
  <p class="page-market-unavailable">{{ $marketT('The current market district could not be uniquely linked to this source page. Prices and trends remain unavailable.','इस स्रोत पृष्ठ से वर्तमान बाज़ार जिले का एकमात्र संबंध स्थापित नहीं हुआ। भाव और रुझान उपलब्ध नहीं हैं।') }}</p>
 @endif
 <details class="page-market-methodology" data-methodology-note translate="no"><summary>{{ $marketT('Mandi methodology, coverage & sources','मंडी भाव की पद्धति, उपलब्धता और स्रोत') }}</summary>
 <p>{{ $marketT('Village pages show district-level market reports. Current districts are linked only through the existing official LGD ancestry, unique Census 2011 village correspondence or an existing official district crosswalk. Historical boundaries are retained; a historical district spanning several current districts requires a selection. Census year does not change the reporting dates of prices.','गाँव के पृष्ठ पर जिले के बाज़ारों की रिपोर्ट दिखती हैं। वर्तमान जिले का मिलान मौजूदा आधिकारिक LGD संबंध, एकमात्र Census 2011 गाँव संबंध या मौजूदा आधिकारिक जिला मिलान से होता है। ऐतिहासिक सीमाएँ सुरक्षित रहती हैं; कई वर्तमान जिलों में फैले पुराने जिले के लिए चयन आवश्यक है। जनगणना का वर्ष मंडी भाव की रिपोर्ट की तारीख नहीं बदलता।') }}</p>
 <p>{{ $marketT('Units follow the official AGMARKNET commodity directory. Prices are dated wholesale observations. Trends are unweighted monthly means of reported modal prices for the selected district, commodity, variety and grade. Missing months remain gaps. The year-on-year comparison uses the same calendar days; the data table also includes the full month last year. Reporting coverage can differ.','इकाइयाँ आधिकारिक AGMARKNET जिंस निर्देशिका से ली गई हैं। ये तारीख सहित थोक भाव की रिपोर्ट हैं। रुझान चुने जिले, जिंस, किस्म और ग्रेड के रिपोर्ट किए गए प्रचलित भाव का बिना भार का मासिक औसत है। गायब महीने खाली रहते हैं। सालाना तुलना में समान कैलेंडर दिन लिए जाते हैं; आँकड़ों की तालिका में पिछले वर्ष का पूरा महीना भी है। रिपोर्ट की उपलब्धता अलग हो सकती है।') }}</p>
 @if(($marketPageScope['matched']??0)>0)<p>{{ $marketT('Verified village correspondences','सत्यापित गाँव संबंध') }}: {{ $marketPageScope['matched'] }} · {{ $marketT('Unlinked source villages','बिना संबंध वाले स्रोत गाँव') }}: {{ $marketPageScope['unlinked'] ?? 0 }}</p>@endif
 <p>{{ $marketT('Latest available reports combine the daily and archive feeds. Newer report dates replace older ones for the same market, item, variety and grade. For a same-date revision, the daily feed takes priority.','नवीनतम उपलब्ध रिपोर्ट में दैनिक और पुराने आँकड़ों के स्रोत दोनों शामिल हैं। एक ही बाज़ार, जिंस, किस्म और ग्रेड के लिए नई रिपोर्ट की तारीख पुरानी रिपोर्ट की जगह लेती है। एक ही तारीख के संशोधन में दैनिक स्रोत को प्राथमिकता मिलती है।') }}</p>
 <ul><li><a href="https://www.data.gov.in/catalog/current-daily-price-various-commodities-various-markets-mandi">AGMARKNET · data.gov.in</a></li><li><a href="https://api.agmarknet.gov.in/v1/commodities?page_size=1000">{{ $marketT('Official commodity price units','जिंस की आधिकारिक भाव इकाइयाँ') }}</a></li><li><a href="https://lgdirectory.gov.in/downloadDirectory.do">{{ $marketT('LGD official directory','LGD आधिकारिक निर्देशिका') }}</a></li></ul>
 </details>
</section>
<script type="module" src="{{ asset('js/page-market-prices.js') }}?v={{ substr(hash_file('sha256',public_path('js/page-market-prices.js')),0,12) }}"></script>
