@php
    $gpName = $record['name'] ?? '';
    $firstMember = $members[0] ?? null;
    $gpStateName = $firstMember['state_name'] ?? '';
    $gpDistrictName = $firstMember['district_name'] ?? '';
    $gpIdentity = collect(app(\App\Services\DistrictDashboardUrls::class)->all())
        ->first(fn (array $row): bool => \Illuminate\Support\Str::slug($row['state_name']) === \Illuminate\Support\Str::slug($gpStateName)
            && \Illuminate\Support\Str::slug($row['name']) === \Illuminate\Support\Str::slug($gpDistrictName));
    $gpVillageIds = array_values(array_unique(array_map('strval', array_filter(array_column($members, 'village')))));
    $gpSubdistricts = array_values(array_unique(array_filter(array_column($members, 'subdistrict_name'))));
    $gpPresetSubdistrict = count($gpSubdistricts) === 1 ? $gpSubdistricts[0] : null;
@endphp
@if($gpDistrictName !== '' && $gpName !== '' && $gpVillageIds !== [])
    @include('district-village-map', [
        'villageDistrictName' => $gpDistrictName,
        'villageStateName' => $gpIdentity['state_name'] ?? $gpStateName,
        'villageSubdistrictName' => $gpPresetSubdistrict,
        'villageHeading' => $gpName.' villages',
        'villageDistrictPath' => $gpIdentity['path'] ?? null,
        'villageIds' => $gpVillageIds,
    ])
@endif
