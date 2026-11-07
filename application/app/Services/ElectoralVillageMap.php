<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use PDO;

class ElectoralVillageMap
{
    public function directory(): string
    {
        return storage_path('app/electoral-villages');
    }

    public function database(): PDO
    {
        $path = $this->directory().'/links.sqlite';
        abort_unless(is_file($path), 503, 'The official LGD constituency mapping is not available yet.');
        $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $supplement = $this->directory().'/display-links.sqlite';
        if (is_file($supplement)) {
            $database->exec('ATTACH DATABASE '.$database->quote($supplement).' AS display_links');
            $audit = json_decode($database->query('SELECT json FROM display_links.metadata')->fetchColumn(), true);
            $version = filemtime($path).':'.filesize($path);
            $sha = Cache::remember('village-links-sha:'.hash('sha256', $path.':'.$version), 3600, fn (): string => hash_file('sha256', $path));
            if (hash_equals($audit['original_links_sha256'] ?? '', $sha)) {
                if ($database->query("SELECT 1 FROM display_links.sqlite_master WHERE type='table' AND name='layers'")->fetchColumn()) {
                    $database->exec('CREATE TEMP VIEW layers AS SELECT * FROM main.layers UNION ALL SELECT x.file,x.sha256,x.source,x.edition FROM display_links.layers x WHERE NOT EXISTS (SELECT 1 FROM main.layers l WHERE l.file=x.file)');
                }
                $database->exec('CREATE TEMP VIEW shapes AS SELECT * FROM main.shapes UNION ALL SELECT x.file,x.feature_index,x.lgd,x.state,x.basis FROM display_links.shapes x JOIN layers l ON l.file=x.file AND l.sha256=x.sha256 WHERE NOT EXISTS (SELECT 1 FROM main.shapes s WHERE s.file=x.file AND s.feature_index=x.feature_index)');
            }
        }
        $database->exec('PRAGMA query_only=ON');

        return $database;
    }

