<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class JjmWaterSnapshot
{
    public function pilibhit(): array
    {
        $release = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
            ->where('s.key', 'jjm-pilibhit-j1')->where('r.status', 'accepted')->orderByDesc('r.retrieved_at')->first(['r.id', 'r.payload']);
        abort_unless($release, 503, 'The water report is temporarily unavailable.');
        $snapshot = self::validate(json_decode($release->payload, true, flags: JSON_THROW_ON_ERROR));
        $snapshot['source_release_id'] = $release->id;

        return $snapshot;
    }

    public static function linkRows(array $snapshot, array $mapping): array
    {
        $pairs = $lgdIds = [];
        foreach ($mapping['rows'] as $pair) {
            $jjm = $pair['jjm_village_id'];
            $lgd = $pair['lgd_village_id'];
            if (! is_string($jjm) || ! ctype_digit($jjm) || ! is_string($lgd) || ! ctype_digit($lgd) || isset($pairs[$jjm]) || isset($lgdIds[$lgd]) || $pair['state'] !== 'Uttar Pradesh' || $pair['district'] !== 'Pilibhit') {
                throw new \UnexpectedValueException('Ambiguous or invalid official water mapping.');
            }
            $pairs[$jjm] = $pair;
            $lgdIds[$lgd] = true;
        }
        $linked = [];
        foreach (self::validate($snapshot)['rows'] as $row) {
            if (isset($pairs[$row['jjm_village_id']])) {
                $row['lgd_village_code'] = $pairs[$row['jjm_village_id']]['lgd_village_id'];
                $row['link_status'] = 'official_live_mapping';
                $linked[$row['lgd_village_code']] = $row;
            }
        }

        return $linked;
    }

    public function linked(): array
    {
        $mapping = json_decode(file_get_contents(database_path('fixtures/jjm-pilibhit-lgd-b37a-20261013.json')), true, flags: JSON_THROW_ON_ERROR);

        return self::linkRows($this->pilibhit(), $mapping);
    }

    public function forVillage(array $lgdCodes, ?string $censusCode = null): ?array
    {
        $database = app(ElectoralVillageMap::class)->database();
        if ($censusCode !== null && $lgdCodes === []) {
            $query = $database->prepare('SELECT lgd FROM directory WHERE census2011=?');
            $query->execute([$censusCode]);
            $lgdCodes = $query->fetchAll(\PDO::FETCH_COLUMN);
        }
        $lgdCodes = array_values(array_unique(array_map('strval', $lgdCodes)));
        if (count($lgdCodes) !== 1) {
            return null;
        }
        $query = $database->prepare('SELECT lgd,state,0 AS feature_index FROM directory WHERE lgd=?');
        $query->execute([$lgdCodes[0]]);
        $identity = $query->fetch(\PDO::FETCH_ASSOC);

        return $identity ? (app(WaterMapData::class)->records([$identity])[0] ?? null) : null;
    }

    public function polygonValues(string $file): array
    {
        $database = app(ElectoralVillageMap::class)->database();
        $query = $database->prepare('SELECT s.feature_index,s.lgd,l.sha256 FROM shapes s JOIN layers l ON l.file=s.file JOIN directory d ON d.lgd=s.lgd AND d.state=s.state WHERE s.file=? AND s.state=?');
        $query->execute([$file, '9']);
        $linked = $this->linked();
        $values = [];
        $path = public_path($file);
        $sha = is_file($path) ? hash_file('sha256', $path) : '';
        $geometry = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        if (isset($geometry['rows'])) {
            $known = array_fill_keys($database->query("SELECT lgd FROM directory WHERE state='9'")->fetchAll(\PDO::FETCH_COLUMN), true);
            $index = 0;
            foreach ($geometry['rows'] as $row) {
                if (empty($row['geometry'])) {
                    continue;
                }
                $codes = array_values(array_unique(array_column($row['directory_links'] ?? [], 'code')));
                if (count($codes) === 1 && isset($known[$codes[0]], $linked[$codes[0]])) {
                    $values[$index] = $linked[$codes[0]];
                }
                $index++;
            }

            return $values;
        }

        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $shape) {
            if (hash_equals($shape['sha256'], $sha) && isset($linked[$shape['lgd']])) {
                $values[(int) $shape['feature_index']] = $linked[$shape['lgd']];
            }
        }

        return $values;
    }

    public static function validate(array $snapshot): array
    {
        $ids = [];
        $households = $connections = 0;
        foreach ($snapshot['rows'] as $row) {
            $id = $row['jjm_village_id'];
            if (! is_string($id) || ! ctype_digit($id) || isset($ids[$id]) || $row['households'] < 0 || $row['tap_connections'] < 0 || $row['tap_connections'] > $row['households']) {
                throw new \UnexpectedValueException('Invalid or duplicate JJM source row.');
            }
            if ($row['lgd_village_code'] !== null || $row['census2011_code'] !== null || $row['link_status'] !== 'official_crosswalk_pending') {
                throw new \UnexpectedValueException('This release has no verified village identifier crosswalk.');
            }
            $ids[$id] = true;
            $households += $row['households'];
            $connections += $row['tap_connections'];
        }
        if (count($ids) !== $snapshot['summary']['villages'] || $households !== $snapshot['summary']['households'] || $connections !== $snapshot['summary']['tap_connections']) {
            throw new \UnexpectedValueException('JJM source totals do not reconcile.');
        }

        return $snapshot;
    }
}
