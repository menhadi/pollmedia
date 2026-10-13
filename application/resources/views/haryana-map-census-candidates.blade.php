@if(in_array([($data['census_district_code'] ?? ''), trim($data['name'])], [['069', 'Panchkula'], ['070', 'Ambala'], ['071', 'Yamunanagar'], ['072', 'Kurukshetra'], ['073', 'Kaithal'], ['074', 'Karnal'], ['075', 'Panipat'], ['076', 'Sonipat'], ['077', 'Jind'], ['078', 'Fatehabad'], ['079', 'Sirsa'], ['080', 'Hisar'], ['081', 'Bhiwani'], ['082', 'Rohtak'], ['083', 'Jhajjar'], ['084', 'Mahendragarh'], ['085', 'Rewari'], ['086', 'Gurgaon'], ['087', 'Mewat'], ['088', 'Faridabad'], ['089', 'Palwal'], ['035', 'Gurdaspur'], ['036', 'Kapurthala'], ['037', 'Jalandhar'], ['038', 'Hoshiarpur'], ['039', 'Shahid Bhagat Singh Nagar'], ['040', 'Fatehgarh Sahib'], ['041', 'Ludhiana'], ['042', 'Moga'], ['043', 'Firozpur'], ['044', 'Muktsar'], ['045', 'Faridkot'], ['046', 'Bathinda'], ['047', 'Mansa'], ['048', 'Patiala'], ['049', 'Amritsar'], ['050', 'Tarn Taran'], ['051', 'Rupnagar'], ['052', 'Sahibzada Ajit Singh Nagar'], ['053', 'Sangrur'], ['054', 'Barnala'], ['090', 'North West'], ['091', 'North']], true))
@if(($data['census_district_code'] ?? '') === '081')
<p class="source-note">Map coverage: 270 SOI Bhiwani shapes versus 444 Census 2011 villages. The later Charkhi Dadri area is outside this map selection. This is not the full historical district boundary; boundary editions and village crosswalks remain unverified.</p>
@endif
@if(($data['census_district_code'] ?? '') === '086')
<p class="source-note">Map coverage: 287 SOI Gurgaon shapes versus 242 Census 2011 villages; 103 duplicate-code shapes remain ambiguous. These counts do not establish boundary correspondence. The dated electoral Gurgaon district includes later Mewat references; a Census 2011 district-to-constituency crosswalk is not yet established. Constituency links are deferred rather than assigning the older district scope to this Census district.</p>
@endif
@if(($data['census_district_code'] ?? '') === '087')
<p class="source-note">Map coverage: 441 SOI shapes labelled NUH versus 439 Census 2011 Mewat villages. Source names and editions are retained; these counts do not establish boundary correspondence. The dated electoral Gurgaon district includes later Mewat references. District constituency links are deferred until a Census 2011 electoral crosswalk is established; no older district membership or population is assigned to this district.</p>
@endif
@if(($data['census_district_code'] ?? '') === '088')
<p class="source-note">Map coverage: 189 SOI Faridabad shapes versus 149 Census 2011 villages; 58 duplicate-code shapes remain ambiguous. Source editions and boundary correspondence are unverified. The dated electoral Faridabad district includes later Palwal references. District constituency links are deferred until a Census 2011 electoral crosswalk is established; no older district membership or population is assigned to this district.</p>
@endif
@if(($data['census_district_code'] ?? '') === '089')
<p class="source-note">Map coverage: 281 SOI Palwal shapes versus 280 Census 2011 villages; 12 duplicate-code shapes remain ambiguous. Source editions and boundary correspondence are unverified. The dated electoral Faridabad district includes later Palwal references. District constituency links are deferred until a Census 2011 electoral crosswalk is established; no older district membership or population is assigned to this district.</p>
@endif
@if(($data['census_district_code'] ?? '') === '035')
<p class="source-note">Map coverage: 1,208 valid SOI Gurdaspur source shapes versus 1,595 Census 2011 villages. Two invalid source shapes are excluded without repair; 65 duplicate-code shapes remain ambiguous. The later Pathankot source selection is outside this map. This is not the full historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '036')
<p class="source-note">Map coverage: 651 SOI Kapurthala source shapes versus 688 Census 2011 villages; 27 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '037')
<p class="source-note">Map coverage: 983 SOI Jalandhar source shapes versus 941 Census 2011 villages; 68 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '038')
<p class="source-note">Map coverage: 1,409 valid SOI Hoshiarpur source shapes versus 1,416 Census 2011 villages. Two invalid source shapes are excluded without repair; 60 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '039')
<p class="source-note">Map coverage: 436 SOI Shahid Bhagat Singh Nagar source shapes versus 468 Census 2011 villages; four duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '040')
<p class="source-note">Map coverage: 452 valid SOI Fatehgarh Sahib source shapes versus 446 Census 2011 villages. One invalid source shape is excluded without repair; 11 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '041')
<p class="source-note">Map coverage: 933 SOI Ludhiana source shapes versus 907 Census 2011 villages; 38 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '042')
<p class="source-note">Map coverage: 319 SOI Moga source shapes versus 325 Census 2011 villages; five duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '043')
<p class="source-note">Map coverage: 652 SOI Firozpur source shapes versus 1,000 Census 2011 villages; 26 duplicate-code shapes remain ambiguous. The later Fazilka source selection is excluded, so this is not the full historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '044')
<p class="source-note">Map coverage: 235 SOI Muktsar source shapes versus 234 Census 2011 villages. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '045')
<p class="source-note">Map coverage: 168 SOI Faridkot source shapes versus 171 Census 2011 villages; two duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '046')
<p class="source-note">Map coverage: 289 SOI Bathinda source shapes versus 281 Census 2011 villages; eight duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '047')
<p class="source-note">Map coverage: 239 SOI Mansa source shapes versus 240 Census 2011 villages. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '048')
<p class="source-note">Map coverage: 969 SOI Patiala source shapes versus 912 Census 2011 villages; 35 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '049')
<p class="source-note">Map coverage: 752 SOI Amritsar source shapes versus 739 Census 2011 villages; 35 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '050')
<p class="source-note">Map coverage: 491 SOI Tarn Taran source shapes versus 489 Census 2011 villages; 14 duplicate-code shapes remain ambiguous and one invalid source shape (37992) is excluded without repair. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '051')
<p class="source-note">Map coverage: 604 SOI Rupnagar source shapes versus 611 Census 2011 villages; 31 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '052')
<p class="source-note">Map coverage: 420 SOI SAS Nagar source shapes versus 419 Census 2011 villages; 27 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '053')
<p class="source-note">Map coverage: 397 SOI Sangrur source shapes versus 571 Census 2011 villages; 15 duplicate-code shapes remain ambiguous. The later Malerkotla source selection is excluded, so this is not the full historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '054')
<p class="source-note">Map coverage: 141 SOI Barnala source shapes versus 128 Census 2011 villages; 17 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; constituency links below retain their separately documented electoral dates and district scope.</p>
@endif
@if(($data['census_district_code'] ?? '') === '090')
<p class="source-note">Map coverage: three named rural SOI North West shapes; 34 unnamed urban features are excluded from this village map. The 52 Census 2011 villages are a separate historical inventory. The SOI archive uses a later 11-district inventory, compared with nine Census 2011 districts. This is not the full historical district boundary. Boundary edition and accepted identifier crosswalks remain unverified; no population is assigned to shapes. Electoral membership audit remains pending.</p>
@endif
@if(($data['census_district_code'] ?? '') === '091')
<p class="source-note">Map coverage: 15 named rural SOI North shapes; 55 unnamed urban features are excluded. None of these shape codes matches the four North district Census 2011 village codes. The SOI archive uses a later 11-district inventory, compared with nine Census 2011 districts. This is later source coverage, not the historical district boundary or a map of its four Census villages. Boundary edition and accepted identifier crosswalks remain unverified; no population is assigned to shapes. Electoral membership audit remains pending.</p>
@endif
@php
    $haryanaVillageCandidates = array_map(fn (array $row): array => [
        'code' => $row['code'],
        'name' => $row['name'],
        'url' => route('civic.place', ['record' => $row['id']], false),
    ], $data['census_villages'] ?? []);
@endphp
<script type="application/json" id="haryana-map-census-candidates">{!! json_encode($haryanaVillageCandidates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
@endif
