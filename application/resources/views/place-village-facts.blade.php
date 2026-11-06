@if(isset($village) || (($place->level ?? '') === 'VILLAGE'))
    @php
        $factVillage = is_array($village ?? null) ? $village : [];
        $factCodes = isset($currentRecords) ? collect($currentRecords)->pluck('code')->map(fn ($code) => (string) $code)->unique()->values()->all() : (isset($factVillage['lgd']) ? [(string) $factVillage['lgd']] : []);
        $factCensusCode = isset($place) && is_object($place) ? (string) (json_decode($place->geography, true)['Town/Village'] ?? '') : null;
        $factWater = app(\App\Services\JjmWaterSnapshot::class)->forVillage($factCodes, $factCensusCode);
        if ($factCodes === [] && isset($factWater['lgd_village_code'])) {
            $factCodes = [(string) $factWater['lgd_village_code']];
        }
        $placeFacts = app(\App\Services\PlaceFactTables::class)->village($factVillage, $factWater, $factCodes, isset($place) && is_object($place) ? $place : null, $census ?? null);
    @endphp
    @include('place-fact-tables', ['facts' => $placeFacts])
    @include('rainfall-village-annual')
@endif
