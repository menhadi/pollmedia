<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class PilibhitDistrictPreviews
{
    public function summaries(): array
    {
        $paths = [
            'rain' => public_path('maps/pilibhit-rainfall.json'),
            'soil' => public_path('maps/pilibhit-soil-2025-26.json'),
            'directory' => database_path('fixtures/pilibhit-lgd.json'),
            'elections' => database_path('fixtures/up-electoral-geography.json'),
        ];
        $versions = array_map(fn ($path) => is_file($path) ? filemtime($path).'-'.filesize($path) : 'missing', $paths);

        return Cache::remember('pilibhit:district-previews:v2:'.implode(':', $versions), now()->addDay(), function () use ($paths) {
            $read = static fn ($path) => is_readable($path) ? json_decode(file_get_contents($path), true) : null;
            $rain = [];
            foreach ($read($paths['rain'])['years'] ?? [] as $year => $report) {
                if (($report['source']['unit'] ?? null) === 'mm' && is_numeric($report['district']['annual_mm'] ?? null)) {
                    $rain[$year] = $report['district']['annual_mm'];
                }
            }
            ksort($rain);

            $soil = $read($paths['soil']);
            $counts = $soil['district_counts'] ?? [];
            $nitrogen = collect(['N_Low', 'N_Medium', 'N_High'])->map(fn ($key) => $counts[$key] ?? null);
            $soilSummary = $nitrogen->every(fn ($count) => is_int($count) && $count >= 0) ? [
                'low' => $counts['N_Low'], 'tested' => $nitrogen->sum(), 'cycle' => $soil['source']['cycle'],
            ] : null;

            $directory = $read($paths['directory']);
            $panchayats = [];
            $villages = [];
            foreach ($directory['villages'] ?? [] as $village) {
                if (($village['district_code'] ?? null) !== '173' || empty($village['code'])) {
                    continue;
                }
                foreach ($village['panchayats'] ?? [] as $gp) {
                    if (! empty($gp['code'])) {
                        $panchayats[$gp['code']] ??= ['code' => $gp['code'], 'name' => $gp['name'], 'villages' => []];
                        $panchayats[$gp['code']]['villages'][$village['code']] = true;
                        $villages[$village['code']] = true;
                    }
                }
            }
            $directoryRows = collect($panchayats)->sortBy([
                ['name', 'asc'], ['code', 'asc'],
            ])->take(5)->map(fn (array $gp) => [
                'code' => $gp['code'], 'name' => $gp['name'], 'villages' => count($gp['villages']),
            ])->values()->all();
            $seats = is_readable($paths['elections']) ? app(DistrictConstituencyLinks::class)->forDistrict('Pilibhit') : [];

            return [
                'rain' => $rain, 'soil' => $soilSummary,
                'directory' => $panchayats ? ['panchayats' => count($panchayats), 'villages' => count($villages), 'date' => $directory['checked_on'], 'rows' => $directoryRows] : null,
                'elections' => empty($seats['available']) ? null : ['assembly' => count($seats['assembly']), 'parliamentary' => count($seats['parliamentary']), 'date' => $seats['sources'][0]['date'],
                    'rows' => collect(['assembly' => 'AC', 'parliamentary' => 'PC'])->flatMap(fn (string $label, string $kind) => collect($seats[$kind])->map(fn (array $seat) => [
                        'type' => $label, 'code' => $seat['code'], 'name' => $seat['name'], 'url' => $seat['url'],
                    ]))->take(5)->values()->all()],
            ];
        });
    }
}
