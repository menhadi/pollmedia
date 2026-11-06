<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;

class PageMarketContext
{
    public function censusDistrict(string $state, string $district): array
    {
        try {
            $version = DB::table('census_editions')->where('status', 'published')->where('year', 2011)
                ->selectRaw('COUNT(*) as editions, MAX(id) as latest, MAX(updated_at) as updated')->first();
            $path = app(ElectoralVillageMap::class)->directory().'/links.sqlite';
            $key = 'page-market-district:v1:'.hash('sha256', json_encode([$state, $district, $version, filemtime($path), filesize($path)]));

            return Cache::remember($key, 3600, function () use ($state, $district): array {
                $urban = json_decode(file_get_contents(database_path('fixtures/lgd-urban-districts.json')), true)['districts'] ?? [];
                $parents = array_values(array_filter($urban, fn ($row) => (int) ($row['census_state'] ?? 0) === (int) $state && (int) ($row['census_district'] ?? 0) === (int) $district));
                if (count($parents) === 1) {
                    return $this->result([['state' => $parents[0]['state'], 'district' => $parents[0]['code']]], 'official_district_correspondence');
                }
                $geographies = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                    ->where('e.status', 'published')->where('e.year', 2011)->where('c.level', 'VILLAGE')
                    ->whereIn('c.state_code', array_unique([$state, (string) (int) $state, str_pad($state, 2, '0', STR_PAD_LEFT)]))
                    ->whereIn('c.district_code', array_unique([$district, (string) (int) $district, str_pad($district, 3, '0', STR_PAD_LEFT)]))
                    ->pluck('c.geography');
                $codes = $geographies->map(fn ($geography) => (string) (json_decode($geography, true)['Town/Village'] ?? ''))->all();

                return $this->censusCodes($codes);
            });
        } catch (\Throwable) {
            return $this->result([], 'identity_unavailable');
        }
    }

    public function censusVillage(int $year, string $code): array
    {
        return $year === 2011 ? $this->censusCodes([$code]) : $this->result([], 'historical_correspondence_unavailable');
    }

    public function placeIdentifiers(int $place): array
    {
        $identifiers = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
            ->where('i.place_id', $place)->where('r.status', 'accepted')->whereIn('i.namespace', ['lgd:IN:village', 'lgd:IN:district'])->select('i.namespace', 'i.code')->distinct()->get();
        $villages = $identifiers->where('namespace', 'lgd:IN:village')->pluck('code')->all();
        if ($villages) {
            return $this->lgdVillages($villages);
        }
        $parents = [];
        foreach ($identifiers->where('namespace', 'lgd:IN:district') as $identity) {
            $query = app(MyPlaceDirectory::class)->database()->prepare('SELECT DISTINCT state,district FROM ancestry WHERE district=?');
            $query->execute([$identity->code]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) {
                $parents[] = $rows[0];
            }
        }

        return $this->result($parents, 'accepted_lgd_place_identifiers');
    }

    public function lgdVillages(array $codes, ?string $state = null): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes), fn ($code) => ctype_digit($code) && (int) $code > 0)));
        $accepted = [];
        foreach (array_chunk($codes, 400) as $chunk) {
            $query = app(MyPlaceDirectory::class)->database()->prepare('SELECT d.lgd,d.state,a.district FROM directory d LEFT JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.lgd IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
            $query->execute($chunk);
            $groups = [];
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $groups[$row['lgd']][$row['state'].':'.$row['district']] = $row;
            }
            foreach ($groups as $rows) {
                if (count($rows) === 1) {
                    $row = reset($rows);
                    if ($state === null || (int) $row['state'] === (int) $state) {
                        $accepted[] = $row;
                    }
                }
            }
        }

        return $this->result($accepted, 'official_lgd_village_ancestry', count($codes));
    }

    public function censusCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_map(fn ($code) => str_pad((string) (int) $code, 6, '0', STR_PAD_LEFT), array_filter($codes, fn ($code) => ctype_digit((string) $code) && (int) $code > 0 && (int) $code <= 999999))));
        $accepted = [];
        foreach (array_chunk($codes, 400) as $chunk) {
            $lookup = array_values(array_unique(array_merge($chunk, array_map(fn ($code) => (string) (int) $code, $chunk))));
            $query = app(MyPlaceDirectory::class)->database()->prepare('SELECT d.lgd,d.state,d.census2011,a.district FROM directory d LEFT JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.census2011 IN ('.implode(',', array_fill(0, count($lookup), '?')).')');
            $query->execute($lookup);
            $groups = [];
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $groups[(int) $row['census2011']][$row['state'].':'.$row['lgd'].':'.$row['district']] = $row;
            }
            foreach ($groups as $rows) {
                if (count($rows) === 1) {
                    $accepted[] = reset($rows);
                }
            }
        }

        return $this->result($accepted, 'official_census_2011_lgd_village_correspondence', count($codes));
    }

    private function result(array $parents, string $basis, int $expected = 0): array
    {
        if ($parents === []) {
            return ['options' => [], 'basis' => $basis, 'matched' => 0, 'unlinked' => $expected, 'reason' => 'no_unique_current_district'];
        }
        $directory = app(MyPlaceDirectory::class);
        $states = collect($directory->states())->keyBy('code');
        $options = [];
        $matched = 0;
        foreach ($parents as $parent) {
            $state = (string) (int) ($parent['state'] ?? 0);
            $district = (string) (int) ($parent['district'] ?? 0);
            if ($district === '0' || ! $states->has($state)) {
                continue;
            }
            $key = $state.':'.$district;
            if (! isset($options[$key])) {
                $districtRow = collect($directory->districts($state))->firstWhere('code', $district);
                if (! $districtRow) {
                    continue;
                }
                $options[$key] = ['key' => $key, 'state' => $state, 'district' => $district, 'state_name' => $states[$state]['name'], 'district_name' => $districtRow['name']];
            }
            $matched++;
        }
        usort($options, fn ($a, $b) => strcmp($a['state_name'].$a['district_name'], $b['state_name'].$b['district_name']));

        return ['options' => array_values($options), 'basis' => $basis, 'matched' => $matched, 'unlinked' => max(0, $expected - $matched), 'reason' => $options ? null : 'no_unique_current_district'];
    }
}
