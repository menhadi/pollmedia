<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PlaceBreadcrumbs
{
    public function village(array $village): array
    {
        $location = $village['administration'] ?? $village;
        $state = $location['state_name'] ?? $village['state_name'] ?? null;
        $district = $location['district'] ?? null;
        $trail = [['label' => 'India', 'url' => route('home')]];
        if ($state) {
            $trail[] = ['label' => $state, 'url' => route('civic.state', ['state' => PlaceFactTables::stateSlug($state)])];
        }
        if ($state && $district) {
            $identity = app(DistrictDashboardUrls::class)->resolve(Str::slug($state), Str::slug($district));
            $url = $identity ? url($identity['path']) : (! empty($location['state']) && ! empty($location['district_code']) ? route('my-place.index', ['state' => $location['state'], 'district' => $location['district_code']]) : null);
            $trail[] = ['label' => $district, 'url' => $url];
        }
        if (! empty($location['subdistrict'])) {
            $url = null;
            $code = $location['census2011'] ?? null;
            if ($code && ! empty($location['state']) && RequestSchema::hasTable('census_catalogue_rows') && RequestSchema::hasTable('census_editions')) {
                $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                    ->where('e.status', 'published')->where('e.year', 2011)->where('c.level', 'VILLAGE')->where('c.residence', 'Rural')
                    ->where('c.state_code', str_pad($location['state'], 2, '0', STR_PAD_LEFT))->where('c.geography->Town/Village', str_pad($code, 6, '0', STR_PAD_LEFT))
                    ->select('c.state_code', 'c.district_code', 'c.geography')->get();
                $identities = $rows->map(fn ($row) => $row->state_code.':'.$row->district_code.':'.(json_decode($row->geography, true)['Subdistt'] ?? ''))->unique();
                if ($identities->count() === 1) {
                    $row = $rows->first();
                    $subdistrict = json_decode($row->geography, true)['Subdistt'] ?? null;
                    if ($subdistrict) {
                        $record = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                            ->where('e.status', 'published')->where('e.year', 2011)->where('c.state_code', $row->state_code)->where('c.district_code', $row->district_code)
                            ->whereIn('c.level', ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'])->where('c.residence', 'Total')->where('c.geography->Subdistt', $subdistrict)->orderBy('c.id')->value('c.id');
                        $url = $record ? app(NamedPlaceUrls::class)->census((int) $record) : null;
                    }
                }
            }
            $trail[] = ['label' => $location['subdistrict'], 'url' => $url];
        }
        if (! empty($village['name'])) {
            $trail[] = ['label' => $village['name'], 'url' => null];
        }

        return $trail;
    }

    public function issue(array $scopes): array
    {
        $areas = collect($scopes);
        $village = $areas->firstWhere('kind', 'village');
        if ($village && preg_match('/^village:([0-9]{1,8})$/D', $village['key'] ?? '', $match)) {
            try {
                $query = app(MyPlaceDirectory::class)->database()->prepare('SELECT d.*,s.name AS state_name,a.district AS district_code,a.subdistrict AS subdistrict_code FROM directory d JOIN states s ON s.code=d.state JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.lgd=?');
                $query->execute([$match[1]]);
                $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
                if (count($rows) === 1) {
                    $trail = $this->village($rows[0]);
                    $trail[array_key_last($trail)]['url'] = app(NamedPlaceUrls::class)->village($rows[0]);

                    return $trail;
                }
            } catch (HttpExceptionInterface|\PDOException) {
                // Retain recorded place scopes when the current directory is unavailable.
            }
        }
        $state = $areas->firstWhere('kind', 'state')['name'] ?? null;
        $trail = [['label' => 'India', 'url' => route('home')]];
        foreach (['state', 'district', 'subdistrict', 'locality', 'village'] as $kind) {
            $scope = $areas->firstWhere('kind', $kind);
            if (! $scope || empty($scope['name'])) {
                continue;
            }
            $url = $kind === 'state' ? route('civic.state', ['state' => PlaceFactTables::stateSlug($scope['name'])]) : null;
            if ($kind === 'district' && $state) {
                $identity = app(DistrictDashboardUrls::class)->resolve(Str::slug($state), Str::slug($scope['name']));
                $url = $identity ? url($identity['path']) : null;
            }
            if ($kind === 'village' && preg_match('/^village:([0-9]{1,8})$/D', $scope['key'] ?? '', $match)) {
                $url = route('electoral-villages.village', $match[1]);
            }
            $trail[] = ['label' => $scope['name'], 'url' => $url];
        }

        return $trail;
    }
}
