<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;

class PostalPlaceNavigation
{
    private ?array $statePages = null;

    private ?array $districtPages = null;

    public function __construct(private ?string $directoryPath = null) {}

    private function database(): ?PDO
    {
        $path = $this->directoryPath ?? storage_path('app/electoral-villages/links.sqlite');

        return is_file($path) ? new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) : null;
    }

    public function rows(array $rows): array
    {
        $db = $this->database();
        $states = $db ? $db->query('SELECT code,name FROM states')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        $village = $db?->prepare('SELECT d.*,a.district AS district_code,a.subdistrict AS subdistrict_code FROM directory d JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.lgd=?');
        $subdistrict = $db?->prepare('SELECT count(DISTINCT d.subdistrict) FROM directory d JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE a.state=? AND a.district=? AND a.subdistrict=?');
        $subdistricts = [];
        foreach ($rows as &$row) {
            $row['place_url'] = route('postal-areas.show', ['kind' => $row['kind'], 'code' => $row['code']]);
            $row['state_url'] = isset($states[$row['state']]) ? $this->stateUrl($row['state'], $states[$row['state']]) : null;
            $row['district_url'] = null;
            $row['subdistrict_url'] = null;
            $row['current_identity_linked'] = false;
            $row['parent_links'] = [];
            if ($row['kind'] === 'local-body' && $db) {
                foreach (app(UrbanPostalParents::class)->forBody((string) $row['code'], (string) $row['state']) as $parent) {
                    $scope = $db->prepare($parent['level'] === 'subdistrict'
                        ? 'SELECT count(DISTINCT d.subdistrict) FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? AND a.district=? AND a.subdistrict=?'
                        : 'SELECT EXISTS(SELECT 1 FROM ancestry WHERE state=? AND district=?)');
                    $params = [$row['state'], $parent['district']];
                    if ($parent['level'] === 'subdistrict') {
                        $params[] = $parent['code'];
                    }
                    $scope->execute($params);
                    $valid = (int) $scope->fetchColumn() === 1;
                    if (! $valid && $parent['level'] === 'district' && class_exists(MyPlaceDirectory::class)) {
                        $valid = collect(app(MyPlaceDirectory::class)->districts((string) $row['state']))->contains(fn ($district) => (string) $district['code'] === $parent['code']);
                    }
                    $parent['url'] = $valid ? ($parent['level'] === 'district'
                        ? $this->districtUrl($row['state'], $parent['code'], $row['state_name'], $parent['name'])
                        : route('map-selection.page', ['state' => $row['state'], 'district' => $parent['district'], 'subdistrict' => $parent['code']])) : route('postal-areas.geography', ['state' => $row['state'], 'level' => $parent['level'], 'code' => $parent['code']]);
                    $row['parent_links'][$parent['level']][] = $parent;
                }
            }
            if ($row['kind'] !== 'village' || ! $village) {
                continue;
            }
            $village->execute([$row['code']]);
            $matches = $village->fetchAll(PDO::FETCH_ASSOC);
            $match = count($matches) === 1 ? $matches[0] : null;
            if (! $match || $match['state'] !== $row['state'] || $match['district_code'] !== $row['district'] || $match['subdistrict_code'] !== $row['subdistrict']) {
                continue;
            }
            $row['current_identity_linked'] = true;
            $row['name'] = $match['name'];
            $row['district_name'] = $match['district'];
            $row['subdistrict_name'] = $match['subdistrict'];
            $row['state_name'] = $states[$row['state']];
            $row['place_url'] = class_exists(NamedPlaceUrls::class)
                ? app(NamedPlaceUrls::class)->village([...$match, 'state_name' => $row['state_name']])
                : route('electoral-villages.village', ['code' => $row['code']]);
            $row['district_url'] = $this->districtUrl($row['state'], $row['district'], $row['state_name'], $row['district_name']);
            $key = $row['state'].':'.$row['district'].':'.$row['subdistrict'];
            if (! array_key_exists($key, $subdistricts)) {
                $subdistrict->execute([$row['state'], $row['district'], $row['subdistrict']]);
                $subdistricts[$key] = (int) $subdistrict->fetchColumn() === 1;
            }
            if ($subdistricts[$key]) {
                $row['subdistrict_url'] = route('map-selection.page', ['state' => $row['state'], 'district' => $row['district'], 'subdistrict' => $row['subdistrict']]);
            }
        }

        return $rows;
    }

    private function stateUrl(string $code, string $name): string
    {
        if ($this->statePages === null) {
            $this->statePages = [];
            if (Schema::hasTable('census_catalogue_rows') && Schema::hasTable('census_editions')) {
                $names = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                    ->where('e.status', 'published')->where('e.year', 2011)->where('c.level', 'STATE')->where('c.residence', 'Total')->distinct()->pluck('c.name');
                foreach ($names as $stateName) {
                    $slug = $this->stateSlug($stateName);
                    $this->statePages[$slug] = route('civic.state', ['state' => $slug]);
                }
            }
        }

        return $this->statePages[$this->stateSlug($name)] ?? route('postal-areas.state', ['state' => $code]);
    }

    private function stateSlug(string $name): string
    {
        return Str::slug(preg_replace('/^the\s+/i', '', str_replace('&', 'and', $name)));
    }

    private function districtUrl(string $state, string $district, string $stateName, string $districtName): string
    {
        if ($this->districtPages === null) {
            $this->districtPages = [];
            if (class_exists(DistrictDashboardUrls::class)) {
                foreach (app(DistrictDashboardUrls::class)->all() as $page) {
                    $this->districtPages[$page['path']] = true;
                }
            }
        }
        $path = '/india/districts/'.Str::slug($stateName).'/'.Str::slug($districtName);

        return isset($this->districtPages[$path]) ? url($path) : route('map-selection.page', ['state' => $state, 'district' => $district]);
    }

    public function state(string $code): ?array
    {
        $db = $this->database();
        if (! $db) {
            return null;
        }
        $query = $db->prepare('SELECT code,name FROM states WHERE code=?');
        $query->execute([$code]);
        $state = $query->fetch(PDO::FETCH_ASSOC);
        if (! $state) {
            return null;
        }
        $query = $db->prepare('SELECT a.district AS code,MIN(d.district) AS name,count(*) AS villages FROM directory d JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.state=? GROUP BY a.district HAVING count(DISTINCT d.district)=1 ORDER BY name');
        $query->execute([$code]);
        $districts = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($districts as &$district) {
            $district['url'] = $this->districtUrl($code, $district['code'], $state['name'], $district['name']);
        }

        return ['state' => $state, 'districts' => $districts];
    }
}
