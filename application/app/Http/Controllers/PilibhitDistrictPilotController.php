<?php

namespace App\Http\Controllers;

use App\Services\DistrictConstituencyLinks;
use App\Services\DistrictDashboardData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PilibhitDistrictPilotController extends Controller
{
    public function index(Request $request): View|StreamedResponse|JsonResponse
    {
        $input = $request->validate(['q' => 'nullable|string|max:120', 'subdistrict' => 'nullable|in:all,00790,00791,00792', 'kind' => 'nullable|in:all,village,town', 'status' => 'nullable|in:all,linked,unlinked,ambiguous', 'page' => 'nullable|integer|min:1', 'format' => 'nullable|in:csv,json']);
        $filters = ['q' => trim($input['q'] ?? ''), 'subdistrict' => $input['subdistrict'] ?? 'all', 'kind' => $input['kind'] ?? 'all', 'status' => $input['status'] ?? 'all'];
        $path = public_path('maps/pilibhit-district-pilot.json');
        abort_unless(is_file($path), 503, 'District evidence package unavailable.');
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $live = app(DistrictDashboardData::class)->published('151');
        if ($live) {
            $adapt = fn (array $record): array => $record + ['literate' => $record['literates'], 'population_0_6' => $record['children'], 'literacy_rate' => $record['literacy']];
            $data['totals'] = array_map($adapt, $live['totals']);
            $data['subdistricts'] = array_map($adapt, $live['subdistricts']);
            $publishedPlaces = collect(array_merge($live['census_villages'], $live['towns']))->keyBy('code');
            foreach ($data['rows'] as &$row) {
                if ($publishedPlaces->has($row['code'])) {
                    $row['census'] = $adapt($publishedPlaces->get($row['code']));
                }
            }
            unset($row);
        }
        $history = json_decode(file_get_contents(database_path('fixtures/district-pilot-history.json')), true, 512, JSON_THROW_ON_ERROR);
        $censusSeries = $live['history'] ?? $history['history']['pilibhit'];
        $data['data_mode'] = $live ? 'live_published' : 'local_snapshot';
        $data['history'] = $censusSeries;
        if (($input['format'] ?? '') === 'json') {
            return response()->json($data)->header('Cache-Control', 'no-store');
        }
        $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Pilibhit');
        $matches = collect($data['rows'])->filter(fn (array $row): bool => ($filters['q'] === '' || mb_stripos($row['name'].' '.$row['code'], $filters['q']) !== false) && ($filters['subdistrict'] === 'all' || $filters['subdistrict'] === $row['subdistrict_code']) && ($filters['kind'] === 'all' || $filters['kind'] === $row['kind']) && ($filters['status'] === 'all' || $filters['status'] === $row['status']))->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        if (($input['format'] ?? '') === 'csv') {
            return response()->streamDownload(function () use ($matches): void {
                $stream = fopen('php://output', 'w');
                fputcsv($stream, ['Census code', 'Place', 'Type', 'Census 2011 subdistrict', 'Population 2011', 'Households 2011', 'Literates 2011', 'Population 0–6', 'Current directory link', 'Geometry code link', 'Census source row'], ',', '"', '');
                foreach ($matches as $row) {
                    $values = [$row['code'], $row['name'], $row['kind'], $row['subdistrict'], $row['census']['population'], $row['census']['households'], $row['census']['literate'], $row['census']['population_0_6'], $row['status'], $row['geometry_status'], $row['census']['source_row']];
                    fputcsv($stream, array_map(fn (mixed $value): string => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : (string) $value, $values), ',', '"', '');
                }
                fclose($stream);
            }, 'pilibhit-district-census-2011.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $page = (int) ($input['page'] ?? 1);
        $rows = new LengthAwarePaginator($matches->forPage($page, 25)->values(), $matches->count(), 25, $page, ['path' => route('pilibhit-district-pilot'), 'query' => $filters]);

        return view('pilibhit-district-pilot', compact('data', 'rows', 'filters', 'censusSeries', 'constituencyLinks'));
    }
}
