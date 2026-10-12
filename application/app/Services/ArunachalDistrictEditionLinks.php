<?php

namespace App\Services;

class ArunachalDistrictEditionLinks
{
    public function laterDistrict(string $district): array
    {
        $sources = json_decode(file_get_contents(database_path('fixtures/arunachal-pradesh-later-electoral-sources.json')), true, 512, JSON_THROW_ON_ERROR);
        $source = collect($sources)->firstWhere('district', $district);
        $historical = ['Upper Siang' => 'East Siang', 'Kurung Kumey' => 'Lower Subansiri', 'Lower Dibang Valley' => 'Dibang Valley', 'Anjaw' => 'Lohit'];
        if (! $source || ! isset($historical[$district])) {
            return [];
        }
        $links = app(DistrictConstituencyLinks::class)->forDistrict($historical[$district], 'Arunachal Pradesh');
        $links['assembly'] = array_values(array_filter($links['assembly'], fn (array $seat): bool => in_array($seat['code'], $source['ac_codes'], true)));
        $links['parliamentary'] = array_values(array_filter($links['parliamentary'], fn (array $pc): bool => $pc['code'] === $source['pc_code']));
        $fixture = json_decode(file_get_contents(database_path('fixtures/arunachal-pradesh-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($links['parliamentary'] as &$pc) {
            $pc['outside_district'] = collect($fixture['district_rows'])->whereIn('code', array_diff($pc['ac_codes'], $source['ac_codes']))->map(fn (array $seat): array => ['code' => $seat['code'], 'name' => $seat['name'], 'district' => $seat['district']])->values()->all();
        }
        unset($pc);
        $flags = implode('; ', $source['review_flags']);
        $links['sources'][] = ['label' => "Official {$district} district list; page update date does not certify territorial effective date. Original historical headings retained; Census boundary correspondence pending. {$flags}", 'url' => $source['url'], 'date' => 'Page last updated '.$source['page_last_updated'].'; territorial effective date unverified', 'sha256' => $source['sha256']];

        return $links;
    }

    public function papumPare(): array
    {
        $links = app(DistrictConstituencyLinks::class)->forDistrict('Lower Subansiri', 'Arunachal Pradesh');
        $links['assembly'] = array_values(array_filter($links['assembly'], fn (array $seat): bool => in_array($seat['code'], [13, 14, 15], true)));
        $fixture = json_decode(file_get_contents(database_path('fixtures/arunachal-pradesh-electoral-geography.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($links['parliamentary'] as &$pc) {
            $pc['outside_district'] = collect($fixture['district_rows'])->whereIn('code', array_diff($pc['ac_codes'], [13, 14, 15]))->map(fn (array $seat): array => ['code' => $seat['code'], 'name' => $seat['name'], 'district' => $seat['district']])->values()->all();
        }
        unset($pc);
        $links['sources'][] = ['label' => 'Official Papum Pare district list associates AC13, AC14, AC15 and PC01; page edition/date unverified. Original 1989 Lower Subansiri headings retained; Census boundary correspondence pending', 'url' => $fixture['papum_pare_source']['url'], 'date' => 'Publication date unverified', 'sha256' => $fixture['papum_pare_source']['sha256']];

        return $links;
    }
}
