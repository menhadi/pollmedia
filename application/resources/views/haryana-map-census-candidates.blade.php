@if(in_array([($data['census_district_code'] ?? ''), trim($data['name'])], [['069', 'Panchkula'], ['070', 'Ambala'], ['071', 'Yamunanagar'], ['072', 'Kurukshetra'], ['073', 'Kaithal'], ['074', 'Karnal'], ['075', 'Panipat'], ['076', 'Sonipat'], ['077', 'Jind'], ['078', 'Fatehabad'], ['079', 'Sirsa'], ['080', 'Hisar'], ['081', 'Bhiwani'], ['082', 'Rohtak'], ['083', 'Jhajjar'], ['084', 'Mahendragarh'], ['085', 'Rewari'], ['086', 'Gurgaon'], ['087', 'Mewat'], ['088', 'Faridabad'], ['089', 'Palwal'], ['035', 'Gurdaspur'], ['036', 'Kapurthala']], true))
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
<p class="source-note">Map coverage: 1,208 valid SOI Gurdaspur source shapes versus 1,595 Census 2011 villages. Two invalid source shapes are excluded without repair; 65 duplicate-code shapes remain ambiguous. The later Pathankot source selection is outside this map. This is not the full historical district boundary. Source editions and village crosswalks remain unverified; district constituency links await a dated electoral source audit.</p>
@endif
@if(($data['census_district_code'] ?? '') === '036')
<p class="source-note">Map coverage: 651 SOI Kapurthala source shapes versus 688 Census 2011 villages; 27 duplicate-code shapes remain ambiguous. These inventories do not establish complete village coverage or a historical district boundary. Source editions and village crosswalks remain unverified; district constituency links await a dated electoral source audit.</p>
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
