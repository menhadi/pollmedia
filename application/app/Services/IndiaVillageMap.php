<?php

namespace App\Services;

use PDO;

class IndiaVillageMap
{
    public function directory(): string
    {
        return storage_path('app/india-map');
    }

    public function catalogue(): array
    {
        $path = $this->directory().'/catalogue.json';
        abort_unless(is_file($path), 503, 'The national village catalogue is not available yet.');

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function database(): PDO
    {
        $path = $this->directory().'/villages.sqlite';
        abort_unless(is_file($path), 503, 'Village search is temporarily unavailable.');
        $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('PRAGMA query_only = ON');

        return $database;
    }

    public function search(string $query, string $state = '', string $layer = ''): array
    {
        preg_match_all('/[\p{L}\p{N}\p{M}]+/u', $query, $matches);
        $terms = array_slice($matches[0], 0, 8);
        if ($terms === []) {
            return [];
        }
        $expression = implode(' AND ', array_map(fn (string $term): string => '"'.$term.'"*', $terms));
        $sql = 'SELECT p.id,p.layer,p.feature_index,p.source_id,p.name,p.code,p.subdistrict,p.state,p.district,p.bbox FROM search JOIN places p ON p.rowid=search.rowid WHERE search MATCH :query';
        $parameters = ['query' => $expression];
        if ($state !== '') {
            $sql .= ' AND p.state=:state';
            $parameters['state'] = $state;
        }
        if ($layer !== '') {
            $sql .= ' AND p.layer=:layer';
            $parameters['layer'] = $layer;
        }
        $statement = $this->database()->prepare($sql.' ORDER BY rank,p.name,p.id LIMIT 25');
        $statement->execute($parameters);

        return array_map($this->summary(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function place(string $id): array
    {
        $statement = $this->database()->prepare('SELECT * FROM places WHERE id=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (! $row) {
            foreach ($this->catalogue()['context_layers'] ?? [] as $layer) {
                if ($layer['context_place']['id'] === $id) {
                    return $layer['context_place'];
                }
            }
        }
        abort_unless($row, 404);
        $row['properties'] = json_decode($row['properties'], true, flags: JSON_THROW_ON_ERROR);

        return $this->summary($row);
    }

    public function feature(string $layer, int $index): array
    {
        $source = $this->layer($layer);
        if (($source['kind'] ?? '') === 'district') {
            abort_unless($index === $source['context_place']['feature_index'], 404);

            return $source['context_place'];
        }
        $statement = $this->database()->prepare('SELECT id FROM places WHERE layer=:layer AND feature_index=:position UNION ALL SELECT place_id FROM feature_aliases WHERE layer=:layer AND feature_index=:position LIMIT 1');
        $statement->execute(['layer' => $layer, 'position' => $index]);
        $id = $statement->fetchColumn();
        abort_unless($id, 404, 'This duplicate or unusable source shape is not in the national index.');

        return $this->place($id);
    }

    public function layer(string $id): array
    {
        $catalogue = $this->catalogue();
        $layer = collect(array_merge($catalogue['layers'], $catalogue['context_layers'] ?? []))->firstWhere('id', $id);
        abort_unless($layer, 404);

        return $layer;
    }

    public function geometryPath(array $layer): string
    {
        $snapshot = $this->directory().'/snapshot/'.$layer['file'];
        $path = realpath(is_file($snapshot) ? $snapshot : public_path($layer['file']));
        $root = realpath(is_file($snapshot) ? $this->directory().'/snapshot/maps' : public_path('maps'));
        abort_unless($path && $root && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path), 404);
        abort_unless(hash_equals($layer['sha256'], hash_file('sha256', $path)), 409, 'This layer changed after the coverage audit. Rebuild the catalogue before using it.');

        return $path;
    }

    private function summary(array $row): array
    {
        $row['bbox'] = json_decode($row['bbox'], true, flags: JSON_THROW_ON_ERROR);
        $row['feature_index'] = (int) $row['feature_index'];
        $row['url'] = route('india-map.place', ['id' => $row['id']]);

        return $row;
    }
}
