<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CensusProfileSummary
{
    public function series(?object $place = null, array $records = [], int $year = 2011): array
    {
        $source = json_decode(file_get_contents(database_path('fixtures/census-india-decadal.json')), true, flags: JSON_THROW_ON_ERROR);
        $points = [];
        $historySource = null;
        if ($place === null || ($year === 2011 && in_array($place->level, ['STATE', 'DISTRICT'], true) && (empty($records) || $records[0]->residence === 'Total'))) {
            foreach ($source['records'] as $row) {
                if ($row['state_code'] !== ($place?->state_code ?? '00') || $row['district_code'] !== ($place?->level === 'DISTRICT' ? $place->district_code : '000')) {
                    continue;
                }
                $growth = preg_replace('/\s+/u', '', $row['percentage']);
                $points[$row['year']] = ['year' => $row['year'], 'population' => $row['persons'], 'male' => $row['males'], 'female' => $row['females'], 'growth' => is_numeric($growth) ? (float) $growth : null, 'households' => null, 'literates' => null, 'ratio' => $row['males'] > 0 && $row['females'] !== null ? 1000 * $row['females'] / $row['males'] : null, 'notes' => $row['flags']];
                $historySource = $source['source'];
            }
        }
        if ($place && $year === 2011 && in_array($place->level, ['STATE', 'DISTRICT'], true) && (empty($records) || $records[0]->residence === 'Total') && isset($place->edition_id)) {
            $historicalRows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')->where('e.status', 'published')->where('e.source_key', 'like', 'census-a02-%')->where('c.state_code', $place->state_code)->where('c.district_code', $place->district_code)->where('c.level', $place->level)->where('c.residence', 'Total')->select('c.*', 'e.year as reference_year', 'e.source_url')->orderBy('e.year')->get();
            foreach ($historicalRows->groupBy('reference_year') as $referenceYear => $rows) {
                $rows = $rows->filter(fn ($row) => str_contains(json_decode($row->geography, true)['boundary_basis'] ?? '', '2011') && (int) (json_decode($row->geography, true)['year'] ?? 0) === (int) $row->reference_year);
                if ($rows->isEmpty() || $rows->map(fn ($row) => json_decode($row->values, true))->unique()->count() !== 1) {
                    continue;
                }
                $row = $rows->first();
                $values = json_decode($row->values, true);
                $points[$referenceYear] = ['year' => (int) $referenceYear, 'population' => $values['TOT_P'] ?? null, 'male' => $values['TOT_M'] ?? null, 'female' => $values['TOT_F'] ?? null, 'growth' => $points[$referenceYear]['growth'] ?? null, 'households' => null, 'literates' => null, 'ratio' => ($values['TOT_M'] ?? 0) > 0 && isset($values['TOT_F']) ? 1000 * $values['TOT_F'] / $values['TOT_M'] : null, 'notes' => json_decode($row->flags, true) ?? [], 'source_url' => $row->source_url];
                $historySource = ['boundary_basis' => json_decode($row->geography, true)['boundary_basis'], 'url' => $row->source_url];
            }
        }
        if (count($records) === 1) {
            $values = json_decode($records[0]->values, true);
            $point = $points[$year] ?? ['year' => $year, 'growth' => null, 'notes' => []];
            foreach (['population' => 'TOT_P', 'male' => 'TOT_M', 'female' => 'TOT_F', 'households' => 'No_HH', 'literates' => 'P_LIT'] as $key => $field) {
                $point[$key] = $values[$field] ?? $point[$key] ?? null;
            }
            $point['ratio'] = ($point['male'] ?? 0) > 0 && ($point['female'] ?? null) !== null ? 1000 * $point['female'] / $point['male'] : null;
            $point['notes'] = array_unique(array_merge($point['notes'], json_decode($records[0]->flags, true) ?? []));
            $points[$year] = $point;
        }
        ksort($points);
        foreach ($points as $referenceYear => &$point) {
            $previous = $points[$referenceYear - 10] ?? null;
            if ($point['growth'] === null && $previous && isset($point['source_url'], $previous['source_url']) && ($previous['population'] ?? 0) > 0 && $point['population'] !== null) {
                $point['growth'] = 100 * ($point['population'] - $previous['population']) / $previous['population'];
                $point['notes'][] = 'Decadal growth calculated from the two published A-02 population totals on 2011 boundaries.';
            }
        }
        unset($point);
        foreach ($points as &$point) {
            $point['review'] = ! empty($point['notes']);
        }
        unset($point);

        return ['rows' => array_values($points), 'source' => $historySource];
    }

    public function landing(?string $state = null): array
    {
        $edition = DB::table('census_editions')->where('status', 'published')->orderByDesc('year')->first();
        $editionIds = DB::table('census_editions')->where('status', 'published')->where('year', $edition?->year ?? 2011)->pluck('id');
        $districtCounts = DB::table('census_catalogue_rows')->whereIn('edition_id', $editionIds)->where('level', 'DISTRICT')->where('residence', 'Total')->select('edition_id', 'state_code')->selectRaw('COUNT(*) as total')->groupBy('edition_id', 'state_code')->get()->keyBy(fn ($row) => $row->edition_id.':'.$row->state_code);
        $states = DB::table('census_catalogue_rows')->whereIn('edition_id', $editionIds)->where('level', 'STATE')->where('residence', 'Total')->orderBy('name')->get()->groupBy('state_code')->map(fn ($rows) => $rows->sortByDesc(fn ($row) => $districtCounts->get($row->edition_id.':'.$row->state_code)?->total ?? 0)->first())->values();
        $place = $state ? $states->first(fn ($row) => Str::slug(trim(preg_replace('/[\s@*#†‡]+$/u', '', $row->name))) === $state) : null;
        $children = $place ? DB::table('census_catalogue_rows')->where('edition_id', $place->edition_id)->where('state_code', $place->state_code)->where('level', 'DISTRICT')->where('residence', 'Total')->orderBy('name')->get() : $states;
        if ($state && ! $place) {
            $children = collect();
        }

        return ['series' => $state && ! $place ? ['rows' => [], 'source' => null] : $this->series($place, $place ? [$place] : [], (int) ($edition?->year ?? 2011)), 'places' => $children];
    }
}