    public function catalogue(): array
    {
        $database = $this->database();

        return ['states' => $database->query('SELECT code,name FROM states ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
            'seats' => $database->query('SELECT state,kind,code,name FROM seats ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
            'audit' => json_decode($database->query('SELECT json FROM metadata')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function membership(string $state, string $kind, string $seat): array
    {
        $database = $this->database();
        $statement = $database->prepare('SELECT name FROM seats WHERE state=? AND kind=? AND code=?');
        $statement->execute([$state, $kind, $seat]);
        $name = $statement->fetchColumn();
        abort_unless($name !== false, 404);
        $column = $kind === 'pc' ? 'pc' : 'ac';
        $statement = $database->prepare("SELECT entity_type,entity_code,MIN(entity_name) AS entity_name,MAX(coverage='Partly Covered') AS partial,MIN(source_row) AS source_row FROM membership WHERE state=? AND $column=? GROUP BY entity_type,entity_code");
        $statement->execute([$state, $seat]);
        $entities = $statement->fetchAll(PDO::FETCH_ASSOC);
        $resolved = "WITH resolved AS (
            SELECT entity_code AS lgd,coverage FROM membership WHERE state=:state AND $column=:seat AND entity_type='Village'
            UNION ALL SELECT a.lgd,'Fully Covered' FROM membership m JOIN ancestry a ON a.state=m.state AND a.district=m.entity_code WHERE m.state=:state AND m.$column=:seat AND m.entity_type='District' AND m.coverage='Fully Covered'
            UNION ALL SELECT a.lgd,'Fully Covered' FROM membership m JOIN ancestry a ON a.state=m.state AND a.subdistrict=m.entity_code WHERE m.state=:state AND m.$column=:seat AND m.entity_type='SubDistrict' AND m.coverage='Fully Covered'
        ), villages AS (SELECT lgd,MAX(coverage='Partly Covered') AS partial FROM resolved GROUP BY lgd) ";
        $statement = $database->prepare($resolved.'SELECT DISTINCT s.file,s.feature_index,s.lgd,s.basis,l.sha256,l.source,l.edition FROM shapes s JOIN layers l ON l.file=s.file JOIN villages v ON v.lgd=s.lgd WHERE s.state=:state AND v.partial=0 ORDER BY s.file,s.feature_index');
        $statement->execute(['state' => $state, 'seat' => $seat]);
        $shapes = $statement->fetchAll(PDO::FETCH_ASSOC);
        $layers = [];
        foreach ($shapes as $shape) {
            $file = $shape['file'];
            $layers[$file] ??= ['id' => hash('sha256', $file), 'file' => $file, 'sha256' => $shape['sha256'], 'source' => $shape['source'], 'edition' => $shape['edition'], 'indices' => [], 'codes' => []];
            $layers[$file]['indices'][] = (int) $shape['feature_index'];
            $layers[$file]['codes'][(string) $shape['feature_index']] = $shape['lgd'];
        }
        $statement = $database->prepare($resolved.'SELECT COUNT(*) AS total,COALESCE(SUM(partial),0) AS partial FROM villages');
        $statement->execute(['state' => $state, 'seat' => $seat]);
        $villageCounts = $statement->fetch(PDO::FETCH_ASSOC);
        $linkedCodes = array_unique(array_column($shapes, 'lgd'));
        $statement = $database->prepare($resolved.'SELECT d.lgd,d.name,d.subdistrict FROM villages v JOIN directory d ON d.lgd=v.lgd WHERE d.state=:state AND v.partial=0 ORDER BY d.name,d.lgd');
        $statement->execute(['state' => $state, 'seat' => $seat]);
        $villageOptions = $statement->fetchAll(PDO::FETCH_ASSOC);

        return ['state' => $state, 'kind' => $kind, 'code' => $seat, 'name' => $name, 'entities' => $entities, 'layers' => array_values($layers), 'villages' => $villageOptions,
            'counts' => ['villages' => (int) $villageCounts['total'], 'partly_covered_villages' => (int) $villageCounts['partial'],
                'linked_villages' => count($linkedCodes), 'linked_shapes' => count($shapes), 'villages_without_displayed_shape' => (int) $villageCounts['total'] - count($linkedCodes)],
            'note' => 'Official LGD membership checked 2026-10-13. Fully covered district/subdistrict memberships expand through the official village-directory hierarchy. Only fully covered villages with checked code identities are displayed. Partial coverage, missing polygons and unverified boundary editions remain explicit. No population or soil totals are inferred.'];
    }

    public function hierarchy(string $state, string $district = '', string $subdistrict = ''): array
    {
        $database = $this->database();
        $where = 'a.state=?';
        $parameters = [$state];
        if ($district !== '') {
            $where .= ' AND a.district=?';
            $parameters[] = $district;
        }
        if ($subdistrict !== '') {
            $where .= ' AND a.subdistrict=?';
            $parameters[] = $subdistrict;
        }
        $select = $district === '' ? 'a.district AS code,MIN(d.district) AS name' : ($subdistrict === '' ? 'a.subdistrict AS code,MIN(d.subdistrict) AS name' : 'd.lgd AS code,d.name');
        $group = $district === '' ? 'a.district' : ($subdistrict === '' ? 'a.subdistrict' : 'd.lgd');
        $statement = $database->prepare("SELECT $select FROM ancestry a JOIN directory d ON d.lgd=a.lgd AND d.state=a.state WHERE $where GROUP BY $group ORDER BY name,code");
        $statement->execute($parameters);

        $options = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($district === '') {
            $query = $database->prepare("SELECT entity_code AS code,MIN(entity_name) AS name FROM membership WHERE state=? AND entity_type='District' GROUP BY entity_code");
            $query->execute([$state]);
            $options = collect(array_merge($options, $query->fetchAll(PDO::FETCH_ASSOC)))->unique('code')->sortBy('name')->values()->all();
            $urbanPath = database_path('fixtures/lgd-urban-districts.json');
            if (is_file($urbanPath)) {
                $urban = collect(json_decode(file_get_contents($urbanPath), true, flags: JSON_THROW_ON_ERROR)['districts'])
                    ->where('state', $state)->map(fn (array $row): array => ['code' => $row['code'], 'name' => $row['name']])->all();
                $options = collect(array_merge($options, $urban))->unique('code')->sortBy('name')->values()->all();
            }
        }

        return ['options' => $options];
    }

    public function area(string $state, string $district, string $subdistrict = ''): array
    {
        $where = 'a.state=? AND a.district=?';
        $parameters = [$state, $district];
        if ($subdistrict !== '') {
            $where .= ' AND a.subdistrict=?';
            $parameters[] = $subdistrict;
        }
        $statement = $this->database()->prepare("SELECT s.file,s.feature_index,s.lgd,l.sha256,l.source,l.edition FROM ancestry a JOIN shapes s ON s.lgd=a.lgd AND s.state=a.state JOIN layers l ON l.file=s.file WHERE $where ORDER BY s.file,s.feature_index");
        $statement->execute($parameters);
        $layers = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $shape) {
            $file = $shape['file'];
            $layers[$file] ??= ['id' => hash('sha256', $file), 'file' => $file, 'sha256' => $shape['sha256'], 'source' => $shape['source'], 'edition' => $shape['edition'], 'indices' => [], 'codes' => []];
            $layers[$file]['indices'][] = (int) $shape['feature_index'];
            $layers[$file]['codes'][(string) $shape['feature_index']] = $shape['lgd'];
        }

        return ['state' => $state, 'district' => $district, 'subdistrict' => $subdistrict, 'layers' => array_values($layers)];
    }

    /** @return array<int, array{file: string, feature_index: int, sha256: string}> */
    private function villageShapes(PDO $database, string $code): array
    {
        $states = $database->query('SELECT DISTINCT state FROM main.shapes')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($database->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $attached) {
            if ($attached['name'] === 'display_links') {
                $states = array_merge($states, $database->query('SELECT DISTINCT state FROM display_links.shapes')->fetchAll(PDO::FETCH_COLUMN));
            }
        }
        $hasNullState = in_array(null, $states, true);
        $states = array_values(array_unique(array_filter($states, fn ($state): bool => $state !== null), SORT_STRING));
        if ($states === [] && ! $hasNullState) {
            return [];
        }
        $predicate = $states === [] ? 's.state IS NULL' : 's.state IN ('.implode(',', array_fill(0, count($states), '?')).')';
        if ($states !== [] && $hasNullState) {
            $predicate = '('.$predicate.' OR s.state IS NULL)';
        }
        $statement = $database->prepare('SELECT s.file,s.feature_index,l.sha256 FROM shapes s JOIN layers l ON l.file=s.file WHERE s.lgd=? AND '.$predicate.' ORDER BY s.file,s.feature_index');
        $statement->execute([$code, ...$states]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mapVillage(string $code, IndiaVillageMap $map): array
    {
        $shapes = $this->villageShapes($this->database(), $code);
        $layers = collect($map->catalogue()['layers'])->keyBy('file');
        foreach ($shapes as $shape) {
            $layer = $layers->get($shape['file']);
            if ($layer && hash_equals($layer['sha256'], $shape['sha256'])) {
                return $map->feature($layer['id'], (int) $shape['feature_index']);
            }
        }
        $village = $this->village($code);

        return array_replace($village, ['lgd_code' => $code, 'code' => $code, 'state' => $village['state_name'], 'url' => route('electoral-villages.village', ['code' => $code])]);
    }

    public function geometryPath(string $id): string
    {
        $statement = $this->database()->prepare('SELECT file,sha256 FROM layers');
        $statement->execute();
        $layer = collect($statement->fetchAll(PDO::FETCH_ASSOC))->first(fn (array $row): bool => hash_equals(hash('sha256', $row['file']), $id));
        abort_unless($layer, 404);
        $root = realpath(public_path('maps'));
        $path = realpath(public_path($layer['file']));
        abort_unless($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path), 404);
        abort_unless(hash_equals($layer['sha256'], hash_file('sha256', $path)), 409, 'This district layer changed; its code links need refreshing.');

        return $path;
    }

    public function villageRecords(string $code): array
    {
        $statement = $this->database()->prepare('SELECT d.*,s.name AS state_name FROM directory d JOIN states s ON s.code=d.state WHERE lgd=?');
        $statement->execute([$code]);
        $village = $statement->fetch(PDO::FETCH_ASSOC);
        abort_unless($village, 404);

        return array_replace($village, ['url' => route('electoral-villages.village', ['code' => $code])], app(VillageProfileLinks::class)->records($village, true));
    }

    public function village(string $code, bool $identityOnly = false): array
    {
        $statement = $this->database()->prepare('SELECT d.*,s.name AS state_name FROM directory d JOIN states s ON s.code=d.state WHERE lgd=?');
        $statement->execute([$code]);
        $village = $statement->fetch(PDO::FETCH_ASSOC);
        abort_unless($village, 404);
        if ($identityOnly) {
            return $village;
        }
        $statement = $this->database()->prepare("SELECT file,feature_index FROM shapes WHERE lgd=? AND state=? AND file LIKE '%.json'");
        $statement->execute([$code, $village['state']]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $shape) {
            $path = $this->geometryPath(hash('sha256', $shape['file']));
            $source = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $row = $source['rows'][(int) $shape['feature_index']] ?? null;
            if ($row && ($source['year'] ?? null) === 2011 && $row['code'] === $village['census2011'] && ($row['geometry_status'] ?? null) === 'code_linked' && isset($row['census'])) {
                $village['census'] = $row['census'];
                $village['census_source'] = $source['sources']['census'];
                break;
            }
        }

        return array_replace($village, app(VillageProfileLinks::class)->records($village));
    }

    public function connections(string $code): array
    {
        $database = $this->database();
        $statement = $database->prepare('SELECT d.*,s.name AS state_name,a.district AS district_code,a.subdistrict AS subdistrict_code FROM directory d JOIN states s ON s.code=d.state LEFT JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state WHERE d.lgd=?');
        $statement->execute([$code]);
        $village = $statement->fetch(PDO::FETCH_ASSOC);
        if (! $village) {
            return [];
        }
        $statement = $database->prepare("SELECT m.source_row,m.pc,m.ac,m.coverage,m.entity_type FROM membership m WHERE m.state=:state AND ((m.entity_type='Village' AND m.entity_code=:village) OR (m.coverage='Fully Covered' AND ((m.entity_type='District' AND m.entity_code=:district) OR (m.entity_type='SubDistrict' AND m.entity_code=:subdistrict))))");
        $statement->execute(['state' => $village['state'], 'village' => $code, 'district' => $village['district_code'], 'subdistrict' => $village['subdistrict_code']]);
        $memberships = $statement->fetchAll(PDO::FETCH_ASSOC);
        $seats = [];
        $seatQuery = $database->prepare('SELECT name FROM seats WHERE state=? AND kind=? AND code=?');
        foreach ($memberships as $membership) {
            foreach (['pc', 'ac'] as $kind) {
                $seatCode = $membership[$kind];
                $key = $kind.':'.$seatCode;
                if (! isset($seats[$key])) {
                    $seatQuery->execute([$village['state'], $kind, $seatCode]);
                    $name = $seatQuery->fetchColumn();
                    if ($name === false) {
                        continue;
                    }
                    $seats[$key] = ['kind' => $kind, 'code' => $seatCode, 'name' => $name, 'partial' => false, 'source_rows' => [],
                        'url' => ConstituencyUrls::url(['state' => $village['state_name'], 'kind' => $kind, 'name' => $name])];
                }
                $seats[$key]['partial'] = $seats[$key]['partial'] || $membership['coverage'] === 'Partly Covered';
                $seats[$key]['source_rows'][] = (int) $membership['source_row'];
            }
        }
        $audit = json_decode($database->query('SELECT json FROM metadata')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);

        return ['administration' => $village, 'constituencies' => array_values($seats), 'relationship_source' => [
            'url' => $audit['source_url'], 'checked_on' => $audit['checked_on'], 'files' => $audit['sources'] ?? [],
        ]];
    }
}
