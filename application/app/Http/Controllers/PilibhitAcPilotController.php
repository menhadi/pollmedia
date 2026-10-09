<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PilibhitAcPilotController extends Controller
{
    public function index(Request $request): View|StreamedResponse
    {
        $input = $request->validate([
            'q' => 'nullable|string|max:120',
            'status' => 'nullable|in:all,linked_inside,linked_review,map_candidate',
            'reason' => 'nullable|in:all,polling_directory_conflict,directory_map_disagreement,missing_directory_link,corroborated_directory_map',
            'page' => 'nullable|integer|min:1',
            'format' => 'nullable|in:csv',
        ]);
        $filters = ['q' => trim($input['q'] ?? ''), 'status' => $input['status'] ?? 'all', 'reason' => $input['reason'] ?? 'all'];
        $path = public_path('maps/pilibhit-ac-pilot.json');
        abort_unless(is_file($path), 503, 'Pilibhit pilot evidence package is unavailable.');
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $statusLabels = ['linked_inside' => 'Directory linked · map agrees',
            'linked_review' => 'Directory linked · map review', 'map_candidate' => 'Map candidate · link pending'];
        $matches = collect($data['rows'])->filter(fn (array $row): bool => ($filters['status'] === 'all' || $row['status'] === $filters['status']) &&
            ($filters['reason'] === 'all' || $row['review_reason'] === $filters['reason']) &&
            ($filters['q'] === '' || mb_stripos($row['name'].' '.$row['code'], $filters['q']) !== false))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        if (($input['format'] ?? '') === 'csv') {
            return response()->streamDownload(function () use ($matches): void {
                $stream = fopen('php://output', 'w');
                fputcsv($stream, ['Census code', 'Village', 'Evidence status', 'Population 2011', 'Households 2011', 'Literates 2011', 'Population age 7+', 'Land overlap % (not a population weight)', 'Reviewed booth references', 'Scope'], ',', '"', '');
                foreach ($matches as $row) {
                    $values = [$row['code'], $row['name'], $row['status'], $row['census']['population'],
                        $row['census']['households'], $row['census']['literate'],
                        $row['census']['population'] - $row['census']['population_0_6'],
                        $row['land_overlap_percent'] ?? '', implode(';', $row['booths']),
                        'AC127 rural village baseline; Census 2011; LGD checked 2026-09-16; not official AC total'];
                    $safe = array_map(fn (mixed $value): string => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : (string) $value, $values);
                    fputcsv($stream, $safe, ',', '"', '');
                }
                fclose($stream);
            }, 'pilibhit-ac127-village-evidence.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $page = (int) ($input['page'] ?? 1);
        $rows = new LengthAwarePaginator($matches->forPage($page, 25)->values(), $matches->count(), 25, $page,
            ['path' => route('pilibhit-ac-pilot'), 'query' => $filters]);

        return view('pilibhit-ac-pilot', compact('data', 'rows', 'filters', 'statusLabels'));
    }
}
