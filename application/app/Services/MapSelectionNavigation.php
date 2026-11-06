<?php

namespace App\Services;

use Illuminate\Support\Str;
use PDO;

class MapSelectionNavigation
{
    public function selection(string $state, ?string $district = null, ?string $subdistrict = null): array
    {
        $directory = app(MyPlaceDirectory::class);
        $s = collect($directory->states())->firstWhere('code', $state);
        abort_unless($s, 404);
        $links = [['label' => $s['name'].' state dashboard', 'url' => route('civic.state', ['state' => PlaceFactTables::stateSlug($s['name'])])]];
        $result = ['state' => $s, 'district' => null, 'subdistrict' => null, 'navigation' => $links];
        if (! $district) {
            return $result;
        }
        $d = collect($directory->districts($state))->firstWhere('code', $district);
        abort_unless($d, 404);
        $identity = app(DistrictDashboardUrls::class)->resolve(Str::slug($s['name']), Str::slug($d['name']));
        $result['district'] = $d;
        $links[] = ['label' => $d['name'].' district dashboard', 'url' => $identity ? url($identity['path']) : route('map-selection.page', ['state' => $state, 'district' => $district])];
        if ($subdistrict) {
            $q = $directory->database()->prepare('SELECT DISTINCT d.subdistrict AS name FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? AND a.district=? AND a.subdistrict=?');
            $q->execute([$state, $district, $subdistrict]);
            $names = $q->fetchAll(PDO::FETCH_COLUMN);
            abort_unless(count($names) === 1, 404);
            $result['subdistrict'] = ['code' => $subdistrict, 'name' => $names[0]];
            $links[] = ['label' => $names[0].' subdistrict dashboard', 'url' => route('map-selection.page', ['state' => $state, 'district' => $district, 'subdistrict' => $subdistrict])];
        }
        $db = $directory->database();
        $q = $db->prepare('SELECT pc,ac,coverage FROM membership NOT INDEXED WHERE state=? AND entity_type=? AND entity_code=?');
        $q->execute([$state, $subdistrict ? 'SubDistrict' : 'District', $subdistrict ?: $district]);
        $seats = $db->prepare('SELECT name FROM seats WHERE state=? AND kind=? AND code=?');
        $seen = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $membership) {
            foreach (['pc', 'ac'] as $kind) {
                $key = $kind.':'.$membership[$kind];
                if (isset($seen[$key])) {
                    continue;
                }
                $seats->execute([$state, $kind, $membership[$kind]]);
                $name = $seats->fetchColumn();
                if ($name === false) {
                    continue;
                }
                $seen[$key] = true;
                $links[] = ['label' => ($kind === 'pc' ? 'Lok Sabha (PC)' : 'Assembly (AC)').': '.$name.' · related constituency', 'url' => route('constituency.overview', ['state' => $s['name'], 'kind' => $kind, 'name' => $name])];
            }
        }
        $result['navigation'] = $links;

        return $result;
    }

    public function villages(string $state, string $district, ?string $subdistrict): array
    {
        $q = app(MyPlaceDirectory::class)->database()->prepare('SELECT DISTINCT d.lgd AS code,d.name,d.subdistrict FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE a.state=? AND a.district=?'.($subdistrict ? ' AND a.subdistrict=?' : '').' ORDER BY d.name,d.lgd');
        $q->execute($subdistrict ? [$state, $district, $subdistrict] : [$state, $district]);

        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
