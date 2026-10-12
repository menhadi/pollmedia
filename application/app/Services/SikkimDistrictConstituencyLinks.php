<?php

namespace App\Services;

class SikkimDistrictConstituencyLinks
{
    public function forDistrict(string $district): array
    {
        $fixture = json_decode(file_get_contents(database_path('fixtures/sikkim-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
        $assembly = collect($fixture['district_rows'])->filter(fn (array $row): bool => in_array($district, $row['district_memberships'], true))->values();
        $pc = $fixture['pcs'][0];
        $pc['url'] = 'https://pollmedia.org'.route('constituency.overview', ['kind' => 'pc', 'state' => 'Sikkim', 'name' => 'Sikkim'], false);
        $pc['outside_district'] = collect($fixture['district_rows'])->whereNotIn('code', $assembly->pluck('code')->all())->map(fn (array $row): array => ['code' => $row['code'], 'name' => $row['name'], 'district' => implode(', ', $row['district_memberships'])])->values()->all();

        return ['available' => $assembly->isNotEmpty(), 'assembly' => $assembly->map(fn (array $row): array => $row + ['url' => 'https://pollmedia.org'.route('constituency.overview', ['kind' => 'ac', 'state' => 'Sikkim', 'name' => trim(preg_replace('/\s*\((?:BL|SC|ST)\)\s*$/i', '', $row['name']))], false)])->all(), 'parliamentary' => [$pc], 'sources' => [
            ['label' => '2008 Schedule XXV: historical headings, cross-district extents and statewide PC geographical scope; boundary correspondence pending', 'url' => $fixture['district_url'], 'date' => $fixture['district_source_date'], 'sha256' => $fixture['district_sha256']],
            ['label' => 'Official 2024 PC01 and Sangha AC32 identities; Sangha territorial assignment withheld and relationship review pending', 'url' => $fixture['identity_source']['url'], 'date' => $fixture['identity_source']['date'], 'sha256' => $fixture['identity_source']['sha256']],
        ]];
    }
}
