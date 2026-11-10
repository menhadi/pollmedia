<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

class PlacePostalCodes
{
    public function __construct(private ?string $path = null) {}

    private function database(): ?PDO
    {
        $path = $this->path ?? storage_path('app/postal-places/links.sqlite');
        if (! is_file($path)) {
            return null;
        }

        return new PDO('sqlite:file:'.str_replace('\\', '/', $path).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function village(array $village): array
    {
        $identity = $village['administration'] ?? [];

        return $this->lookup('village', (string) ($village['lgd'] ?? ''), (string) ($village['state'] ?? ''), [
            'district' => (string) ($identity['district_code'] ?? ''),
            'subdistrict' => (string) ($identity['subdistrict_code'] ?? ''),
        ]);
    }

    public function lookup(string $kind, string $code, string $state = '', array $parents = []): array
    {
        $empty = ['status' => 'unavailable', 'pins' => [], 'place' => null, 'source' => null];
        if (! in_array($kind, ['village', 'local-body'], true) || ! ctype_digit($code)) {
            return $empty;
        }
        try {
            $db = $this->database();
            if (! $db) {
                return $empty;
            }
            $statement = $db->prepare('SELECT * FROM places WHERE kind=? AND code=? ORDER BY source_row');
            $statement->execute([$kind, $code]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $metadata = $db->prepare('SELECT json FROM metadata WHERE kind=?');
            $metadata->execute([$kind]);
            $source = json_decode($metadata->fetchColumn() ?: 'null', true, flags: JSON_THROW_ON_ERROR);
            $empty['source'] = $source;
            if (! $rows) {
                return $empty;
            }
            foreach ($rows as $row) {
                if (($state !== '' && $row['state'] !== $state) || $row['state'] !== $rows[0]['state']) {
                    return [...$empty, 'status' => 'identity_mismatch'];
                }
                foreach ($parents as $key => $value) {
                    if (in_array($key, ['district', 'subdistrict'], true) && $value !== '' && $row[$key] !== $value) {
                        return [...$empty, 'status' => 'identity_mismatch'];
                    }
                }
            }
            $pins = [];
            $hasUnusable = false;
            foreach ($rows as $row) {
                if ($row['status'] === 'reported' && preg_match('/^[1-9][0-9]{5}$/D', (string) $row['pin'])) {
                    $pins[] = $row['pin'];
                } else {
                    $hasUnusable = true;
                }
            }
            $pins = array_values(array_unique($pins));
            sort($pins, SORT_STRING);

            return ['status' => ! $pins ? 'not_reported' : (count($pins) > 1 ? 'multiple' : 'reported'),
                'pins' => $pins, 'place' => $rows[0], 'source' => $source, 'has_unusable_rows' => $hasUnusable];
        } catch (\Throwable $exception) {
            report($exception);

            return $empty;
        }
    }

    public function suggestions(string $pin): array
    {
        if (! preg_match('/^[1-9][0-9]{5}$/D', $pin)) {
            return [];
        }
        $result = $this->search($pin);
        $suggestions = [['label' => 'PIN '.$pin, 'type' => 'Postal PIN', 'rank' => 0,
            'period' => $result['available'] ? number_format($result['total']).' linked areas · view all' : 'Open postal directory',
            'url' => route('postal-areas.index', ['pin' => $pin])]];
        foreach (app(PostalPlaceNavigation::class)->rows(array_slice($result['rows'], 0, 8)) as $row) {
            $suggestions[] = ['label' => $row['name'], 'type' => $row['kind'] === 'village' ? 'Village · PIN '.$pin : 'Urban local body · PIN '.$pin,
                'rank' => 1, 'period' => implode(', ', array_filter([$row['subdistrict_name'], $row['district_name'], $row['state_name']])),
                'url' => $row['place_url']];
        }

        return $suggestions;
    }

    public function search(string $pin, int $page = 1, string $query = ''): array
    {
        $empty = ['rows' => [], 'total' => 0, 'available' => false, 'page' => $page];
        if (! preg_match('/^[1-9][0-9]{5}$/D', $pin)) {
            return $empty;
        }
        try {
            $db = $this->database();
            if (! $db) {
                return $empty;
            }
            $query = trim($query);
            $filter = $query === '' ? '' : ' AND lower(name) LIKE ?';
            $bindings = [$pin];
            if ($query !== '') {
                $bindings[] = '%'.mb_strtolower($query).'%';
            }
            $count = $db->prepare('SELECT count(*) FROM (SELECT kind,code FROM places WHERE pin=?'.$filter.' GROUP BY kind,code)');
            $count->execute($bindings);
            $statement = $db->prepare('SELECT kind,code,state,district,subdistrict,name,state_name,district_name,subdistrict_name FROM places WHERE pin=?'.$filter.' GROUP BY kind,code ORDER BY kind,state_name,district_name,name,code LIMIT 50 OFFSET ?');
            $index = 1;
            foreach ($bindings as $binding) {
                $statement->bindValue($index++, $binding);
            }
            $statement->bindValue($index, (max(1, $page) - 1) * 50, PDO::PARAM_INT);
            $statement->execute();

            return ['rows' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn(), 'available' => true, 'page' => $page];
        } catch (\Throwable $exception) {
            report($exception);

            return $empty;
        }
    }

    public function historicalReference(array $place): ?array
    {
        if (($place['kind'] ?? '') !== 'village' || (string) ($place['code'] ?? '') !== '938441'
            || (string) ($place['state'] ?? '') !== '29' || (string) ($place['district'] ?? '') !== '525'
            || (string) ($place['subdistrict'] ?? '') !== '5543' || ! Schema::hasTable('census_catalogue_rows')) {
            return null;
        }
        $records = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->where('e.status', 'published')->where('e.year', 1991)->where('e.source_key', 'census-pca-urban-43598-1991')
            ->where('c.name', 'Kengeri')->where('c.level', 'WARD')->where('c.state_code', '11')->where('c.district_code', '01')
            ->select('c.id')->get();
        if ($records->count() !== 1) {
            return null;
        }

        return ['url' => route('civic.place', ['record' => $records->first()->id]), 'name' => 'Kengeri · Census 1991 urban record'];
    }
}
