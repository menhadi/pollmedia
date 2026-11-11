<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;

class PlaceLabels
{
    /**
     * Official place-type labels for every source feature in a district layer.
     *
     * Each label names the place exactly as the official record that defines it:
     * Census 2011 for towns, the LGD village directory for villages, and the
     * source layer classification for features that are neither. A feature whose
     * type cannot be established from an official record is left unclassified
     * rather than guessed.
     *
     * @param  array<string, mixed>  $layer
     * @return array<int, array{level: string|null, name: string|null, code: string|null, source: string|null}>
     */
    public function forLayer(array $layer, ?PDO $links): array
    {
        if (! $links || empty($layer['id']) || empty($layer['state']) || empty($layer['district'])) {
            return [];
        }

        $features = $this->features((string) $layer['id']);
        if ($features === []) {
            return [];
        }

        $state = $this->stateCode($links, (string) $layer['state']);
        if ($state === null) {
            return [];
        }

        $linked = $this->linkedCodes($links, $layer);
        $codes = [];
        foreach ($features as $feature) {
            $index = (int) $feature['feature_index'];
            foreach ([$linked[$index] ?? null, $feature['code'] ?? null] as $candidate) {
                $candidate = (string) $candidate;
                if ($candidate !== '' && $candidate !== '0') {
                    $codes[$candidate] = true;
                }
            }
        }

        $directory = $this->directoryNames($links, $state, array_keys($codes));
        $district = $this->censusDistrictCode($state, (string) $layer['district']);
        $towns = $district === null ? [] : $this->censusTowns($state, $district, array_keys($codes));
        $censusVillages = $district === null ? [] : $this->censusVillages($state, $district, array_keys($codes));
        $directoryByCensus = $this->directoryByCensus($links, $state, (string) $layer['district']);

        $labels = [];
        foreach ($features as $feature) {
            $index = (int) $feature['feature_index'];
            $linkedCode = isset($linked[$index]) ? (string) $linked[$index] : null;
            $sourceCode = (string) ($feature['code'] ?? '');
            $code = $linkedCode ?? $sourceCode;
            $labels[$index] = match (true) {
                $sourceCode !== '' && isset($towns[$sourceCode]) => ['level' => 'TOWN', 'name' => $towns[$sourceCode], 'code' => $sourceCode, 'source' => 'Census 2011 town directory'],
                $linkedCode !== null && isset($towns[$linkedCode]) => ['level' => 'TOWN', 'name' => $towns[$linkedCode], 'code' => $linkedCode, 'source' => 'Census 2011 town directory'],
                $linkedCode !== null && isset($directory[$linkedCode]) => ['level' => 'VILLAGE', 'name' => $directory[$linkedCode], 'code' => $linkedCode, 'source' => 'LGD village directory'],
                isset($directory[$sourceCode]) => ['level' => 'VILLAGE', 'name' => $directory[$sourceCode], 'code' => $sourceCode, 'source' => 'LGD village directory'],
                isset($censusVillages[$sourceCode]) => ['level' => 'VILLAGE',
                    'name' => $directoryByCensus[$sourceCode] ?? $censusVillages[$sourceCode],
                    'code' => $sourceCode,
                    'source' => isset($directoryByCensus[$sourceCode]) ? 'LGD village directory (Census 2011 code)' : 'Census 2011 village directory'],
                ($feature['category'] ?? '') === 'OTHERS' => ['level' => 'OTHER', 'name' => (string) ($feature['name'] ?? ''), 'code' => $code, 'source' => 'SOI source classification'],
                default => ['level' => null, 'name' => null, 'code' => $code, 'source' => null],
            };
        }

        return $labels;
    }

