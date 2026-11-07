<?php

namespace App\Services;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use PDO;

final class VillageSummaryPage
{
    public function enabled(): bool
    {
        request()->attributes->set('village_summary_route', true);

        return request()->query('view') !== 'details'
            && request()->query('topic', 'overview') === 'overview'
            && request()->except(['view', 'topic', 'lang', 'year', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid']) === [];
    }

    public function census(object $row, int $year): View
    {
        $geo = json_decode($row->geography, true);
        $values = json_decode($row->values, true);
        $identity = $year === 2011 ? app(DistrictDashboardUrls::class)->identity($row->state_code, $row->district_code) : null;
        $labels = $geo['source_codes']['original_labels'] ?? [];
        $lgd = null;
        if ($year === 2011 && $row->residence === 'Rural') {
            $lgd = $this->linkedVillage((string) ($geo['Town/Village'] ?? $geo['TOWN_VILL'] ?? ''), (string) $row->state_code);

        }
        $metrics = ['population' => $values['TOT_P'] ?? $values['T_P'] ?? null, 'households' => $values['No_HH'] ?? $values['HOUSEHOLDS'] ?? $values['T_HH'] ?? null, 'literacy' => CensusAreaPresentation::rates($values)['literacy'], 'children' => $values['P_06'] ?? null, 'workers' => $values['TOT_WORK_P'] ?? null];

        return $this->page($row->name, $identity['name'] ?? $labels['District _Name'] ?? null, $identity['state_name'] ?? $labels['State_Name'] ?? null, $year, $metrics, $lgd, $identity);
    }

    public function linkedVillage(string $code, string $censusState): ?array
    {
        if (! ctype_digit($code) || (int) $code <= 0 || (int) $code >= 999999) {
            return null;
        }
        $map = app(ElectoralVillageMap::class);
        $forms = MyPlaceFacts::censusCodeForms($code);
        $marks = implode(',', array_fill(0, count($forms), '?'));
        $q = $map->database()->prepare("SELECT lgd,state FROM directory WHERE census2011 IN ($marks) LIMIT 2");
        $q->execute($forms);
        $matches = $q->fetchAll(PDO::FETCH_ASSOC);
        $states = match ((int) $censusState) {
            28 => ['28', '36'], 1 => ['1', '37'], 25,26 => ['38'], default => [(string) (int) $censusState],
        };

        return count($matches) === 1 && in_array((string) $matches[0]['state'], $states, true) ? $map->village($matches[0]['lgd'], true) : null;
    }

    public function directory(array $lgd, bool $fromGeography = false): View
    {
        $facts = app(MyPlaceFacts::class)->village(['code' => $lgd['lgd'], 'census2011' => $lgd['census2011']], (string) $lgd['state']);
        $metrics = array_intersect_key($facts, array_flip(['population', 'households', 'literacy']));

        return $this->page($lgd['name'], $lgd['district'], $lgd['state_name'], 2011, $metrics, $lgd, null, $facts, $fromGeography);
    }

    public function pilibhit(array $row, int $year, ?array $lgd): View
    {
        $metrics = ['population' => $row['population'] ?? null, 'households' => $row['households'] ?? null, 'children' => $row['population_0_6'] ?? null, 'literates' => $row['literate'] ?? null];

        return $this->page($row['name'], 'Pilibhit', 'Uttar Pradesh', $year, $metrics, $lgd, app(DistrictDashboardUrls::class)->identity('09', '151'));
    }

    public function archive(object $place, array $archive): View
    {
        return $this->page($place->name, null, null, 0, [], null, null, null, false, $archive);
    }

    private function page(string $name, ?string $district, ?string $state, int $year, array $metrics, ?array $lgd, ?array $identity, ?array $facts = null, bool $fromGeography = false, array $archive = []): View
    {
        $metrics = array_filter($metrics, fn ($v) => is_numeric($v) && $v >= 0);
        $hi = app()->getLocale() === 'hi';
        $detail = request()->fullUrlWithQuery(['view' => 'details']);
        $people = request()->fullUrlWithQuery(['view' => 'details', 'topic' => 'people']);
        $water = $soil = $satellite = $gp = null;
        $rain = [];
        $current = null;
        if ($lgd) {
            $code = (string) $lgd['lgd'];
            $st = (string) $lgd['state'];
            $current = url(app(NamedPlaceUrls::class)->village($lgd, false));
            if ($fromGeography) {
                $people = $current.'?'.http_build_query(array_filter(['view' => 'details', 'topic' => 'people', 'lang' => $hi ? 'hi' : null]));
            }
            $water = $facts !== null ? $facts['water'] : (app(WaterMapData::class)->records([['feature_index' => 0, 'state' => $st, 'lgd' => $code]])[0] ?? null);
            if (! is_numeric($water['households'] ?? null) || ! is_numeric($water['tap_connections'] ?? null) || $water['households'] <= 0 || $water['tap_connections'] < 0 || $water['tap_connections'] > $water['households']) {
                $water = null;
            }
            $satellite = $this->vegetation($st, $code);
            $soil = $this->nitrogen($st, $code);
            $gp = $this->panchayat($st, $code, $hi);
            if ($st === '9' && $lgd['district'] === 'Pilibhit' && is_file(public_path('maps/pilibhit-rainfall.json'))) {
                $report = json_decode(file_get_contents(public_path('maps/pilibhit-rainfall.json')), true);
                $shape = $report['lgd_to_source'][$code] ?? null;
                foreach ($report['villages'][$shape] ?? [] as $y => $r) {
                    if (($report['years'][$y]['source']['unit'] ?? null) === 'mm' && is_numeric($r['annual_mm'] ?? null)) {
                        $rain[$y] = $r['annual_mm'];
                    }
                }
            }
        }

        return view('village-summary', compact('name', 'district', 'state', 'year', 'metrics', 'hi', 'detail', 'people', 'water', 'soil', 'satellite', 'gp', 'rain', 'current', 'identity', 'lgd', 'archive'));
    }

    private function vegetation(string $state, string $code): ?array
    {
        $path = database_path('fixtures/village-summary-vegetation.sqlite');
        if (! is_file($path)) {
            return null;
        }
        $db = new PDO('sqlite:file:'.$path.'?mode=ro');
        $q = $db->prepare('SELECT r.layer,r.idx,r.a,r.b,r.coverage,l.date1,l.date2 FROM reading r JOIN layer l ON l.id=r.layer WHERE r.state=? AND r.lgd=?');
        $q->execute([$state, $code]);

        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function nitrogen(string $state, string $code): ?array
    {
        $dir = public_path('maps/soil-national-20261014');
        $manifest = $dir.'/manifest.json';
        if (! is_file($manifest)) {
            return null;
        }
        $shards = Cache::remember('village-soil-shards:'.filemtime($manifest), 3600, fn () => json_decode(file_get_contents($manifest), true)['cycles']['2025-26']['shards'] ?? []);
        $entry = $shards[substr($code, 0, 3)] ?? null;
        if (! $entry || ! is_file($path = $dir.'/'.basename($entry['file']))) {
            return null;
        }
        $index = Cache::remember('village-soil-counts:'.$entry['sha256'], 86400, function () use ($path, $entry) {
            $raw = file_get_contents($path);
            if (hash('sha256', $raw) !== $entry['sha256']) {
                return [];
            }
            $rows = json_decode($raw, true)['records'] ?? [];
            $out = [];
            foreach ($rows as $row) {
                $ids = $row['source_ids'];
                $v = (string) $row['lgd_village_code'];
                if ((string) $ids['village']['code'] !== $v) {
                    continue;
                }
                $key = $ids['state']['code'].':'.$v;
                $out[$key] = array_key_exists($key, $out) ? null : PlaceFactTables::soilValue($row['counts'], 'N', ['Low', 'Medium', 'High'], 'Low');
            }

            return $out;
        });
        $item = $index[$state.':'.$code] ?? null;

        return ($item['samples'] ?? 0) > 0 && is_numeric($item['value'] ?? null) ? $item : null;
    }

    private function panchayat(string $state, string $code, bool $hi): ?array
    {
        $row = app(GramPanchayatDirectory::class)->forVillage((int) $state, (int) $code);

        return $row ? ['name' => $row['name'], 'url' => route('gram-panchayats.show', ['state' => $state, 'gp' => $row['gp'], 'lang' => $hi ? 'hi' : null])] : null;
    }
}
