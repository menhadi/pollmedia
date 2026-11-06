<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MyPlaceDirectory
{
    public function database(): \PDO
    {
        $path = app(ElectoralVillageMap::class)->directory().'/links.sqlite';
        abort_unless(is_file($path), 503);
        $db = new \PDO('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');

        return $db;
    }

    public function states(): array
    {
        return $this->database()->query('SELECT code,name FROM states ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function districts(string $state): array
    {
        $path = app(ElectoralVillageMap::class)->directory().'/links.sqlite';
        abort_unless(is_file($path), 503);
        $urbanPath = database_path('fixtures/lgd-urban-districts.json');
        $key = 'my-place-districts:v2:'.hash('sha256', $path.filemtime($path).filesize($path).':'.$state.(is_file($urbanPath) ? filemtime($urbanPath).filesize($urbanPath) : ''));

        return Cache::remember($key, 3600, function () use ($state, $urbanPath): array {
            $query = $this->database()->prepare('SELECT a.district AS code,MIN(d.district) AS name FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? GROUP BY a.district HAVING COUNT(DISTINCT d.district)=1 ORDER BY name');
            $query->execute([$state]);

            $rows = collect($query->fetchAll(\PDO::FETCH_ASSOC))->keyBy('code');
            $urban = is_file($urbanPath) ? json_decode(file_get_contents($urbanPath), true)['districts'] ?? [] : [];
            foreach ($urban as $district) {
                if ((int) $district['state'] === (int) $state && ! $rows->has((string) $district['code'])) {
                    $rows->put((string) $district['code'], ['code' => (string) $district['code'], 'name' => $district['name']]);
                }
            }

            return $rows->sortBy('name')->values()->all();
        });
    }

    public function context(string $state, string $district, ?string $village): array
    {
        $s = collect($this->states())->firstWhere('code', $state);
        $d = collect($this->districts($state))->firstWhere('code', $district);
        abort_unless($s && $d, 404);
        $selected = null;
        if ($village) {
            $query = $this->database()->prepare('SELECT DISTINCT d.lgd AS code,d.name,d.census2011,d.subdistrict,a.subdistrict AS subdistrict_code FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? AND a.district=? AND a.lgd=?');
            $query->execute([$state, $district, $village]);
            $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
            abort_unless(count($rows) === 1, 404);
            $selected = $rows[0];
        }
        $pilot = $state === '9' && $district === '173';
        $dashboards = app(DistrictDashboardUrls::class);
        $districtIdentity = $dashboards->resolve(Str::slug($s['name']), Str::slug($d['name']));
        if ($state === '29' && $district === '525') {
            // Historical district reference: censusindia.gov.in/nada/index.php/catalog/6763;
            // Bengaluru Urban DSO demography retains the Bangalore 2011 district figures.
            $districtIdentity = $dashboards->identity('29', '572');
        }
        $facts = $pilot ? app(PilibhitMyPlace::class)->facts($selected) : ($selected ? app(MyPlaceFacts::class)->village($selected, $state) : app(MyPlaceFacts::class)->district($districtIdentity));
        $dashboard = $selected ? NamedPlaceUrls::path('lgd-village', $s['name'], $d['name'], $selected['name'], $selected['code']) : ($districtIdentity['path'] ?? route('district-dashboard.named', ['state' => Str::slug($s['name']), 'district' => Str::slug($d['name'])], false));

        return ['state' => $s, 'district' => $d, 'village' => $selected, 'pilot' => $pilot, 'facts' => $facts, 'dashboard' => $dashboard];
    }
}
