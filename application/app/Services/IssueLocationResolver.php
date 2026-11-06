<?php

namespace App\Services;

class IssueLocationResolver
{
    public function resolve(float $latitude, float $longitude): array
    {
        $map = app(IndiaVillageMap::class);
        $electoral = app(ElectoralVillageMap::class);
        $db = $electoral->database();
        $match = $db->prepare('SELECT DISTINCT s.lgd FROM shapes s JOIN layers l ON l.file=s.file WHERE s.file=? AND s.feature_index=? AND l.sha256=?');
        $found = [];
        foreach ($map->catalogue()['layers'] as $layer) {
            $box = $layer['bbox'] ?? [];
            if (count($box) !== 4 || $longitude < $box[0] || $longitude > $box[2] || $latitude < $box[1] || $latitude > $box[3]) {
                continue;
            }
            $source = json_decode(file_get_contents($map->geometryPath($layer)), true, flags: JSON_THROW_ON_ERROR);
            foreach ($source['features'] ?? $source['rows'] ?? [] as $index => $feature) {
                if (! $this->contains($feature['geometry'] ?? [], $longitude, $latitude)) {
                    continue;
                }
                $match->execute([$layer['file'], $index, $layer['sha256']]);
                foreach ($match->fetchAll(\PDO::FETCH_COLUMN) as $code) {
                    $location = $this->village((string) $code);
                    $location['source'] += ['layer' => $layer['id'], 'sha256' => $layer['sha256'], 'feature_index' => $index];
                    $found[(string) $code] = $location;
                }
            }
        }

        return $found ? array_values($found) : [app(IssueLocalityResolver::class)->resolve($latitude, $longitude)];
    }

    public function village(string $code): array
    {
        $connections = app(ElectoralVillageMap::class)->connections($code);
        $a = $connections['administration'] ?? null;
        abort_unless($a, 422, 'This village is not in the available directory.');
        $scopes = [
            ['key' => 'village:'.$code, 'name' => $a['name'], 'kind' => 'village'],
            ['key' => 'state:'.$a['state'], 'name' => $a['state_name'], 'kind' => 'state'],
        ];
        if (! empty($a['district_code'])) {
            $scopes[] = ['key' => 'district:'.$a['state'].':'.$a['district_code'], 'name' => $a['district'], 'kind' => 'district'];
        }
        $notes = [];
        foreach (['ac', 'pc'] as $kind) {
            $seats = array_values(array_filter($connections['constituencies'], fn ($s) => $s['kind'] === $kind));
            if (count($seats) === 1 && ! $seats[0]['partial']) {
                $scopes[] = ['key' => $kind.':'.$a['state'].':'.$seats[0]['code'], 'name' => $seats[0]['name'], 'kind' => $kind];
            } else {
                $notes[] = strtoupper($kind).' is not uniquely established by the available links; no constituency assignment has been guessed.';
            }
        }

        return ['name' => $a['name'], 'scopes' => $scopes, 'notes' => $notes, 'state' => (string) $a['state'], 'district' => (string) $a['district_code'], 'source' => ['lgd' => $code]];
    }

    public function contains(array $geometry, float $x, float $y): bool
    {
        $polygons = match ($geometry['type'] ?? '') {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };
        foreach ($polygons as $rings) {
            if (! $rings || ! $this->ring($rings[0], $x, $y)) {
                continue;
            }
            foreach (array_slice($rings, 1) as $hole) {
                if ($this->ring($hole, $x, $y)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    private function ring(array $ring, float $x, float $y): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
