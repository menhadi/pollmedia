<?php

namespace App\Services;

use PDO;

class WaterMapData
{
    public static function censusIdentities(array $records, array $directory, array $identities = []): array
    {
        $codes = [];
        foreach ($directory as $row) {
            $code = str_pad((string) $row['census2011'], 6, '0', STR_PAD_LEFT);
            $codes[$code][$row['state'].':'.$row['lgd']] = $row;
        }
        foreach ($records as $record) {
            if (($record['level'] ?? '') !== 'VILLAGE' || ($record['residence'] ?? '') !== 'Rural') {
                continue;
            }
            $code = str_pad((string) ($record['census_code'] ?? ''), 6, '0', STR_PAD_LEFT);
            $matches = $codes[$code] ?? [];
            if (count($matches) !== 1) {
                continue;
            }
            $row = reset($matches);
            $states = match ((int) ($record['state_code'] ?? 0)) {
                28 => ['28', '36'], 1 => ['1', '37'], 25, 26 => ['38'],
                default => [(string) (int) ($record['state_code'] ?? 0)],
            };
            if (in_array((string) $row['state'], $states, true)) {
                $identities[] = ['feature_index' => (int) $record['feature_index'], 'lgd' => (string) $row['lgd'], 'state' => (string) $row['state'], 'basis' => 'Published Census 2011 village identity and existing unique official LGD correspondence'];
            }
        }

        return $identities;
    }

    public function publishedIdentities(array $records, PDO $directory, array $identities = []): array
    {
        $codes = array_values(array_unique(array_map(fn (array $row): string => str_pad((string) ($row['census_code'] ?? ''), 6, '0', STR_PAD_LEFT), array_filter($records, fn (array $row): bool => ($row['level'] ?? '') === 'VILLAGE' && ($row['residence'] ?? '') === 'Rural'))));
        $rows = [];
        foreach (array_chunk($codes, 500) as $batch) {
            $query = $directory->prepare('SELECT lgd,state,census2011 FROM directory WHERE census2011 IN ('.implode(',', array_fill(0, count($batch), '?')).')');
            $query->execute($batch);
            array_push($rows, ...$query->fetchAll(PDO::FETCH_ASSOC));
        }

        return self::censusIdentities($records, $rows, $identities);
    }

    public static function uniqueIdentities(array $identities): array
    {
        $groups = [];
        foreach ($identities as $identity) {
            $groups[(int) $identity['feature_index']][(string) $identity['state'].':'.(string) $identity['lgd']] = $identity;
        }

        return array_values(array_map(fn (array $matches): array => reset($matches), array_filter($groups, fn (array $matches): bool => count($matches) === 1)));
    }

    public function records(array $identities): array
    {
        $records = [];
        $pilibhit = null;
        $pilibhitSource = null;
        $nationalPath = storage_path('app/jjm/national/linked.sqlite');
        $national = is_file($nationalPath) ? new PDO('sqlite:'.$nationalPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) : null;
        $national?->exec('PRAGMA query_only=ON');
        $query = $national?->prepare('SELECT payload FROM water WHERE lgd=? AND state=?');
        foreach (self::uniqueIdentities($identities) as $identity) {
            $row = null;
            if ($query) {
                $query->execute([(string) $identity['lgd'], (string) $identity['state']]);
                $payload = $query->fetchColumn();
                $row = $payload ? json_decode($payload, true, flags: JSON_THROW_ON_ERROR) : null;
            }
            if ($row === null && (string) $identity['state'] === '9') {
                $pilibhit ??= app(JjmWaterSnapshot::class)->linked();
                $row = $pilibhit[(string) $identity['lgd']] ?? null;
                if ($row !== null) {
                    $pilibhitSource ??= app(JjmWaterSnapshot::class)->pilibhit();
                    $row += ['state' => 'Uttar Pradesh', 'retrieved_at' => $pilibhitSource['retrieved_at']];
                }
            }
            if ($row !== null) {
                $records[(int) $identity['feature_index']] = $row;
            }
        }

        return $records;
    }

    public static function merge(array $records, array $water, array $identities = []): array
    {
        $result = array_column($records, null, 'feature_index');
        foreach ($identities as $identity) {
            $index = (int) $identity['feature_index'];
            $result[$index] ??= ['feature_index' => $index, 'values' => []];
        }
        foreach ($water as $index => $row) {
            if (($result[$index]['level'] ?? '') === 'TOWN') {
                continue;
            }
            $result[$index] ??= ['feature_index' => (int) $index, 'values' => []];
            $result[$index]['values']['water'] = $row['coverage_pct'];
            $result[$index]['water'] = array_intersect_key($row, array_flip(['jjm_village_id', 'lgd_village_code', 'households', 'tap_connections', 'coverage_pct', 'source_url', 'retrieved_at']));
        }

        $groups = [];
        foreach ($identities as $identity) {
            $groups[$identity['feature_index']][$identity['state'].':'.$identity['lgd']] = true;
        }
        foreach ($result as $index => &$record) {
            if (! isset($record['water'])) {
                $count = count($groups[$index] ?? []);
                $record['water_note'] = ($record['level'] ?? '') === 'TOWN' ? 'JJM unavailable: this snapshot covers rural villages, not towns.' : ($count > 1 ? 'JJM unavailable: conflicting village identities; no water value assigned.' : ($count === 1 ? 'JJM unavailable: no accepted stored JJM observation for this village.' : 'JJM unavailable: no unique official village link for this polygon.'));
            }
        }

        return array_values($result);
    }
}
