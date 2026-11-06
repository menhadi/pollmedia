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
        $row = self::$records[$id] ??= DB::table('census_catalogue_rows as r')->join('census_editions as e', 'e.id', '=', 'r.edition_id')
            ->where('r.id', $id)->where('e.status', 'published')->select('r.*', 'e.year as census_year')->first();
        if (! $row) {
            return route('civic.place', ['record' => $id], $absolute);
        }

        return $this->fromPublishedCensusRow($row, $absolute);
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
