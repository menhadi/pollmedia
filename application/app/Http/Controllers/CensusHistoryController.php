<?php

namespace App\Http\Controllers;

use App\Services\CensusHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CensusHistoryController extends Controller
{
    public function show(Request $request, CensusHistory $service): View
    {
        $data = $service->population();
        abort_unless($data, 404, 'Historical Census population data has not been imported.');
        $years = collect($data['rows'])->pluck('year')->unique()->sort()->values()->all();
        $input = $request->validate(['area' => 'nullable|in:total,rural,urban', 'census_year' => ['nullable', 'integer', Rule::in($years)]]);
        $area = $input['area'] ?? 'total';
        $selectedYear = isset($input['census_year']) ? (int) $input['census_year'] : null;
        $series = collect($data['rows'])->where('area', $area)->sortBy('year')->values();
        $rows = $series->filter(fn ($row) => $selectedYear === null || $row['year'] === $selectedYear);

        return view('census-history', compact('data', 'years', 'area', 'selectedYear', 'series', 'rows') + ['has1981' => $service->edition1981() !== null]);
    }

    public function edition1981(Request $request, CensusHistory $service): View
    {
        $data = $service->edition1981();
        abort_unless($data, 404, 'This Census edition has not been imported.');
        $input = $request->validate(['geography' => ['nullable', Rule::in(array_column($data['geographies'], 'key'))], 'area' => 'nullable|in:total,rural,urban']);
        $geography = $input['geography'] ?? 'district';
        $area = $input['area'] ?? 'total';
        $selected = collect($data['geographies'])->firstWhere('key', $geography);
        $rows = collect($data['rows'])->where('geography', $geography)->where('area', $area);
        $villageData = $service->villages1981();
        $villageOptions = collect($villageData['villages'] ?? [])->where('tahsil', $geography)->values();
        $villageInput = $request->validate(['village' => ['nullable', 'string', Rule::in($villageOptions->pluck('code')->all())]]);
        $villageCode = $villageInput['village'] ?? null;
        $villageRows = $villageOptions->filter(fn ($village) => $villageCode === null || $village['code'] === $villageCode);

        return view('census-1981', compact('data', 'geography', 'area', 'selected', 'rows', 'villageData', 'villageOptions', 'villageCode', 'villageRows'));
    }

    public function archive(Request $request, CensusHistory $service): View
    {
        $entries = collect($service->archive());
        $years = $entries->pluck('year')->unique()->sortDesc()->values()->all();
        $input = $request->validate(['census_year' => ['nullable', 'integer', Rule::in($years)]]);
        $selectedYear = isset($input['census_year']) ? (int) $input['census_year'] : null;
        $entries = $entries->filter(fn ($entry) => $selectedYear === null || $entry['year'] === $selectedYear);
        $hasPopulationHistory = $service->population() !== null;

        return view('census-archive', compact('entries', 'years', 'selectedYear', 'hasPopulationHistory') + ['has1981' => $service->edition1981() !== null]);
    }
}
