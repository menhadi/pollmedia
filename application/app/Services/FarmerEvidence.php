<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FarmerEvidence
{
    public function collect(array $location, ?array $coordinates): array
    {
        $evidence = [];
        $code = (string) ($location['source']['lgd'] ?? '');
        $manifest = $this->read(public_path('maps/soil-national-20261014/manifest.json'));
        $cycles = $manifest['cycles'] ?? [];
        krsort($cycles);
        foreach (array_slice($cycles, 0, 4, true) as $cycle => $details) {
            $shard = $details['shards'][substr($code, 0, 3)] ?? null;
            $rows = $shard ? $this->read(public_path('maps/soil-national-20261014/'.$shard['file']), $shard['sha256'])['records'] ?? [] : [];
            $hits = array_values(array_filter($rows, fn ($r) => (string) ($r['lgd_village_code'] ?? '') === $code && (string) ($r['source_ids']['state']['code'] ?? '') === (string) ($location['state'] ?? '')));
            $r = count($hits) === 1 ? $hits[0] : null;
            $evidence[] = ['kind' => 'soil', 'status' => $r ? 'available' : 'unavailable', 'scope' => 'Village tested-sample categories, not this farmer’s field', 'cycle' => $cycle, 'retrieved_at' => $r['retrieved_at'] ?? $manifest['retrieved_at'] ?? null, 'counts' => $r['counts'] ?? null, 'source_url' => 'https://soilhealth.dac.gov.in/nutrient-dashboard', 'note' => $r ? 'Reported sample category counts; not laboratory measurements for this field.' : 'No unique linked village report for this cycle.'];
        }
        if (! $cycles) {
            $evidence[] = ['kind' => 'soil', 'status' => 'unavailable', 'note' => 'Stored soil reports are unavailable.'];
        }
        $grid = $coordinates ? $this->read(public_path('maps/imd-rainfall-annual-2023-2024.json')) : [];
        if ($grid && isset($grid['latitude'],$grid['longitude'])) {
            $lat = $this->nearest($grid['latitude'], (float) $coordinates['latitude']);
            $lon = $this->nearest($grid['longitude'], (float) $coordinates['longitude']);
            foreach ($grid['years'] ?? [] as $year => $data) {
                $inside = abs($grid['latitude'][$lat] - $coordinates['latitude']) <= ($grid['spacing'] / 2) && abs($grid['longitude'][$lon] - $coordinates['longitude']) <= ($grid['spacing'] / 2);
                $value = $inside ? ($data['annual_mm'][$lat * count($grid['longitude']) + $lon] ?? null) : null;
                $evidence[] = ['kind' => 'rainfall', 'status' => is_numeric($value) ? 'available' : 'unavailable', 'year' => $year, 'annual_mm' => $value, 'scope' => 'Historical 0.25-degree grid cell at photo location; not a field measurement or forecast', 'source_url' => $data['source']['source_url'] ?? null, 'retrieved_at' => $data['source']['retrieved_at'] ?? null];
            }
        } else {
            $evidence[] = ['kind' => 'rainfall', 'status' => 'unavailable', 'note' => 'No confirmed coordinates or stored annual rainfall grid.'];
        }
        unset($rows, $hits, $grid);
        $registry = $this->registry($code);
        $ids = ($registry['lgd'][$code] ?? []);
        $entry = collect($registry['entries'] ?? [])->first(fn ($e) => isset($location['source']['layer']) ? $e['layer_id'] === $location['source']['layer'] : count($ids) === 1 && $e['layer_id'] === $ids[0]);
        $satellite = ['kind' => 'satellite', 'status' => 'unavailable', 'note' => 'No unique linked stored satellite observation.'];
        if ($entry) {
            try {
                $data = $this->satellite($entry['base'].'comparison.json');
                $layer = collect(app(IndiaVillageMap::class)->catalogue()['layers'])->firstWhere('id', $entry['layer_id']);
                if (($data['geometry_sha256'] ?? null) === $entry['geometry_sha256'] && ($layer['sha256'] ?? null) === $entry['geometry_sha256']) {
                    $index = $location['source']['feature_index'] ?? null;
                    if ($index === null) {
                        $lookup = $this->satellite($entry['base'].'index.json');
                        $matches = $lookup['lgd'][$code] ?? [];
                        $index = count($matches) === 1 ? $matches[0] : null;
                    }
                    $row = $index !== null ? collect($data['villages'])->firstWhere('feature_index', $index) : null;
                    if ($row) {
                        $satellite = ['kind' => 'satellite', 'status' => $row['ndvi_change'] !== null ? 'available' : 'unavailable', 'scope' => 'Dated source village polygon, not the farmer’s field or current crop', 'dates' => $data['dates'], 'before_ndvi' => $row['before_ndvi'], 'after_ndvi' => $row['after_ndvi'], 'ndvi_change' => $row['ndvi_change'], 'paired_coverage_pct' => $row['paired_coverage_pct'], 'retrieved_at' => $data['retrieved_at'], 'source_url' => $data['source'], 'note' => 'Comparison uses the same clear pixels. Missing statistics are withheld; greenness does not establish cause, disease or yield.'];
                    }
                }
            } catch (\Throwable) {
                $satellite['note'] = 'Stored satellite evidence could not be verified or accessed.';
            }
        }
        $evidence[] = $satellite;
        $evidence[] = ['kind' => 'government_guidance', 'status' => 'reference_only', 'source_url' => 'https://agromet.imd.gov.in/', 'note' => 'Consult the current local IMD agromet advisory. No current advisory was retrieved for this assessment.'];
        $evidence[] = ['kind' => 'government_guidance', 'status' => 'reference_only', 'source_url' => 'https://soilhealth.dac.gov.in/', 'note' => 'A field-specific Soil Health Card or laboratory test is needed for measured soil chemistry and fertiliser recommendations.'];

        return $evidence;
    }

    private function read(string $path, ?string $sha = null): array
    {
        if (! is_file($path) || ($sha && ! hash_equals($sha, hash_file('sha256', $path)))) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function registry(string $code): array
    {
        $path = public_path('maps/satellite-registry.json');
        if (! is_file($path)) {
            return [];
        }
        // The national identity dictionaries expand far beyond the PHP request
        // budget. Decode just the small entries array and this village's IDs.
        $raw = file_get_contents($path);
        if (! preg_match('/"entries"\s*:\s*\[/', $raw, $match, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $start = $match[0][1] + strlen($match[0][0]) - 1;
        $depth = 0;
        $quoted = false;
        $escaped = false;
        $end = $start;
        for ($length = strlen($raw); $end < $length; $end++) {
            $char = $raw[$end];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $quoted = false;
                }
            } elseif ($char === '"') {
                $quoted = true;
            } elseif ($char === '[') {
                $depth++;
            } elseif ($char === ']' && --$depth === 0) {
                break;
            }
        }
        $entries = json_decode(substr($raw, $start, $end - $start + 1), true) ?: [];
        $ids = [];
        if (ctype_digit($code) && preg_match('/"lgd"\s*:\s*\{/', $raw, $lgd, PREG_OFFSET_CAPTURE, $end)) {
            $offset = $lgd[0][1] + strlen($lgd[0][0]);
            $limit = strpos($raw, '}', $offset);
            if (preg_match('/"'.preg_quote($code, '/').'"\s*:\s*(\[[^\]]*\])/', substr($raw, $offset, $limit === false ? null : $limit - $offset), $value)) {
                $ids = json_decode($value[1], true) ?: [];
            }
        }

        return ['entries' => $entries, 'lgd' => [$code => $ids]];
    }

    private function nearest(array $values, float $target): int
    {
        $best = 0;
        foreach ($values as $i => $value) {
            if (abs($value - $target) < abs($values[$best] - $target)) {
                $best = $i;
            }
        }

        return $best;
    }

    private function satellite(string $relative): array
    {
        $path = 'public/'.ltrim($relative, '/');
        $storage = app(MapAssetStorage::class);
        if (! $storage->allowed($path)) {
            throw new \RuntimeException('Invalid satellite asset.');
        }
        $file = DB::table('map_storage_files')->where('path_hash', hash('sha256', $path))->first();
        $disk = $file?->profile_id ? $storage->remote($file->profile_id) : $storage->local();
        $raw = $disk->get($file?->profile_id ? $file->object_key : $path);
        if (! $raw || ($file && ! hash_equals($file->sha256, hash('sha256', $raw)))) {
            throw new \RuntimeException('Satellite checksum mismatch.');
        }

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
