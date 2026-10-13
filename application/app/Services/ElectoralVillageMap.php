<?php

namespace App\Services;

use PDO;

class ElectoralVillageMap
{
    public function directory(): string
    {
        return storage_path('app/electoral-villages');
    }

    private function database(): PDO
    {
        $path = $this->directory().'/links.sqlite';
        abort_unless(is_file($path), 503, 'The official LGD constituency mapping is not available yet.');
        $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
            $layers[$file] ??= ['id' => hash('sha256', $file), 'sha256' => $shape['sha256'], 'source' => $shape['source'], 'edition' => $shape['edition'], 'indices' => [], 'codes' => []];
            $layers[$file]['indices'][] = (int) $shape['feature_index'];
            $layers[$file]['codes'][(string) $shape['feature_index']] = $shape['lgd'];
        }
        $statement = $database->prepare($resolved.'SELECT COUNT(*) AS total,COALESCE(SUM(partial),0) AS partial FROM villages');
        $statement->execute(['state' => $state, 'seat' => $seat]);
        $villageCounts = $statement->fetch(PDO::FETCH_ASSOC);
        $linkedCodes = array_unique(array_column($shapes, 'lgd'));

        return ['state' => $state, 'kind' => $kind, 'code' => $seat, 'name' => $name, 'entities' => $entities, 'layers' => array_values($layers),
            'counts' => ['villages' => (int) $villageCounts['total'], 'partly_covered_villages' => (int) $villageCounts['partial'],
                'linked_villages' => count($linkedCodes), 'linked_shapes' => count($shapes), 'villages_without_displayed_shape' => (int) $villageCounts['total'] - count($linkedCodes)],
            'note' => 'Official LGD membership checked 2026-10-13. Fully covered district/subdistrict memberships expand through the official village-directory hierarchy. Only fully covered villages with checked code identities are displayed. Partial coverage, missing polygons and unverified boundary editions remain explicit. No population or soil totals are inferred.'];
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

    public function village(string $code): array
    {
        $statement = $this->database()->prepare('SELECT d.*,s.name AS state_name FROM directory d JOIN states s ON s.code=d.state WHERE lgd=?');
        $statement->execute([$code]);
        $village = $statement->fetch(PDO::FETCH_ASSOC);
        abort_unless($village, 404);
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

        return $village;
    }
}
