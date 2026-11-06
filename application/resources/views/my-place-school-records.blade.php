@php($schoolValue=fn($field,$value)=>app(\App\Services\MyPlaceSchools::class)->value($field,$value,$schools['dataset']??[],$language))
@if($schools['dataset'] || $schools['named'])
<form data-local-data-form class="local-data-filters">
    @if($schools['scope']==='district')
    <input type="hidden" name="browse" value="district">
    @if($schools['blocks'])<label>{{ $t('Source block','स्रोत ब्लॉक') }}<select name="block" translate="no"><option value="">{{ $t('All available blocks','सभी उपलब्ध ब्लॉक') }}</option>@foreach($schools['blocks'] as $block)<option value="{{ $block }}" @selected(($schools['filters']['block']??'')===$block)>{{ $block }}</option>@endforeach</select></label>@endif
    @if(!empty($schools['filters']['block']))<label>{{ $t('Source village','स्रोत गाँव') }}<select name="source_village" translate="no"><option value="">{{ $t('All available villages','सभी उपलब्ध गाँव') }}</option>@foreach($schools['villages'] as $name)<option value="{{ $name }}" @selected(($schools['filters']['source_village']??'')===$name)>{{ $name }}</option>@endforeach</select></label>@endif
    @endif
    <label>{{ $t('PIN code','पिन कोड') }}<input type="text" name="pin" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" value="{{ $schools['filters']['pin']??'' }}" placeholder="{{ $t('Any PIN','कोई भी पिन') }}"></label>
    <button type="submit">{{ $t('Filter schools','स्कूल छाँटें') }}</button>
    @if($context['village'])<button type="button" class="secondary" data-local-browse="{{ $schools['scope']==='district'?'village':'district' }}">{{ $schools['scope']==='district'?$t('Back to selected village','चुने गाँव पर लौटें'):$t('Browse district records','जिले के रिकॉर्ड देखें') }}</button>@endif
