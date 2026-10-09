<?php

namespace App\Services;

class DistrictConstituencyLinks
{
    /** @return array<string, mixed> */
    public function forDistrict(string $district, string $state = 'Uttar Pradesh'): array
    {
        $empty = ['available' => false, 'assembly' => [], 'parliamentary' => [], 'sources' => []];
        if ($state !== 'Uttar Pradesh') {
            return $empty;
        }
        $path = database_path('fixtures/up-electoral-geography.json');
        if (! is_file($path)) {
            return $empty;
        }
        $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $assembly = collect($fixture['district_rows'])->filter(fn (array $row): bool => strcasecmp($row['district'], $district) === 0)->sortBy('code')->values();
        if ($assembly->isEmpty()) {
            return $empty;
        }
        $codes = $assembly->pluck('code')->all();
        $parliamentary = collect($fixture['pcs'])->filter(fn (array $row): bool => count(array_intersect($row['ac_codes'], $codes)) > 0)->sortBy('code')->values()->map(function (array $row) use ($codes, $fixture, $state): array {
            $outside = array_values(array_diff($row['ac_codes'], $codes));
            $row['outside_district'] = collect($fixture['district_rows'])->whereIn('code', $outside)->map(fn (array $seat): array => ['code' => $seat['code'], 'name' => $seat['name'], 'district' => $seat['district']])->values()->all();
            $row['url'] = $this->historyUrl('pc', $row['name'], $state);

            return $row;
        });

        return [
            'available' => true,
            'assembly' => $assembly->map(fn (array $row): array => $row + ['url' => $this->historyUrl('ac', $row['name'], $state)])->all(),
            'parliamentary' => $parliamentary->all(),
            'sources' => [
                ['label' => 'District–Assembly gazette', 'url' => $fixture['district_url'], 'date' => $fixture['district_source_date'], 'sha256' => $fixture['district_sha256']],
                ['label' => 'Parliamentary delimitation order', 'url' => $fixture['pc_url'], 'date' => $fixture['pc_source_date'], 'sha256' => $fixture['pc_sha256']],
            ],
        ];
    }

    private function historyUrl(string $kind, string $name, string $state): string
    {
        $name = trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i', '', $name));

        return 'https://pollmedia.org'.route('constituency.overview', ['kind' => $kind, 'state' => $state, 'name' => $name], false);
    }
}
