<?php

namespace App\Services;

class PrintableElectionMap
{
    public function paths(string $kind, string $stateName, ?string $selectedName = null): array
    {
        $catalogue = app(ElectoralMapCatalogue::class);
        $state = $catalogue->stateFromQuery($stateName);
        if (! $state) {
            return [];
        }
        $slug = $kind === 'ac' && $state['slug'] === 'telangana' ? 'andhra-pradesh' : $state['slug'];
        $source = $catalogue->all()['states'][$slug];
        $body = file_get_contents(public_path('maps/electoral/'.$source['file']));
        if (! hash_equals($source['sha256'], hash('sha256', $body))) {
            return [];
        }
        $features = array_values(array_filter(json_decode($body, true, flags: JSON_THROW_ON_ERROR)['features'], fn ($feature) => $feature['properties']['kind'] === $kind));
        if (! $features) {
            return [];
        }
        $rings = fn ($feature) => $feature['geometry']['type'] === 'Polygon' ? $feature['geometry']['coordinates'] : array_merge(...$feature['geometry']['coordinates']);
        $points = array_merge(...array_map(fn ($feature) => array_merge(...$rings($feature)), $features));
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $minX = min($xs);
        $maxY = max($ys);
        $cosine = cos(deg2rad(25));
        $width = (max($xs) - $minX) * $cosine;
        $height = $maxY - min($ys);
        $scale = 560 / max($width, $height, 0.001);
        $normalize = fn ($name) => preg_replace('/[^\pL\pN]/u', '', mb_strtolower(preg_replace('/\((?:sc|st)\)/i', '', $name)));
        $selected = array_filter($features, fn ($feature) => $selectedName !== null && $normalize($feature['properties']['name']) === $normalize($selectedName));

        return array_map(function ($feature) use ($rings, $minX, $maxY, $cosine, $width, $height, $scale, $selected): array {
            $path = '';
            foreach ($rings($feature) as $ring) {
                foreach ($ring as $i => $point) {
                    $x = (600 - $width * $scale) / 2 + ($point[0] - $minX) * $cosine * $scale;
                    $y = (600 - $height * $scale) / 2 + ($maxY - $point[1]) * $scale;
                    $path .= ($i === 0 ? 'M' : 'L').round($x, 2).','.round($y, 2).' ';
                }
                $path .= 'Z ';
            }

            return ['name' => $feature['properties']['name'], 'path' => $path, 'selected' => count($selected) === 1 && in_array($feature, $selected, true)];
        }, $features);
    }
}
