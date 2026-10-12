<?php

namespace App\Services;

class JharkhandDistrictEditionLinks
{
    public function ramgarh(): array
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Hazaribagh', 'Jharkhand');
        $links['assembly'] = array_values(array_filter($links['assembly'], fn (array $seat): bool => in_array($seat['code'], [22, 23], true)));
        $links['parliamentary'] = array_values(array_filter($links['parliamentary'], fn (array $seat): bool => $seat['code'] === 14));
        $fixture = json_decode(file_get_contents(database_path('fixtures/jharkhand-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($links['parliamentary'] as &$pc) {
            $pc['outside_district'] = collect($fixture['district_rows'])->whereIn('code', array_diff($pc['ac_codes'], [22, 23]))->map(fn (array $seat): array => ['code' => $seat['code'], 'name' => $seat['name'], 'district' => $seat['context_district'] ?? $seat['district']])->values()->all();
        }
        unset($pc);
        $links['sources'][] = ['label' => 'Ramgarh electoral context: ECI fact sheet published by PIB on 4 December 2019 lists AC22 and AC23; original 2008 Hazaribagh headings retained; Census boundary correspondence pending', 'url' => $fixture['ramgarh_2019_source']['url'], 'date' => '2019-12-04', 'sha256' => $fixture['ramgarh_2019_source']['sha256']];

        return $links;
    }

    public function latehar(): array
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Palamu', 'Jharkhand');
        $links['assembly'] = array_values(array_filter($links['assembly'], fn (array $seat): bool => in_array($seat['code'], [73, 74], true)));
        $links['parliamentary'] = array_values(array_filter($links['parliamentary'], fn (array $seat): bool => $seat['code'] === 4));
        $fixture = json_decode(file_get_contents(database_path('fixtures/jharkhand-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($links['parliamentary'] as &$pc) {
            $pc['outside_district'] = collect($fixture['district_rows'])->whereIn('code', array_diff($pc['ac_codes'], [73, 74]))->map(fn (array $seat): array => ['code' => $seat['code'], 'name' => $seat['name'], 'district' => $seat['context_district'] ?? $seat['district']])->values()->all();
        }
        unset($pc);
        $links['sources'][] = ['label' => 'Latehar official district election context: 2019 reference; original 2008 Palamau district headings retained; Census boundary correspondence pending', 'url' => 'https://latehar.nic.in/lok-sabha-election-2019/', 'date' => '2019 election reference; exact publication date unverified', 'sha256' => $fixture['latehar_2019_source']['sha256']];

        return $links;
    }
}
