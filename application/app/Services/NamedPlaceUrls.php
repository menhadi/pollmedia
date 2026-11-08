<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NamedPlaceUrls
{
    private static array $records = [];

    private static array $parents = [];

    public static function path(string $kind, string $state, string $district, string $name, int|string $id): string
    {
        if (! in_array($kind, ['subdistricts', 'villages', 'towns', 'lgd-village'], true) || ! preg_match('/^[0-9]+$/', (string) $id)) {
            throw new InvalidArgumentException('Unsupported place identity.');
        }
        $prefix = $kind === 'lgd-village' ? '/india/villages' : '/india/census/'.$kind;

        return $prefix.'/'.(Str::slug($state) ?: 'state').'/'.(Str::slug($district) ?: 'district').'/'.(Str::slug($name) ?: 'place').'-'.$id;
    }

    public function census(object|array|int $record, bool $absolute = true): string
    {
        $id = is_int($record) ? $record : (int) (is_array($record) ? $record['id'] : $record->id);
        $row = $this->publishedRow($id);
        if (! $row) {
            return route('civic.place', ['record' => $id], $absolute);
        }

        return $this->fromPublishedCensusRow($row, $absolute);
    }

    private function publishedRow(int $id): ?object
    {
        return self::$records[$id] ??= DB::table('census_catalogue_rows as r')->join('census_editions as e', 'e.id', '=', 'r.edition_id')
            ->where('r.id', $id)->where('e.status', 'published')->select('r.*', 'e.year as census_year')->first();
    }

    public function primeCensus(iterable $ids): void
    {
        $ids = collect($ids)->filter()->unique()->diff(array_keys(self::$records));
        foreach ($ids->chunk(500) as $chunk) {
            $rows = DB::table('census_catalogue_rows as r')->join('census_editions as e', 'e.id', '=', 'r.edition_id')
                ->where('e.status', 'published')->whereIn('r.id', $chunk)->select('r.id', 'r.level', 'r.name', 'r.edition_id', 'r.state_code', 'r.district_code', 'r.residence', 'r.geography', 'e.year as census_year')->get();
            foreach ($rows as $row) {
                self::$records[$row->id] = $row;
            }
        }
    }

    /** Generate the public destination for a record and its selected Census context. */
    public function link(array $parameters, bool $absolute = true): string
    {
        $id = (int) $parameters['record'];
        unset($parameters['record']);
        $row = $this->publishedRow($id);
        $path = $row ? $this->fromPublishedCensusRow($row, false) : route('civic.place', ['record' => $id], false);
        if ($row?->level === 'STATE') {
            unset($parameters['edition'], $parameters['year']);
        }
        if ($row && (int) $row->census_year === 2011 && ! isset($parameters['edition'])) {
            if ($row->level === 'DISTRICT' && $this->isDistrictOverview($row, $parameters)) {
                $district = app(DistrictDashboardUrls::class)->identity($row->state_code, $row->district_code);
                $path = $district['path'] ?? $path;
            } elseif (in_array($row->level, ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'], true)) {
                $preferred = $this->preferredSubdistrict($row);
                if ($preferred && $preferred !== $id) {
                    $path = $this->census($preferred, false);
                }
            }
        }
        if (! isset($parameters['lang']) && app()->getLocale() === 'hi') {
            $parameters['lang'] = 'hi';
        }
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return ($absolute ? url($path) : $path).($query === '' ? '' : '?'.$query);
    }

    public function isDistrictOverview(object $row, array $parameters): bool
    {
        return ($parameters['group'] ?? 'population') === 'population' && ($parameters['residence'] ?? $row->residence) === 'Total'
            && ! array_key_exists('q', $parameters) && ! array_key_exists('page', $parameters);
    }

    public function preferredSubdistrict(object $row): ?int
    {
        if ($row->residence !== 'Total') {
            return null;
        }
        $geo = json_decode($row->geography, true);
        $code = (string) ($geo['Subdistt'] ?? $geo['TAHSIL'] ?? $geo['SUB-DISTT'] ?? '');
        if ($code === '') {
            return null;
        }
        $key = 'preferred-census-subdistricts:'.$row->state_code.':'.$row->district_code;
        $directory = PublicRequestCache::remember($key, function () use ($row) {
            return DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                ->where('e.status', 'published')->where('e.year', 2011)->where('c.state_code', $row->state_code)->where('c.district_code', $row->district_code)
                ->whereIn('c.level', ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'])->where('c.residence', 'Total')
                ->orderByRaw("CASE WHEN e.source_key LIKE 'district-pca-2011-%' THEN 0 ELSE 1 END")->orderBy('c.id')
                ->get(['c.id', 'c.geography'])->mapToGroups(fn ($r) => [(string) (json_decode($r->geography, true)['Subdistt'] ?? '') => (int) $r->id]);
        });

        return $directory->get($code)?->first();
    }

    /** Use only complete rows already selected through a published Census query. */
    public function fromPublishedCensusRow(object $row, bool $absolute = true): string
    {
        $id = (int) $row->id;
        if ($row->level === 'STATE') {
            return route('civic.state', ['state' => PlaceFactTables::stateSlug($row->name), 'census' => (int) $row->census_year === 2011 ? null : $row->census_year], $absolute);
        }
        $kind = match ($row->level) {
            'SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL' => 'subdistricts', 'VILLAGE' => 'villages', 'TOWN' => 'towns', default => null,
        };
        if (! $kind) {
            return route('civic.place', ['record' => $id], $absolute);
        }
        $state = $this->parentName($row, 'STATE');
        $district = $this->parentName($row, 'DISTRICT');
        $path = self::path($kind, $state, $district, $row->name, $id);

        return $absolute ? url($path) : $path;
    }

    private function parentName(object $row, string $level): string
    {
        $key = $row->edition_id.':'.$row->state_code.':'.($level === 'DISTRICT' ? $row->district_code : '').':'.$level;
        if (isset(self::$parents[$key])) {
            return self::$parents[$key];
        }
        $query = DB::table('census_catalogue_rows as r')->join('census_editions as e', 'e.id', '=', 'r.edition_id')
            ->where('e.status', 'published')->where('e.year', $row->census_year)->where('r.state_code', $row->state_code)->where('r.level', $level)
            ->when($level === 'DISTRICT', fn ($q) => $q->where('r.district_code', $row->district_code))
            ->orderByRaw('CASE WHEN r.edition_id = ? THEN 0 ELSE 1 END', [$row->edition_id])->orderBy('r.id');
        $name = $query->value('r.name');

        return self::$parents[$key] = $name ?: strtolower($level).'-'.($level === 'STATE' ? $row->state_code : $row->district_code);
    }

    public function village(array $village, bool $absolute = true): string
    {
        $path = self::path('lgd-village', $village['state_name'], $village['district'], $village['name'], $village['lgd']);

        return $absolute ? url($path) : $path;
    }
}
