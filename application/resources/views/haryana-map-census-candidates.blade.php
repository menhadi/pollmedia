@if(in_array([($data['census_district_code'] ?? ''), $data['name']], [['069', 'Panchkula'], ['070', 'Ambala'], ['071', 'Yamunanagar'], ['072', 'Kurukshetra'], ['073', 'Kaithal'], ['074', 'Karnal'], ['075', 'Panipat'], ['076', 'Sonipat'], ['077', 'Jind'], ['078', 'Fatehabad']], true))
@php
    $haryanaVillageCandidates = array_map(fn (array $row): array => [
        'code' => $row['code'],
        'name' => $row['name'],
        'url' => route('civic.place', ['record' => $row['id']], false),
    ], $data['census_villages'] ?? []);
@endphp
<script type="application/json" id="haryana-map-census-candidates">{!! json_encode($haryanaVillageCandidates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
@endif