</form>
@if($schools['named'])
<h3>{{ $t('Named schools in the public directory','सार्वजनिक निर्देशिका के स्कूल') }}</h3>
<p class="place-note">{{ $t('District directory entries. These are not a nearest-school ranking or a complete school census.','जिला निर्देशिका के रिकॉर्ड। यह सबसे नज़दीकी स्कूलों की रैंकिंग या सभी स्कूलों की पूरी सूची नहीं है।') }}</p>
<div class="school-directory-cards">
@foreach($schools['named'] as $school)<article translate="no"><h4>{{ $school['name'] }}</h4><p class="school-native">{{ $school['native_name'] }}</p><p>{{ $school['address'] }}</p>
@if($school['pin_conflict'])<p class="place-note">{{ $t('PIN conflict in source','स्रोत में पिन का अंतर') }}: {{ $school['pin_note'] }}</p>@else<p><strong>{{ $t('PIN','पिन') }}:</strong> {{ $school['pin'] }}</p>@endif
<small>{{ $t('Directory updated','निर्देशिका अपडेट') }}: {{ $school['source_updated_on'] }}</small></article>@endforeach
</div>
@elseif(!$schools['dataset'])
<p class="local-data-empty">{{ $t('No verified school directory entries are stored for this selection yet. This does not mean there are no schools.','इस चयन के लिए पुष्ट स्कूल निर्देशिका रिकॉर्ड अभी संग्रहीत नहीं हैं। इसका मतलब स्कूल न होना नहीं है।') }}</p>
@endif
@if($schools['dataset'])
<p class="school-profile-year"><strong>{{ $schools['dataset']['academic_year'] }}</strong></p>
<p role="status"><strong>{{ number_format($schools['total']) }}</strong> {{ $t('profiles match these filters','प्रोफाइल इन फ़िल्टर से मेल खाते हैं') }} · {{ $t('Page','पृष्ठ') }} {{ $schools['page'] }} / {{ $schools['pages'] }}</p>
@forelse($schools['profiles'] as $profile)
<article class="school-profile" translate="no"><header><h4>{{ $t('School profile','स्कूल प्रोफाइल') }} {{ $profile['pseudocode'] }}</h4><span>{{ $t('Classes','कक्षाएँ') }} {{ $schoolValue('lowclass',$profile['lowclass']) }}–{{ $schoolValue('highclass',$profile['highclass']) }}</span></header>
<dl class="school-profile-facts"><div><dt>{{ $t('Village / urban area','गाँव / शहरी क्षेत्र') }}</dt><dd>{{ $profile['lgd_vill_name'] ?: ($profile['lgd_urban_local_body_name'] ?: $t('Unavailable','उपलब्ध नहीं')) }}</dd></div><div><dt>{{ $t('Block','ब्लॉक') }}</dt><dd>{{ $profile['lgd_block_name'] ?: $profile['block'] }}</dd></div><div><dt>{{ $t('Source PIN','स्रोत पिन') }}</dt><dd>{{ $schoolValue('pincode',$profile['pincode']) }}</dd></div><div><dt>{{ $t('Gram Panchayat in source','स्रोत में ग्राम पंचायत') }}</dt><dd>{{ $schoolValue('lgd_vill_panchayat_name',$profile['lgd_vill_panchayat_name']) }}</dd></div></dl>
<details><summary>{{ $t('All available profile fields','सभी उपलब्ध प्रोफाइल विवरण') }} · {{ count($schools['dataset']['columns']) }}</summary><dl class="school-all-fields">@foreach($schools['dataset']['columns'] as $field=>$fieldLabel)<div><dt>{{ $fieldLabel }}</dt><dd>{{ $schoolValue($field,$profile[$field]??null) }}</dd></div>@endforeach</dl></details></article>
@empty<p class="local-data-empty">{{ $t('No profiles in this file match the selected filters. This is not a count of all schools in the area.','इस फ़ाइल का कोई प्रोफाइल चुने फ़िल्टर से मेल नहीं खाता। यह क्षेत्र के सभी स्कूलों की गिनती नहीं है।') }}</p>@endforelse
@if($schools['pages']>1)<nav class="local-data-pages" aria-label="{{ $t('School profile pages','स्कूल प्रोफाइल पृष्ठ') }}"><button type="button" class="secondary" data-local-page="{{ $schools['page']-1 }}" @disabled($schools['page']<=1)>{{ $t('Previous','पिछला') }}</button><button type="button" data-local-page="{{ $schools['page']+1 }}" @disabled($schools['page']>=$schools['pages'])>{{ $t('Next','अगला') }}</button></nav>@endif
<details class="school-source-note"><summary>{{ $t('Source & coverage','स्रोत और कवरेज') }}</summary>
<p class="place-note">{{ $t('The supplied UDISE+ file contains profiles without school names or official UDISE codes. These profiles have not been joined to the named directory above.','दी गई UDISE+ फ़ाइल में स्कूल के नाम और आधिकारिक UDISE कोड नहीं हैं। इन प्रोफाइल को ऊपर की नाम वाली निर्देशिका से नहीं जोड़ा गया है।') }}</p>
@if($schools['scope']==='village-names')<p class="place-note">{{ $t('Source-reported village and block names match this selection. The PIN and Panchayat names are source values; a code-based village link and independent PIN confirmation are not established.','स्रोत के गाँव और ब्लॉक के नाम इस चयन से मेल खाते हैं। पिन और पंचायत के नाम स्रोत के मान हैं; गाँव के कोड से संबंध और पिन की स्वतंत्र पुष्टि स्थापित नहीं है।') }}</p>
@else<p class="place-note">{{ $t('Coverage: supplied education-district file, which may differ from the current civil district.','कवरेज: दी गई शिक्षा-जिला फ़ाइल, जिसका क्षेत्र वर्तमान प्रशासनिक जिले से अलग हो सकता है।') }} {{ $schools['totalSource'] }} {{ $t('source profiles.','स्रोत प्रोफाइल।') }}</p>@endif
</details>
@endif
@else
<p class="local-data-empty">{{ $t('School records are not yet stored for this selection. This does not mean there are no schools.','इस चयन के स्कूल रिकॉर्ड अभी संग्रहीत नहीं हैं। इसका मतलब स्कूल न होना नहीं है।') }}</p>
@endif
