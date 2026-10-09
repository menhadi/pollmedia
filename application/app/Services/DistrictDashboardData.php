<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DistrictDashboardData
{
    public function published(string $districtCode, string $stateCode = '09', int $year = 2011): ?array
    {
        foreach (['census_editions', 'census_publications', 'census_catalogue_rows'] as $table) {
            if (! Schema::hasTable($table)) {
                return null;
            }
        }
        $rows = DB::table('census_catalogue_rows as c')
            ->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->join('census_publications as p', function ($join): void {
                $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
            })
            ->where('e.status', 'published')->where('e.year', $year)
            ->where('c.state_code', $stateCode)->where('c.district_code', $districtCode)
            ->whereNotLike('e.source_key', 'census-a02-%')
            ->select('c.*', 'e.source_url', 'e.source_key', 'e.retrieved_at', 'e.sha256', 'e.landing_url')
            ->orderByDesc('e.retrieved_at')->orderByDesc('e.id')->orderBy('c.id')->get();
        $selected = [];
        foreach ($rows as $row) {
            $geo = json_decode($row->geography, true, flags: JSON_THROW_ON_ERROR);
            $identity = implode(':', [$row->level, $row->residence, $geo['Subdistt'] ?? $geo['TAHSIL'] ?? '', $geo['Town/Village'] ?? $geo['TOWN_VILL'] ?? '']);
            $selected[$identity] ??= $row;
        }
        $totals = $subdistricts = $towns = $villages = [];
        $district = null;
        foreach ($selected as $row) {
            $record = $this->record($row);
            if ($row->level === 'DISTRICT') {
                $totals[$row->residence] = $record;
                if ($row->residence === 'Total') {
                    $district = $row;
                }
            } elseif (in_array($row->level, ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'], true) && $row->residence === 'Total') {
                $subdistricts[] = $record;
            } elseif ($row->level === 'TOWN' && $row->residence === 'Urban') {
                $towns[] = $record;
            } elseif ($row->level === 'VILLAGE' && $row->residence === 'Rural') {
                $villages[] = $record;
            }
        }
        if ($district === null) {
            return null;
        }
        $subdistrictNames = array_column($subdistricts, 'name', 'code');
        foreach ($towns as &$town) {
            $town['subdistrict'] = $subdistrictNames[$town['subdistrict_code']] ?? 'Unavailable';
        }
        unset($town);
        $editions = collect($selected)->unique('edition_id')->map(fn (object $row): array => [
            'id' => $row->edition_id, 'source_key' => $row->source_key, 'year' => $year,
            'source_url' => $row->source_url, 'landing_url' => $row->landing_url,
            'sha256' => $row->sha256, 'retrieved_at' => $row->retrieved_at, 'status' => 'published',
        ])->values()->all();

        return [
            'name' => $district->name, 'year' => $year, 'census_district_code' => $districtCode,
            'totals' => $totals, 'subdistricts' => $subdistricts, 'towns' => $towns,
            'census_villages' => $villages, 'census_village_rows' => count($villages),
            'census_editions' => $editions, 'data_mode' => 'live_published',
            'checked_at' => now()->toIso8601String(),
            'history' => app(CensusProfileSummary::class)->series($district, [$district], $year),
        ];
    }

    private function record(object $row): array
    {
        $geo = json_decode($row->geography, true, flags: JSON_THROW_ON_ERROR);
        $values = json_decode($row->values, true, flags: JSON_THROW_ON_ERROR);
        $subdistrict = (string) ($geo['Subdistt'] ?? $geo['TAHSIL'] ?? '00000');
        $code = in_array($row->level, ['VILLAGE', 'TOWN'], true) ? ($geo['Town/Village'] ?? $geo['TOWN_VILL'] ?? '') : ($row->level === 'DISTRICT' ? $row->district_code : $subdistrict);
        $result = ['id' => $row->id, 'name' => $row->name, 'code' => (string) $code, 'subdistrict_code' => $subdistrict, 'residence' => $row->residence];
        foreach (['population' => 'TOT_P', 'households' => 'No_HH', 'literates' => 'P_LIT', 'children' => 'P_06', 'male' => 'TOT_M', 'female' => 'TOT_F', 'workers' => 'TOT_WORK_P'] as $key => $field) {
            $result[$key] = $values[$field] ?? null;
        }
        foreach (['literacy' => ['P_LIT', 'TOT_P', 'P_06'], 'male_literacy' => ['M_LIT', 'TOT_M', 'M_06'], 'female_literacy' => ['F_LIT', 'TOT_F', 'F_06']] as $key => [$numerator, $total, $children]) {
            $denominator = isset($values[$total], $values[$children]) ? $values[$total] - $values[$children] : null;
            $result[$key] = $denominator > 0 && isset($values[$numerator]) ? round(100 * $values[$numerator] / $denominator, 2) : null;
        }

        return $result + ['source_row' => $row->source_row, 'edition_id' => $row->edition_id, 'source_url' => $row->source_url, 'flags' => json_decode($row->flags, true, flags: JSON_THROW_ON_ERROR)];
    }
}
