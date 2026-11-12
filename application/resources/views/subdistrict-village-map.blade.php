@php
    $subdistrictName = $place->name ?? '';
    $stateCode = (string) ($place->state_code ?? '');
    $districtCode = (string) ($place->district_code ?? '');
    $districtIdentity = $stateCode !== '' && $districtCode !== ''
        ? app(\App\Services\DistrictDashboardUrls::class)->identity($stateCode, $districtCode)
        : null;
    $subdistrictPages = collect();
    if ($stateCode !== '' && $districtCode !== '') {
        $subdistrictPages = \Illuminate\Support\Facades\DB::table('census_catalogue_rows as c')
            ->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->where('e.status', 'published')
            ->where('e.year', (int) ($year ?? 2011))
            ->whereIn('c.level', ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'])
            ->where('c.state_code', $place->state_code)
            ->where('c.district_code', $place->district_code)
            ->orderBy('c.name')
            ->get(['c.id', 'c.name'])
            ->map(fn ($row): array => [
                'name' => $row->name,
                'url' => app(\App\Services\NamedPlaceUrls::class)->census((int) $row->id),
            ])
            ->values();
    }
@endphp
@if($districtIdentity && $subdistrictName !== '')
    @include('district-village-map', [
        'villageDistrictName' => $districtIdentity['name'],
        'villageStateName' => $districtIdentity['state_name'],
        'villageSubdistrictName' => $subdistrictName,
        'villageHeading' => $subdistrictName.' villages',
        'villageDistrictPath' => $districtIdentity['path'] ?? null,
        'subdistrictPages' => $subdistrictPages,
    ])
@endif
