<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

class LocalBoardLocations
{
    public function resolve(string $key): array
    {
        if (preg_match('/^village:([1-9][0-9]{0,7})$/D', $key, $match)) {
            $query = app(MyPlaceDirectory::class)->database()->prepare('SELECT d.*,s.name AS state_name,a.district AS district_code FROM directory d JOIN states s ON s.code=d.state JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.lgd=?');
            $query->execute([$match[1]]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            abort_unless(count($rows) === 1, 404, 'A unique village location is required.');
            $row = $rows[0];
            $district = $this->resolve('district:'.$row['state'].':'.$row['district_code']);
            $parents = [$this->scope($district)];
            $gp = app(GramPanchayatDirectory::class)->forVillage((int) $row['state'], (int) $row['lgd']);
            if ($gp) {
                $parents[] = ['key' => 'gp:'.$row['state'].':'.$gp['gp'], 'name' => $gp['name'], 'kind' => 'gp'];
            }

            return $this->area($key, $row['name'], 'village', (string) $row['state'], (string) $row['district_code'], $parents, app(NamedPlaceUrls::class)->village($row));
        }
        if (preg_match('/^district:([1-9][0-9]{0,2}):([1-9][0-9]{0,7})$/D', $key, $match)) {
            $state = collect(app(MyPlaceDirectory::class)->states())->first(fn ($row) => (string) $row['code'] === $match[1]);
            $district = collect(app(MyPlaceDirectory::class)->districts($match[1]))->first(fn ($row) => (string) $row['code'] === $match[2]);
            abort_unless($state && $district, 404);
            $identity = app(DistrictDashboardUrls::class)->resolve(Str::slug($state['name']), Str::slug($district['name']));

            return $this->area($key, $district['name'], 'district', $match[1], $match[2], [], $identity ? url($identity['path']) : route('my-place.index', ['state' => $match[1], 'district' => $match[2]]));
        }
        if (preg_match('/^gp:([1-9][0-9]{0,2}):([1-9][0-9]{0,9})$/D', $key, $match)) {
            $profile = app(GramPanchayatDirectory::class)->profile((int) $match[1], (int) $match[2]);
            $parents = [];
            foreach (collect($profile['villages'])->unique('district') as $member) {
                $parent = $this->resolve('district:'.$match[1].':'.$member['district']);
                $parents[] = $this->scope($parent);
            }

            return $this->area($key, $profile['record']['name'], 'gp', $match[1], (string) ($profile['villages'][0]['district'] ?? ''), $parents, app(GramPanchayatUrls::class)->link($match[1], $profile['record']['name'], $match[2]));
        }
        if (preg_match('/^city:([1-9][0-9]{0,2}):([1-9][0-9]{0,3}):([1-9][0-9]{0,7})$/D', $key, $match)) {
            $rows = $this->towns($match[1], $match[2])->filter(fn ($row) => $this->townCode($row) === $match[3]);
            abort_unless($rows->isNotEmpty() && $rows->pluck('name')->map(fn ($name) => Str::slug($name))->unique()->count() === 1, 404);
            $row = $rows->first();
            // Census town identity does not establish a current district after boundary changes.
            $urban = json_decode(file_get_contents(database_path('fixtures/lgd-urban-districts.json')), true)['districts'] ?? [];
            $matches = array_values(array_filter($urban, fn ($item) => (int) ($item['census_state'] ?? 0) === (int) $row->state_code && (int) ($item['census_district'] ?? 0) === (int) $row->district_code));
            $parentKey = count($matches) === 1 ? 'district:'.(int) $matches[0]['state'].':'.(int) $matches[0]['code'] : null;
            $parents = $parentKey ? [$this->scope($this->resolve($parentKey))] : [];

            return $this->area($key, $row->name, 'city', $parentKey ? explode(':', $parentKey)[1] : $match[1], $parentKey ? explode(':', $parentKey)[2] : '', $parents, app(NamedPlaceUrls::class)->census((int) $row->id));
        }
        abort(404, 'Choose a verified place from the directory.');
    }

    public function districtKey(string $stateName, string $districtName): ?string
    {
        return PublicRequestCache::remember('board-district:'.$stateName.':'.$districtName, function () use ($stateName, $districtName): ?string {
            $state = collect(app(MyPlaceDirectory::class)->states())->filter(fn ($row) => Str::slug($row['name']) === Str::slug($stateName));
            if ($state->count() !== 1) {
                return null;
            }
            $state = (string) $state->first()['code'];
            $districts = collect(app(MyPlaceDirectory::class)->districts($state))->filter(fn ($row) => Str::slug($row['name']) === Str::slug($districtName));

            return $districts->count() === 1 ? 'district:'.$state.':'.$districts->first()['code'] : null;
        });
    }

    public function censusKey(object $row, int $year): ?string
    {
        if ($year !== 2011) {
            return null;
        }
        if ($row->level === 'TOWN') {
            $code = $this->townCode($row);

            return $code ? 'city:'.(int) $row->state_code.':'.(int) $row->district_code.':'.$code : null;
        }
        if ($row->level === 'DISTRICT') {
            $identity = app(DistrictDashboardUrls::class)->identity($row->state_code, $row->district_code);

            return $identity ? $this->districtKey($identity['state_name'], $identity['name']) : null;
        }
        if ($row->level === 'VILLAGE') {
            $code = $this->townCode($row);
            $village = $code ? app(VillageSummaryPage::class)->linkedVillage($code, (string) $row->state_code) : null;

            return $village ? 'village:'.$village['lgd'] : null;
        }

        return null;
    }

    public function options(string $kind, string $state, string $district, string $search, int $page): array
    {
        if ($kind === 'states') {
            return ['items' => app(MyPlaceDirectory::class)->states(), 'more' => false];
        }
        if ($kind === 'districts') {
            abort_unless(collect(app(MyPlaceDirectory::class)->states())->contains(fn ($row) => (string) $row['code'] === $state), 404);

            return ['items' => app(MyPlaceDirectory::class)->districts($state), 'more' => false];
        }
        $context = $this->resolve('district:'.$state.':'.$district);
        if ($kind === 'gp') {
            $listing = app(GramPanchayatDirectory::class)->listing('lgd', (int) $state, (int) $district, null, $search, $page, 25);

            return ['items' => array_map(fn ($row) => ['key' => 'gp:'.$state.':'.$row['gp'], 'name' => $row['name']], $listing['items']), 'more' => $listing['more']];
        }
        if ($kind === 'village') {
            $query = app(MyPlaceDirectory::class)->database()->prepare('SELECT DISTINCT d.lgd,d.name,d.subdistrict FROM directory d JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE a.state=? AND a.district=? AND instr(lower(d.name),lower(?))>0 ORDER BY d.name,d.lgd LIMIT 26 OFFSET ?');
            $query->execute([$state, $district, $search, ($page - 1) * 25]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);

            return ['items' => array_map(fn ($row) => ['key' => 'village:'.$row['lgd'], 'name' => $row['name'].' · '.$row['subdistrict']], array_slice($rows, 0, 25)), 'more' => count($rows) > 25];
        }
        $identity = collect(app(DistrictDashboardUrls::class)->all())->first(fn ($row) => (int) $row['state'] === (int) $state && $this->districtKey($row['state_name'], $row['name']) === $context['key']);
        $contexts = $identity ? [['state' => $identity['state'], 'district' => $identity['district']]] : [];
        $urban = json_decode(file_get_contents(database_path('fixtures/lgd-urban-districts.json')), true)['districts'] ?? [];
        foreach ($urban as $item) {
            if ((int) $item['state'] === (int) $state && (int) $item['code'] === (int) $district) {
                $contexts[] = ['state' => $item['census_state'], 'district' => $item['census_district']];
            }
        }
        $towns = collect();
        foreach (collect($contexts)->unique(fn ($item) => $item['state'].':'.$item['district']) as $candidate) {
            $towns = $towns->concat($this->towns((string) $candidate['state'], (string) $candidate['district']));
        }
        $towns = $towns->filter(fn ($row) => $this->townCode($row) && ($search === '' || mb_stripos($row->name, $search) !== false))->unique(fn ($row) => $row->state_code.':'.$row->district_code.':'.$this->townCode($row))->sortBy('name')->values();
        $slice = $towns->slice(($page - 1) * 25, 25);

        return ['items' => $slice->map(fn ($row) => ['key' => 'city:'.(int) $row->state_code.':'.(int) $row->district_code.':'.$this->townCode($row), 'name' => $row->name])->values()->all(), 'more' => $towns->count() > $page * 25];
    }

    private function towns(string $state, string $district): Collection
    {
        return DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->where('e.status', 'published')->where('e.year', 2011)->where('c.level', 'TOWN')
            ->where('c.state_code', str_pad($state, 2, '0', STR_PAD_LEFT))->where('c.district_code', str_pad($district, 3, '0', STR_PAD_LEFT))
            ->select('c.id', 'c.name', 'c.state_code', 'c.district_code', 'c.geography')->orderBy('c.name')->orderBy('c.id')->get();
    }

    private function townCode(object $row): ?string
    {
        $geo = is_array($row->geography) ? $row->geography : json_decode($row->geography, true);
        $code = (string) ($geo['Town/Village'] ?? $geo['TOWN_VILL'] ?? '');

        return ctype_digit($code) && (int) $code > 0 ? (string) (int) $code : null;
    }

    private function scope(array $area): array
    {
        return array_intersect_key($area, array_flip(['key', 'name', 'kind']));
    }

    private function area(string $key, string $name, string $kind, string $state, string $district, array $parents, string $url): array
    {
        return compact('key', 'name', 'kind', 'state', 'district', 'parents', 'url');
    }
}
