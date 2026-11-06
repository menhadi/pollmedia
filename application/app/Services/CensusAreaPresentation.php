<?php

namespace App\Services;

use PDO;

class CensusAreaPresentation
{
    public static function rates(array $values): array
    {
        $result = [];
        foreach (['literacy' => ['TOT_P', 'P_06', 'P_LIT'], 'male_literacy' => ['TOT_M', 'M_06', 'M_LIT'], 'female_literacy' => ['TOT_F', 'F_06', 'F_LIT']] as $key => [$population, $children, $literate]) {
            $denominator = isset($values[$population], $values[$children]) ? $values[$population] - $values[$children] : null;
            $result[$key] = $denominator > 0 && isset($values[$literate]) && $values[$literate] >= 0 && $values[$literate] <= $denominator ? round(100 * $values[$literate] / $denominator, 2) : null;
        }

        return $result;
    }

    /** Aggregate counts only when every expected source district has one agreed value. */
    public static function stateValues(array $original, array $districts, array $expected): array
    {
        $grouped = [];
        foreach ($districts as $district) {
            $grouped[(int) $district['code']][] = $district['values'];
        }
        $expected = array_values(array_unique(array_map('intval', $expected)));
        $fields = array_unique(array_merge(...array_map(fn (array $row): array => array_keys($row['values']), $districts ?: [['values' => []]])));
        $filled = [];
        foreach ($fields as $field) {
            if (isset($original[$field]) || ! preg_match('/^(No_HH|TOT_[PMF]|[PMF]_(06|SC|ST|LIT|ILL)|TOT_WORK_[PMF]|MAINWORK_[PMF]|MARGWORK_[PMF]|NON_WORK_[PMF]|(MAIN|MARG)_[A-Z0-9_]+_[PMF])$/', $field) || $expected === []) {
                continue;
            }
            $sum = 0;
            foreach ($expected as $code) {
                $candidates = [];
                foreach ($grouped[$code] ?? [] as $values) {
                    if (isset($values[$field]) && is_numeric($values[$field]) && $values[$field] >= 0) {
                        $candidates[(string) (float) $values[$field]] = (float) $values[$field];
                    }
                }
                if (count($candidates) !== 1) {
                    continue 2;
                }
                $sum += reset($candidates);
            }
            $original[$field] = $sum;
            $filled[] = $field;
        }

        return ['values' => $original, 'rates' => self::rates($original), 'aggregated_fields' => $filled, 'expected_districts' => count($expected)];
    }

    public function censusMap(object $place, iterable $children, int $year): array
    {
        if ($year !== 2011) {
            return ['reason' => 'A verified polygon correspondence for this Census edition is unavailable. Historical figures remain in the tables.'];
        }
        $rows = $place->level === 'VILLAGE' ? [$place] : $children;
        $codes = [];
        foreach ($rows as $row) {
            if ($row->level !== 'VILLAGE') {
                continue;
            }
            $geography = json_decode($row->geography, true);
            $code = (string) ($geography['Town/Village'] ?? '');
            if (ctype_digit($code) && (int) $code > 0) {
                $codes[] = str_pad($code, 6, '0', STR_PAD_LEFT);
            }
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return ['reason' => 'No corroborated rural village identity is available for this source selection.'];
        }
        $database = app(ElectoralVillageMap::class)->database();
        $matches = [];
        foreach (array_chunk($codes, 500) as $chunk) {
            $query = $database->prepare('SELECT d.lgd,d.state,d.census2011,a.district,a.subdistrict FROM directory d LEFT JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.census2011 IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
            $query->execute($chunk);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((int) $row['state'] === (int) $place->state_code) {
                    $matches[(int) $row['census2011']][$row['state'].':'.$row['lgd']] = $row;
                }
            }
        }
        $accepted = array_map(fn (array $rows): array => reset($rows), array_filter($matches, fn (array $rows): bool => count($rows) === 1));
        if ($accepted === []) {
            return ['reason' => 'No unique official Census-to-LGD village identity is available. Map values remain unavailable.'];
        }
        $parents = array_unique(array_map(fn (array $row): string => $row['state'].':'.$row['district'], $accepted));
        if (count($parents) !== 1) {
            return ['reason' => 'These source villages span conflicting current district identities. A single local map is not assigned.'];
        }
        $first = reset($accepted);
        $subdistricts = array_unique(array_column($accepted, 'subdistrict'));
        $linkedCodes = array_values(array_unique(array_column($accepted, 'lgd')));
        $mapped = 0;
        foreach (array_chunk($linkedCodes, 500) as $chunk) {
            $query = $database->prepare('SELECT COUNT(DISTINCT lgd) FROM shapes WHERE state=? AND lgd IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
            $query->execute([$first['state'], ...$chunk]);
            $mapped += (int) $query->fetchColumn();
        }

        return ['state' => $first['state'], 'district' => $first['district'], 'subdistrict' => count($subdistricts) === 1 ? reset($subdistricts) : '', 'village' => $place->level === 'VILLAGE' ? $first['lgd'] : '', 'codes' => $linkedCodes, 'unlinked' => count($codes) - count($accepted), 'no_geometry' => count($linkedCodes) - $mapped];
    }

    public function lgdMap(string $code): array
    {
        if (! ctype_digit($code)) {
            return ['reason' => 'No unique official village identity is available for this profile.'];
        }
        $database = app(ElectoralVillageMap::class)->database();
        $query = $database->prepare('SELECT state,lgd FROM directory WHERE lgd=?');
        $query->execute([$code]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        return count($rows) === 1 ? ['state' => $rows[0]['state'], 'village' => $code, 'codes' => [$code], 'unlinked' => 0] : ['reason' => 'No unique official village identity is available for this profile.'];
    }
}
