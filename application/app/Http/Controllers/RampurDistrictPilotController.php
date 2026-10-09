<?php

namespace App\Http\Controllers;

use App\Services\DistrictConstituencyLinks;
use App\Services\DistrictDashboardData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RampurDistrictPilotController extends Controller
{
    public function index(Request $request): View|StreamedResponse|JsonResponse
    {
        $input = $request->validate(['q' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1', 'format' => 'nullable|in:csv,json']);
        $path = public_path('maps/rampur-district-pilot.json');
        abort_unless(is_file($path), 503, 'Rampur imported evidence package unavailable.');
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $live = app(DistrictDashboardData::class)->published('136');
        $data = array_replace($data, $live ?? []);
        $data['data_mode'] = $live ? 'live_published' : 'local_snapshot';
        $history = json_decode(file_get_contents(database_path('fixtures/district-pilot-history.json')), true, 512, JSON_THROW_ON_ERROR);
        $censusSeries = $live['history'] ?? $history['history']['rampur'];
        $data['history'] = $censusSeries;
        if (($input['format'] ?? '') === 'json') {
            return response()->json($data)->header('Cache-Control', 'no-store');
        }
        $constituencyLinks = app(DistrictConstituencyLinks::class)->forDistrict('Rampur');
        $q = trim($input['q'] ?? '');
        $matches = collect($data['lgd_villages'])->filter(fn (array $row): bool => $q === '' || mb_stripos($row['name'].' '.$row['subdistrict'].' '.implode(' ', array_column($row['identifiers'], 'code')), $q) !== false)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        if (($input['format'] ?? '') === 'csv') {
            return response()->streamDownload(function () use ($matches): void {
                $stream = fopen('php://output', 'w');
                fputcsv($stream, ['LGD village', 'LGD subdistrict', 'LGD codes', 'Reference date', 'Village population'], ',', '"', '');
                foreach ($matches as $row) {
                    $values = [$row['name'], $row['subdistrict'], implode(';', array_column($row['identifiers'], 'code')), '2026-09-16', 'Unavailable: village Census rows not imported'];
                    fputcsv($stream, array_map(fn (mixed $value): string => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : (string) $value, $values), ',', '"', '');
                }
                fclose($stream);
            }, 'rampur-imported-lgd-villages.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $page = (int) ($input['page'] ?? 1);
        $rows = new LengthAwarePaginator($matches->forPage($page, 25)->values(), $matches->count(), 25, $page, ['path' => route('rampur-district-pilot'), 'query' => ['q' => $q]]);

        return view('rampur-district-pilot', compact('data', 'rows', 'q', 'censusSeries', 'constituencyLinks'));
    }
}