    /**
     * @return array<int, array{feature_index: int, name: string|null, code: string|null, category: string|null}>
     */
    private function features(string $layer): array
    {
        $path = storage_path('app/india-map/villages.sqlite');
        if (! is_file($path)) {
            return [];
        }
        $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('PRAGMA query_only = ON');
        $statement = $database->prepare('SELECT feature_index,name,code,properties FROM places WHERE layer=? ORDER BY feature_index');
        $statement->execute([$layer]);

        return array_map(fn (array $row): array => [
            'feature_index' => (int) $row['feature_index'],
            'name' => $row['name'],
            'code' => $row['code'],
            'category' => json_decode((string) $row['properties'], true)['category'] ?? null,
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function stateCode(PDO $links, string $stateName): ?string
    {
        $statement = $links->prepare('SELECT code FROM states WHERE lower(name)=lower(?) LIMIT 1');
        $statement->execute([$stateName]);
        $code = $statement->fetchColumn();

        return $code === false ? null : (string) $code;
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array<int, string>
     */
    private function linkedCodes(PDO $links, array $layer): array
    {
        $statement = $links->prepare('SELECT feature_index,lgd FROM shapes WHERE file=? AND lgd IS NOT NULL');
        $statement->execute([$layer['file']]);
        $codes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $codes[(int) $row['feature_index']] = (string) $row['lgd'];
        }

        return $codes;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, string>
     */
    private function directoryNames(PDO $links, string $state, array $codes): array
    {
        $names = [];
        foreach (array_chunk($codes, 500) as $chunk) {
            $statement = $links->prepare('SELECT lgd,name FROM directory WHERE state=? AND lgd IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
            $statement->execute([$state, ...$chunk]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names[(string) $row['lgd']] = (string) $row['name'];
            }
        }

        return $names;
    }

    private function censusDistrictCode(string $state, string $district): ?string
    {
        if (! DB::getSchemaBuilder()->hasTable('census_catalogue_rows')) {
            return null;
        }
        $rows = DB::table('census_catalogue_rows as c')
            ->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->where('e.year', 2011)
            ->where('c.level', 'DISTRICT')
            ->where('c.state_code', str_pad($state, 2, '0', STR_PAD_LEFT))
            ->where('c.name', $district)
            ->select('c.geography')
            ->limit(5)
            ->get();
        $codes = $rows->map(fn (object $row): string => (string) (json_decode($row->geography, true)['District'] ?? ''))
            ->filter()
            ->unique();

        return $codes->count() === 1 ? $codes->first() : null;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, string>
     */
    private function censusTowns(string $state, string $district, array $codes): array
    {
        return $this->censusSettlements($state, $district, $codes, 'TOWN', 'Urban');
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, string>
     */
    private function censusVillages(string $state, string $district, array $codes): array
    {
        return $this->censusSettlements($state, $district, $codes, 'VILLAGE', 'Rural');
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, string>
     */
    private function censusSettlements(string $state, string $district, array $codes, string $level, string $residence): array
    {
        $settlements = [];
        foreach (array_chunk($codes, 500) as $chunk) {
            $rows = DB::table('census_catalogue_rows as c')
                ->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                ->where('e.year', 2011)
                ->where('c.level', $level)
                ->where('c.residence', $residence)
                ->where('c.state_code', str_pad($state, 2, '0', STR_PAD_LEFT))
                ->where('c.geography->District', $district)
                ->whereIn('c.geography->Town/Village', array_map('strval', $chunk))
                ->select('c.name', 'c.geography')
                ->orderBy('c.id')
                ->get();
            foreach ($rows as $row) {
                $code = (string) (json_decode($row->geography, true)['Town/Village'] ?? '');
                if ($code !== '' && ! isset($settlements[$code])) {
                    $settlements[$code] = (string) $row->name;
                }
            }
        }

        return $settlements;
    }

    /**
     * LGD village names keyed by Census 2011 code for one district, unique only.
     *
     * @return array<string, string>
     */
    private function directoryByCensus(PDO $links, string $state, string $district): array
    {
        $statement = $links->prepare('SELECT lgd,name,census2011 FROM directory WHERE state=? AND district=?');
        $statement->execute([$state, $district]);
        $names = [];
        $ambiguous = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['census2011'];
            if ($code === '' || $code === '0') {
                continue;
            }
            if (isset($names[$code]) && $names[$code] !== (string) $row['name']) {
                $ambiguous[$code] = true;
                continue;
            }
            $names[$code] = (string) $row['name'];
        }

        return array_diff_key($names, $ambiguous);
    }
}
