<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

class VillageMapMetrics
{
    public static function withIdentities(array $records, array $identities): array
    {
        $result = array_column($records, null, 'feature_index');
        $groups = [];
        foreach ($identities as $identity) {
            $groups[(int) $identity['feature_index']][(string) $identity['lgd']] = $identity;
        }
        foreach ($groups as $index => $matches) {
            if (count($matches) !== 1 || ($result[$index]['level'] ?? null) === 'TOWN') {
                continue;
            }
            $identity = reset($matches);
            if (isset($result[$index]['lgd_code']) && (string) $result[$index]['lgd_code'] !== (string) $identity['lgd']) {
                continue;
            }
            $result[$index] ??= ['feature_index' => $index, 'values' => []];
            $result[$index]['lgd_code'] = (string) $identity['lgd'];
            $result[$index]['state_lgd_code'] = (string) $identity['state'];
        }

        return array_values($result);
    }

    public static function values(array $values): array
    {
        $number = fn ($value): ?float => is_numeric($value) && is_finite((float) $value) && $value >= 0 ? (float) $value : null;
        $population = $number($values['TOT_P'] ?? null);
        $households = $number($values['No_HH'] ?? null);
        $children = $number($values['P_06'] ?? null);
        $literate = $number($values['P_LIT'] ?? null);
        $denominator = $population !== null && $children !== null ? $population - $children : null;

        $metrics = [];
        foreach ($values as $key => $value) {
            $metrics['c11_'.$key] = $number($value);
        }

        return array_merge($metrics, ['population' => $population, 'households' => $households,
            'literacy' => $denominator > 0 && $literate !== null && $literate <= $denominator ? round(100 * $literate / $denominator, 2) : null]);
    }

