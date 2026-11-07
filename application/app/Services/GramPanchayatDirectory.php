<?php

namespace App\Services;

use PDO;

class GramPanchayatDirectory
{
    private function rows(string $sql, array $values): array
    {
        $path = database_path('fixtures/gram-panchayat-directory.sqlite');
        abort_unless(is_file($path), 503);
        $db = new PDO('sqlite:file:'.$path.'?mode=ro');
        $query = $db->prepare($sql);
        $query->execute($values);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listing(string $scope, int $state, int $district, ?int $subdistrict, string $search = '', int $page = 1, int $size = 50): array
    {
        $census = $scope === 'census';
        $table = $census ? 'census_gps' : 'villages';
        $where = $census ? ['v.cs=?', 'v.cd=?'] : ['v.state=?', 'v.district=?'];
        $bindings = [$state, $district];
        if ($subdistrict !== null) {
            $where[] = $census ? 'v.sub=?' : 'v.subdistrict=?';
            $bindings[] = $subdistrict;
        }
        if ($search !== '') {
            $where[] = '(instr(lower(g.name),lower(?))>0 OR CAST(g.gp AS TEXT)=?)';
            array_push($bindings, $search, $search);
        }
        $from = " FROM {$table} v JOIN gps g ON g.state=v.state AND g.gp=v.gp WHERE ".implode(' AND ', $where);
        $limit = $size + 1;
        $offset = ($page - 1) * $size;
        $rows = $this->rows("SELECT DISTINCT g.state,g.gp,g.name {$from} ORDER BY g.name COLLATE NOCASE,g.state,g.gp LIMIT {$limit} OFFSET {$offset}", $bindings);

        return ['items' => array_slice($rows, 0, $size), 'more' => count($rows) > $size];
    }

    public function myPlace(array $context, ?string $selection): array
    {
        $state = (int) $context['state']['code'];
        $district = (int) $context['district']['code'];
        $values = [$state, $district];
        $criteria = 'v.state=? AND v.district=?';
        if (! empty($context['village']['code'])) {
            $criteria .= ' AND v.village=?';
            $values[] = (int) $context['village']['code'];
        } elseif (! empty($context['gp_subdistrict'])) {
            $criteria .= ' AND v.subdistrict=?';
            $values[] = (int) $context['gp_subdistrict'];
        }
        $items = $this->rows("SELECT DISTINCT g.gp,g.name FROM villages v JOIN gps g USING(state,gp) WHERE {$criteria} ORDER BY g.name,g.gp", $values);
        $result = [];
        foreach ($items as $item) {
            $code = (string) $item['gp'];
            $villages = [];
            if ($selection === $code || count($items) === 1) {
                $villages = $this->rows('SELECT CAST(village AS TEXT) AS code,name FROM villages WHERE state=? AND district=? AND gp=? ORDER BY name,village', [$state, $district, $code]);
            }
            $result[] = ['code' => $code, 'name' => $item['name'], 'namespace' => 'LGD Gram Panchayat',
                'villages' => $villages, 'blocks' => [], 'checked_on' => '2026-11-05', 'source_url' => 'https://lgdirectory.gov.in/downloadDirectory.do',
                'profile_state' => $state];
        }

        return $result;
    }

    public function profile(int $state, int $gp): array
    {
        $record = $this->rows('SELECT * FROM gps WHERE state=? AND gp=?', [$state, $gp])[0] ?? null;
        abort_unless($record, 404);
        $villages = $this->rows('SELECT a.*,v.village,v.name FROM villages v JOIN areas a USING(state,district,subdistrict) WHERE v.state=? AND v.gp=? ORDER BY district_name,subdistrict_name,v.name,v.village', [$state, $gp]);

        return ['record' => $record, 'villages' => $villages];
    }

    public function areaName(int $state, int $district, ?int $subdistrict): ?string
    {
        $row = $this->rows('SELECT district_name,subdistrict_name FROM areas WHERE state=? AND district=?'.($subdistrict ? ' AND subdistrict=?' : '').' LIMIT 1', $subdistrict ? [$state, $district, $subdistrict] : [$state, $district])[0] ?? null;

        return $row ? ($subdistrict ? $row['subdistrict_name'].' · ' : '').$row['district_name'] : null;
    }

    public function districtSubdistricts(int $state, int $district): array
    {
        return $this->rows('SELECT subdistrict,subdistrict_name FROM areas WHERE state=? AND district=? ORDER BY subdistrict_name,subdistrict', [$state, $district]);
    }
}
