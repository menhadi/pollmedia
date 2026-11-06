<?php

namespace App\Services;

class GroundwaterEvidence
{
    private array $snapshots = [];

    public function registry(): array
    {
        $path = config('groundwater.registry_path', storage_path('app/groundwater/registry.json'));

        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['districts'] ?? [] : [];
    }

    public function district(string $state, string $district): ?array
    {
        $key = $state.':'.$district;
        if (array_key_exists($key, $this->snapshots)) {
            return $this->snapshots[$key];
        }
        $entry = $this->registry()[$key] ?? null;
        if (! $entry || ! preg_match('/^[a-zA-Z0-9_-]+\.json$/D', $entry['file'] ?? '')) {
            return $this->snapshots[$key] = null;
        }
        $path = dirname(config('groundwater.registry_path', storage_path('app/groundwater/registry.json'))).'/'.$entry['file'];
        if (! is_file($path) || ! hash_equals($entry['sha256'], hash_file('sha256', $path))) {
            return $this->snapshots[$key] = null;
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (($data['state_lgd_code'] ?? null) !== $state || ($data['district_lgd_code'] ?? null) !== $district) {
            return $this->snapshots[$key] = null;
        }
        foreach ($data['stations'] as &$station) {
            $station['state_lgd_code'] = $state;
            $station['district_lgd_code'] = $district;
            $station['district_name'] = $data['district'];
            $station['explorer_url'] = route('water.groundwater', ['state' => $state, 'district' => $district, 'station' => $station['id']]);
            $code = ($station['location_link']['status'] ?? '') === 'supported_location' ? $station['location_link']['lgd'] : null;
            $station['village_url'] = $code ? route('electoral-villages.village', ['code' => $code]) : null;
            $station['my_place_url'] = $code ? url('/my-place').'?'.http_build_query(['state' => $state, 'district' => $district, 'village' => $code]) : null;
        }
        unset($station);

        return $this->snapshots[$key] = $data;
    }

    public function scope(?string $state, ?string $district, ?string $village = null): array
    {
        $stations = $sources = $datasets = [];
        foreach ($this->registry() as $key => $entry) {
            [$s, $d] = explode(':', $key);
            if (($state !== null && $s !== $state) || ($district !== null && $d !== $district)) {
                continue;
            }
            $data = $this->district($s, $d);
            if (! $data) {
                continue;
            }
            $datasets[] = ['state' => $s, 'district' => $d, 'name' => $data['district'], 'built_at' => $data['built_at']];
            foreach ($data['sources'] as $source) {
                $sources[$source['id'].':'.$source['sha256']] = $source;
            }
            foreach ($data['stations'] as $station) {
                $link = $station['location_link'] ?? [];
                if ($village !== null && (($link['status'] ?? '') !== 'supported_location' || ($link['lgd'] ?? '') !== $village || ($link['state'] ?? '') !== $s || ($link['district'] ?? '') !== $d)) {
                    continue;
                }
                $stations[] = $station;
            }
        }

        return ['scope' => $village !== null ? 'village' : ($district !== null ? 'district' : ($state !== null ? 'state' : 'india')),
            'state' => $state, 'district' => $district, 'village' => $village,
            'status' => $stations ? 'available' : 'unavailable', 'stations' => $stations,
            'datasets' => $datasets, 'sources' => array_values($sources),
            'drinking_water_quality' => ['status' => 'unavailable', 'note' => 'No verified WQMIS drinking-water sample records are imported. Groundwater chemistry is not drinking-water certification.'],
            'note' => 'Historical monitoring locations only. No area-wide mean, household supply link or drinking-water safety conclusion is inferred.'];
    }

    public function villageIdentity(string $code, ?string $state = null): ?array
    {
        $path = config('groundwater.directory_path', storage_path('app/electoral-villages/links.sqlite'));
        if (! is_file($path)) {
            return null;
        }
        $db = new \PDO('sqlite:'.$path);
        $query = $db->prepare('SELECT DISTINCT a.state,a.district,a.lgd FROM ancestry a WHERE a.lgd=?'.($state !== null ? ' AND a.state=?' : ''));
        $query->execute($state !== null ? [$code, $state] : [$code]);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function censusIdentity(string $state, string $district): ?array
    {
        $matches = [];
        foreach ($this->registry() as $key => $entry) {
            if (($entry['census_state'] ?? '') === str_pad($state, 2, '0', STR_PAD_LEFT) && ($entry['census_district'] ?? '') === str_pad($district, 3, '0', STR_PAD_LEFT)) {
                [$s, $d] = explode(':', $key);
                $matches[] = ['state' => $s, 'district' => $d];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