    public function layer(string $id, string $sha, int $year = 2011): array
    {
        abort_unless($year === 2011, 422, 'This map has verified Census 2011 crosswalks. Another edition requires its own verified crosswalk.');
        $map = app(IndiaVillageMap::class);
        $path = app(ElectoralVillageMap::class)->directory().'/links.sqlite';
        $links = is_file($path) ? app(ElectoralVillageMap::class)->database() : null;
        $links?->exec('PRAGMA query_only=ON');
        if (strlen($id) === 20) {
            $layer = $map->layer($id);
        } else {
            abort_unless($links, 404);
            $layer = collect($links->query('SELECT * FROM layers')->fetchAll(PDO::FETCH_ASSOC))->first(fn (array $row): bool => hash('sha256', $row['file']) === $id);
            abort_unless($layer, 404);
        }
        abort_unless(hash_equals($layer['sha256'], $sha), 409, 'The geometry edition changed. Reload the map.');
        $nationalLayer = strlen($id) === 20 ? $layer : collect($map->catalogue()['layers'])->first(fn (array $row): bool => $row['file'] === $layer['file'] && hash_equals($row['sha256'], $sha));
        // Read the existing geometry only to verify its immutable source hash.
        $geometryPath = strlen($id) === 20 ? $map->geometryPath($layer) : app(ElectoralVillageMap::class)->geometryPath($id);
        $result = ['year' => $year, 'layer' => $id, 'geometry_sha256' => $sha, 'records' => [],
            'definition' => 'Literacy: literate persons / population aged 7 and above × 100. Values describe dated Census records, not polygon area estimates.'];
        if ($links) {
            $statement = $links->prepare('SELECT s.feature_index,s.lgd,s.state,s.basis,d.census2011 FROM shapes s JOIN layers l ON l.file=s.file JOIN directory d ON d.lgd=s.lgd AND d.state=s.state WHERE s.file=? AND l.sha256=?');
            $statement->execute([$layer['file'], $sha]);
            $identities = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (Schema::hasTable('census_publications')) {
                foreach (collect($identities)->groupBy('state') as $state => $villages) {
                    $states = match ((string) $state) {
                        '36' => ['28'], '37' => ['01'], '38' => ['25', '26'],
                        default => [str_pad((string) $state, 2, '0', STR_PAD_LEFT)],
                    };
                    $codes = $villages->pluck('census2011')->filter(fn ($code): bool => preg_match('/^[0-9]{1,6}$/', (string) $code) && (int) $code > 0 && (int) $code < 999999)
                        ->map(fn ($code): string => str_pad((string) $code, 6, '0', STR_PAD_LEFT))->unique()->values()->all();
                    if ($codes === []) {
                        continue;
                    }
                    $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                        ->join('census_publications as p', function ($join): void {
                            $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
                        })->where('e.status', 'published')->where('e.year', $year)->whereIn('c.state_code', $states)
                        ->where('c.level', 'VILLAGE')->where('c.residence', 'Rural')->whereIn('c.geography->Town/Village', $codes)
                        ->select('c.*', 'e.source_key', 'e.source_url', 'e.sha256 as source_sha256')
                        ->orderByDesc('e.retrieved_at')->orderByDesc('e.id')->get()
                        ->groupBy(fn (object $row): string => (string) json_decode($row->geography, true)['Town/Village']);
                    foreach ($villages as $village) {
                        $code = str_pad((string) $village['census2011'], 6, '0', STR_PAD_LEFT);
                        $matches = $rows->get($code, collect());
                        $geographies = $matches->map(function (object $row): string {
                            $geo = json_decode($row->geography, true);

                            return implode(':', [$row->state_code, $row->district_code, $geo['Subdistt'] ?? '', $geo['Town/Village']]);
                        })->unique();
                        if ($geographies->count() !== 1) {
                            continue;
                        }
                        $row = $matches->first();
                        $result['records'][] = ['feature_index' => (int) $village['feature_index'], 'lgd_code' => $village['lgd'], 'census_code' => $code,
                            'record_id' => $row->id, 'record_key' => $row->record_key, 'edition_id' => $row->edition_id, 'source_row' => $row->source_row,
                            'source_key' => $row->source_key, 'source_sha256' => $row->source_sha256, 'source_url' => $row->source_url,
                            'url' => route('electoral-villages.village', ['code' => $village['lgd']]), 'census_url' => route('civic.place', ['record' => $row->id]),
                            'flags' => json_decode($row->flags, true), 'values' => self::values(json_decode($row->values, true))];
                    }
                }
            }
        }
        if ($links && str_ends_with($layer['file'], '.json')) {
            $source = json_decode(file_get_contents($geometryPath), true, flags: JSON_THROW_ON_ERROR);
            $existing = array_column($result['records'], null, 'feature_index');
            foreach ($identities as $village) {
                $index = (int) $village['feature_index'];
                $row = $source['rows'][$index] ?? null;
                if (isset($existing[$index]) || ! $row || ($source['year'] ?? null) !== 2011 || ($row['geometry_status'] ?? null) !== 'code_linked' || (string) $row['code'] !== (string) $village['census2011'] || ! isset($row['census'])) {
                    continue;
                }
                $census = $row['census'];
                $result['records'][] = ['feature_index' => $index, 'lgd_code' => $village['lgd'], 'census_code' => $row['code'],
                    'source_row' => $census['source_row'] ?? null, 'source_url' => $source['sources']['census'] ?? null,
                    'source_sha256' => $source['sources']['census_sha256'] ?? null,
                    'url' => route('electoral-villages.village', ['code' => $village['lgd']]),
                    'values' => self::values(['TOT_P' => $census['population'] ?? null, 'No_HH' => $census['households'] ?? null, 'P_LIT' => $census['literate'] ?? null, 'P_06' => $census['population_0_6'] ?? null])];
            }
        }
        if ($nationalLayer && str_ends_with($geometryPath, '.geojson')) {
            $geometry = json_decode(file_get_contents($geometryPath), true, flags: JSON_THROW_ON_ERROR);
            $properties = array_map(fn (array $feature): array => $feature['properties'], $geometry['features']);
            $existing = array_column($result['records'], null, 'feature_index');
            foreach (app(VillageCensusRecords::class)->sourceMatches($nationalLayer['state'], $properties, $nationalLayer) as $index => $row) {
                if (isset($existing[$index])) {
                    if ($row->level !== 'TOWN') {
                        continue;
                    }
                    $result['records'] = array_values(array_filter($result['records'], fn (array $record): bool => $record['feature_index'] !== (int) $index));
                }
                $result['records'][] = ['feature_index' => (int) $index, 'record_id' => $row->id, 'record_key' => $row->record_key,
                    'level' => $row->level, 'residence' => $row->residence, 'state_code' => $row->state_code, 'district_code' => $row->district_code,
                    'census_name' => $row->name,
                    'original_lgd_village_code' => $existing[$index]['lgd_code'] ?? null,
                    'census_code' => json_decode($row->geography, true)['Town/Village'], 'edition_id' => $row->edition_id,
                    'source_row' => $row->source_row, 'source_key' => $row->source_key, 'source_url' => $row->source_url,
                    'source_sha256' => $row->source_sha256, 'url' => route('civic.place', ['record' => $row->id]),
                    'link_basis' => $row->map_link_basis ?? 'Unique Census 2011 code and normalized settlement name; boundary edition unverified',
                    'values' => self::values(json_decode($row->values, true))];
            }
        }
        if (($nationalLayer['state'] ?? '') === 'Uttar Pradesh' && ($nationalLayer['district'] ?? '') === 'Pilibhit') {
            $database = new PDO('sqlite:'.$map->directory().'/villages.sqlite');
            $database->exec('PRAGMA query_only=ON');
            $statement = $database->prepare('SELECT * FROM places WHERE layer=?');
            $statement->execute([$nationalLayer['id'] ?? $id]);
            $resolver = app(VillageProfileLinks::class);
            $existing = array_column($result['records'], null, 'feature_index');
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $place) {
                if (isset($existing[$place['feature_index']])) {
                    continue;
                }
                $profile = $resolver->forShape($place, $nationalLayer, true, false);
                if (! isset($profile['census'])) {
                    continue;
                }
                $census = $profile['census'];
                $result['records'][] = ['feature_index' => (int) $place['feature_index'], 'place_id' => $place['id'], 'soi_source_id' => $place['source_id'],
                    'census_code' => $place['code'], 'url' => $profile['url'], 'source_release_id' => $census['source_release_id'] ?? null,
                    'source_sha256' => $census['source_sha256'] ?? null, 'source_url' => $census['source_url'] ?? null,
                    'values' => self::values(['TOT_P' => $census['population'], 'No_HH' => $census['households'], 'P_LIT' => $census['literate'], 'P_06' => $census['children'] ?? null])];
            }
        }

        $identities = $links ? app(WaterMapData::class)->publishedIdentities($result['records'], $links, $identities ?? []) : ($identities ?? []);
        $result['records'] = WaterMapData::merge($result['records'], app(WaterMapData::class)->records($identities), $identities);
        $result['records'] = self::withIdentities($result['records'], $identities ?? []);

        return $result;
    }
}
