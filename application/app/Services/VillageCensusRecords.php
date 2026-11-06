<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class VillageCensusRecords
{
    public static function name(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(preg_replace('/\s*\(\s*\d+\s*\)\s*$/u', '', trim($name))));
    }

    public function sourceMatches(string $stateName, array $features, ?array $layer = null): array
    {
        if (! Schema::hasTable('census_publications') || $features === []) {
            return [];
        }
        $state = collect(app(ElectoralVillageMap::class)->database()->query('SELECT code,name FROM states')->fetchAll(\PDO::FETCH_ASSOC))
            ->first(fn (array $row): bool => self::name($row['name']) === self::name($stateName));
        if (! $state) {
            return [];
        }
        $states = match ($state['code']) {
            '36' => ['28'], '37' => ['01'], '38' => ['25', '26'],
            default => [str_pad($state['code'], 2, '0', STR_PAD_LEFT)],
        };
        $byCode = [];
        foreach ($features as $index => $properties) {
            $code = (string) ($properties['source_code'] ?? '');
            if (! preg_match('/^[0-9]{1,6}$/', $code) || (int) $code <= 0 || (int) $code >= 999999) {
                continue;
            }
            $byCode[str_pad($code, 6, '0', STR_PAD_LEFT)][] = [$index, $properties];
        }
        $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->join('census_publications as p', function ($join): void {
                $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
            })->where('e.status', 'published')->where('e.year', 2011)->whereIn('c.state_code', $states)
            ->where(function ($query): void {
                $query->where(fn ($rural) => $rural->where('c.level', 'VILLAGE')->where('c.residence', 'Rural'))
                    ->orWhere(fn ($urban) => $urban->where('c.level', 'TOWN')->where('c.residence', 'Urban'));
            })->whereIn('c.geography->Town/Village', array_map('strval', array_keys($byCode)))
            ->select('c.*', 'e.source_key', 'e.source_url', 'e.sha256 as source_sha256')
            ->orderByDesc('e.retrieved_at')->orderByDesc('e.id')->get()
            ->groupBy(fn (object $row): string => (string) json_decode($row->geography, true)['Town/Village']);
        $matches = [];
        foreach ($byCode as $code => $shapes) {
            $records = $rows->get((string) $code, collect());
            $identities = $records->map(function (object $row): string {
                $geo = json_decode($row->geography, true);

                return implode(':', [$row->level, $row->residence, $row->state_code, $row->district_code, $geo['Subdistt'] ?? '', $geo['Town/Village']]);
            })->unique();
            $row = $records->first();
            if (! $row || ($row->level !== 'TOWN' && (count($shapes) !== 1 || ($shapes[0][1]['status'] ?? '') === 'ambiguous'))) {
                continue;
            }
            if ($row->level === 'TOWN' && collect($shapes)->contains(fn (array $shape): bool => self::name($shape[1]['name'] ?? '') !== self::name(app(CommunityData::class)->sourceName($row)))) {
                continue;
            }
            if ($identities->count() === 1 && $row && self::name(app(CommunityData::class)->sourceName($row)) === self::name($shapes[0][1]['name'] ?? '')) {
                foreach ($shapes as $shape) {
                    $matches[$shape[0]] = $row;
                }
            }
        }

        if ($layer) {
            foreach ($this->qualifiedTowns($layer, $features, $states) as $index => $row) {
                $matches[$index] = $row;
            }
        }

        return $matches;
    }

    private function qualifiedTowns(array $layer, array $features, array $states): array
    {
        $directory = app(ElectoralVillageMap::class)->directory();
        $path = $directory.'/urban-map-links/'.($layer['id'] ?? '').'.json';
        if (! is_file($path)) {
            if (is_dir($directory.'/urban-map-links')) {
                return [];
            }
            $path = $directory.'/urban-map-links.json';
        }
        if (! is_file($path) || ! isset($layer['id'], $layer['sha256'])) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (($data['year'] ?? null) !== 2011) {
            return [];
        }
        $source = $data['layers'][$layer['id']] ?? null;
        if (! $source || ! hash_equals($source['sha256'], $layer['sha256']) || $source['file'] !== $layer['file']) {
            return [];
        }
        $identities = [];
        foreach ($source['records'] as $index => $identity) {
            $properties = $features[$index] ?? null;
            if ($properties && (string) ($properties['source_code'] ?? '') === (string) $identity['source_code'] && (string) ($properties['name'] ?? '') === (string) $identity['source_name']) {
                $identities[$index] = $identity;
            }
        }
        if ($identities === []) {
            return [];
        }
        $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->join('census_publications as p', function ($join): void {
                $join->on('p.edition_id', '=', 'e.id')->on('p.source_key', '=', 'e.source_key');
            })->where('e.status', 'published')->where('e.year', 2011)->where('c.level', 'TOWN')->where('c.residence', 'Urban')
            ->whereIn('c.state_code', $states)->whereIn('c.id', array_column($identities, 'record_id'))
            ->select('c.*', 'e.source_key', 'e.source_url', 'e.sha256 as source_sha256')->get()->keyBy('id');
        $matches = [];
        foreach ($identities as $index => $identity) {
            $row = $rows->get($identity['record_id']);
            if ($row && (int) $row->edition_id === (int) $identity['edition_id'] && $row->record_key === $identity['record_key'] && hash_equals($row->source_sha256, $identity['source_sha256'])) {
                $row->map_link_basis = $identity['basis'];
                $matches[$index] = $row;
            }
        }

        return $matches;
    }

    public function profile(object $row): array
    {
        $values = json_decode($row->values, true);
        $geo = json_decode($row->geography, true);
        $metrics = VillageMapMetrics::values($values);
        $navigation = [];
        if (Route::has('district-dashboard')) {
            $navigation[] = ['label' => 'District dashboard · Census 2011', 'url' => route('district-dashboard', ['state' => $row->state_code, 'district' => $row->district_code])];
        }

        return ['url' => route('civic.place', ['record' => $row->id]), 'census_url' => route('civic.place', ['record' => $row->id]),
            'kind' => $row->level === 'TOWN' ? 'town' : 'village',
            'census_name' => $row->name,
            'link_basis' => $row->map_link_basis ?? 'Unique Census 2011 code and settlement name in the official state inventory; boundary edition unverified',
            'census_source' => $row->source_url, 'navigation' => $navigation,
            'census' => ['year' => 2011, 'code' => $geo['Town/Village'], 'population' => $metrics['population'],
                'households' => $metrics['households'], 'literacy_rate' => $metrics['literacy'],
                'literate' => $values['P_LIT'] ?? null, 'children' => $values['P_06'] ?? null,
                'record_id' => $row->id, 'record_key' => $row->record_key, 'edition_id' => $row->edition_id,
                'source_row' => $row->source_row, 'source_key' => $row->source_key, 'source_sha256' => $row->source_sha256,
                'flags' => json_decode($row->flags, true)]];
    }
}
