<?php

namespace App\Services;

class DistrictConstituencyLinks
{
    /** @return array<string, mixed> */
    public function forDistrict(string $district, string $state = 'Uttar Pradesh'): array
    {
        $empty = ['available' => false, 'assembly' => [], 'parliamentary' => [], 'sources' => []];
        if (! in_array($state, ['Punjab', 'Tripura', 'Nagaland', 'Mizoram', 'Meghalaya', 'Manipur', 'Arunachal Pradesh', 'Haryana', 'Jharkhand', 'Chhattisgarh', 'Telangana', 'Andhra Pradesh', 'Kerala', 'Uttar Pradesh', 'Karnataka', 'Maharashtra', 'Bihar', 'Tamil Nadu', 'Gujarat', 'Madhya Pradesh', 'West Bengal', 'Rajasthan'], true)) {
            return $empty;
        }
        $path = database_path(match ($state) {
            'Punjab' => 'fixtures/punjab-electoral-geography.json',
            'Manipur' => 'fixtures/manipur-electoral-geography.json',
            'Meghalaya' => 'fixtures/meghalaya-electoral-geography.json',
            'Mizoram' => 'fixtures/mizoram-electoral-geography.json',
            'Nagaland' => 'fixtures/nagaland-electoral-geography.json',
            'Tripura' => 'fixtures/tripura-electoral-geography.json',
            'Arunachal Pradesh' => 'fixtures/arunachal-pradesh-electoral-geography.json',
            'Haryana' => 'fixtures/haryana-electoral-geography.json',
            'Telangana' => 'fixtures/telangana-electoral-geography.json',
            'Jharkhand' => 'fixtures/jharkhand-electoral-geography.json',
            'Chhattisgarh' => 'fixtures/chhattisgarh-electoral-geography.json',
            'Andhra Pradesh' => 'fixtures/andhra-pradesh-electoral-geography.json',
            'Kerala' => 'fixtures/kerala-electoral-geography.json',
            'West Bengal' => 'fixtures/west-bengal-electoral-geography.json',
            'Rajasthan' => 'fixtures/rajasthan-electoral-geography.json',
            'Madhya Pradesh' => 'fixtures/madhya-pradesh-electoral-geography.json',
            'Gujarat' => 'fixtures/gujarat-electoral-geography.json',
            'Tamil Nadu' => 'fixtures/tamil-nadu-electoral-geography.json',
            'Bihar' => 'fixtures/bihar-electoral-geography.json',
            'Maharashtra' => 'fixtures/maharashtra-electoral-geography.json',
            'Karnataka' => 'fixtures/karnataka-electoral-geography.json',
            default => 'fixtures/up-electoral-geography.json',
        });
        if (! is_file($path)) {
            return $empty;
        }
        $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $supplement = $state === 'Punjab' ? ($fixture['supplemental_districts'][$district] ?? null) : null;
        $assembly = collect($fixture['district_rows'])->filter(fn (array $row): bool => $supplement ? in_array($row['code'], $supplement['ac_codes'], true) : strcasecmp($row['context_district'] ?? $row['district'], $district) === 0)->sortBy('code')->values();
        if ($assembly->isEmpty()) {
            return $empty;
        }
        $codes = $assembly->pluck('code')->all();
        $parliamentary = collect($fixture['pcs'])->filter(fn (array $row): bool => count(array_intersect($row['ac_codes'], $codes)) > 0)->sortBy('code')->values()->map(function (array $row) use ($codes, $fixture, $state): array {
            $outside = array_values(array_diff($row['ac_codes'], $codes));
            $row['outside_district'] = collect($fixture['district_rows'])->whereIn('code', $outside)->map(fn (array $seat): array => ['code' => $seat['code'], 'name' => $seat['name'], 'district' => $seat['context_district'] ?? $seat['district']])->values()->all();
            $row['url'] = $this->historyUrl('pc', $row['name'], $state);

            return $row;
        });

        return [
            'available' => true,
            'scope_note' => $state === 'Punjab' ? ($supplement ? 'Assembly associations use the official district listing retrieved 2026-10-13. Parliamentary membership uses ECI 2008. This is not an exhaustive boundary overlay or a Census population crosswalk.' : 'Historical district associations: ECI 2008 uses district boundaries as of 2004-02-15. Later district splits are not backcast. These links are historical geographic context, not a current district seat inventory or a Census population crosswalk.') : null,
            'supplemental_source' => $supplement,
            'assembly' => $assembly->map(fn (array $row): array => $row + ['url' => $this->historyUrl('ac', $row['name'], $state)])->all(),
            'parliamentary' => $parliamentary->all(),
            'sources' => [
                ['label' => $fixture['district_source_label'] ?? 'District–Assembly gazette', 'url' => $fixture['district_url'], 'date' => $fixture['district_source_date'], 'sha256' => $fixture['district_sha256']],
                ['label' => $fixture['pc_source_label'] ?? 'Parliamentary delimitation order', 'url' => $fixture['pc_url'], 'date' => $fixture['pc_source_date'], 'sha256' => $fixture['pc_sha256']],
            ],
        ];
    }

    private function historyUrl(string $kind, string $name, string $state): string
    {
        $name = trim(preg_replace('/\s*\((?:SC|ST)\)\s*$/i', '', $name));

        if ($state === 'Tamil Nadu' && $kind === 'ac' && $name === 'Tiruvottiyur') {
            $name = 'Thiruvottiyur';
        }

        if ($state === 'Punjab' && $name === 'S.A.S.Nagar') {
            $name = 'S.A.S. Nagar';
        }

        return 'https://pollmedia.org'.route('constituency.overview', ['kind' => $kind, 'state' => $state, 'name' => $name], false);
    }
}
