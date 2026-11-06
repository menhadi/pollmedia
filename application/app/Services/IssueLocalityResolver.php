<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class IssueLocalityResolver
{
    public function resolve(float $latitude, float $longitude): array
    {
        $geometry = app(IssueLocationResolver::class);
        $identity = 'locality:point:'.substr(hash_hmac('sha256', $latitude.','.$longitude, config('app.key')), 0, 32);
        $location = ['name' => 'Detected location', 'identity_key' => $identity, 'scopes' => [], 'notes' => [], 'source' => [], 'state' => '', 'district' => ''];
        $localities = $this->read(public_path('maps/issue-localities.json'));
        $matches = array_values(array_filter($localities['features'] ?? [], fn ($f) => $geometry->contains($f['geometry'] ?? [], $longitude, $latitude)));
        if (count($matches) === 1) {
            $p = $matches[0]['properties'];
            $location['name'] = $p['name'];
            $location['identity_key'] = $p['key'];
            $location['source']['locality'] = $localities['metadata'];
            $location['notes'][] = $localities['metadata']['note'];
            $location['notes'][] = $localities['metadata']['attribution'];
        } else {
            $location['notes'][] = 'Coordinates received. A locality name is not available from the retained maps. You can still report here or change the problem location.';
        }
        $location['scopes'][] = ['key' => $location['identity_key'], 'name' => $location['name'], 'kind' => 'locality'];
        unset($localities);
        $matches = [];
        $index = $this->read(public_path('maps/issue-geography/index.json'));
        foreach ($index['states'] ?? [] as $entry) {
            $b = $entry['bbox'];
            if ($longitude < $b[0] || $longitude > $b[2] || $latitude < $b[1] || $latitude > $b[3]) {
                continue;
            }
            $path = public_path('maps/issue-geography/'.$entry['file']);
            if (! is_file($path) || hash_file('sha256', $path) !== $entry['sha256']) {
                continue;
            }
            $districts = $this->read($path);
            foreach ($districts['features'] as $feature) {
                if ($geometry->contains($feature['geometry'] ?? [], $longitude, $latitude)) {
                    $matches[] = $feature;
                }
            }
            unset($districts);
        }
        if (count($matches) === 1) {
            $p = $matches[0]['properties'];
            $parents = $p['lgd_districts'] ?? [];
            if (count($parents) === 1) {
                $location['state'] = (string) $parents[0]['state'];
                $location['district'] = (string) $parents[0]['district'];
                $directoryPath = app(ElectoralVillageMap::class)->directory().'/links.sqlite';
                $names = Cache::remember('issue-district-name:'.hash('sha256', $directoryPath.filemtime($directoryPath).filesize($directoryPath).':'.$location['state'].':'.$location['district']), 3600, function () use ($location): array {
                    $nameQuery = app(ElectoralVillageMap::class)->database()->prepare('SELECT DISTINCT d.district FROM directory d JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE a.state=? AND a.district=?');
                    $nameQuery->execute([$location['state'], $location['district']]);

                    return $nameQuery->fetchAll(\PDO::FETCH_COLUMN);
                });
                $districtName = count($names) === 1 ? $names[0] : $p['name'];
                $location['scopes'][] = ['key' => 'district:'.$location['state'].':'.$location['district'], 'name' => $districtName, 'kind' => 'district'];
                $location['scopes'][] = ['key' => 'state:'.$location['state'], 'name' => $p['state'], 'kind' => 'state'];
                $location['source']['district'] = ['file' => 'india-district-display.json', 'sha256' => $index['source_sha256'], 'basis' => $p['basis'] ?? null];
                $state = app(ElectoralMapCatalogue::class)->stateFromQuery($p['state']);
                if ($state) {
                    $path = public_path('maps/electoral/'.$state['file']);
                    if (is_file($path) && hash_file('sha256', $path) === $state['sha256']) {
                        $seats = $this->read($path);
                        foreach (['ac', 'pc'] as $kind) {
                            $hits = array_values(array_filter($seats['features'] ?? [], fn ($f) => ($f['properties']['kind'] ?? '') === $kind && $geometry->contains($f['geometry'] ?? [], $longitude, $latitude)));
                            if (count($hits) === 1) {
                                $s = $hits[0]['properties'];
                                $location['scopes'][] = ['key' => $kind.':'.$location['state'].':'.$s['code'], 'name' => $s['name'], 'kind' => $kind];
                            }
                        }
                        $location['source']['electoral'] = ['sha256' => $state['sha256'], 'metadata' => $seats['metadata']];
                        $location['notes'][] = 'AC/PC links use the retained community electoral map (PC reference 2019; AC edition unverified). They are map-based links, not an official current-jurisdiction confirmation.';
                    }
                }
            }
        }
        $location['notes'][] = 'No village boundary is required for this report. Missing or overlapping administrative areas are left unassigned.';

        return $location;
    }

    private function read(string $path): array
    {
        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }
}
