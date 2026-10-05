<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CensusPlaceNavigation
{
    public function record(object $place): ?int
    {
        if ($place->type !== 'district') {
            return null;
        }
        $codes = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
            ->where('i.place_id', $place->id)->where('r.status', 'accepted')
            ->where('i.namespace', 'census:district:IN:UP')->where('i.version', '2011')->distinct()->pluck('i.code');
        if ($codes->isEmpty() && mb_strtolower($place->name) === 'pilibhit' && $place->country_code === 'IN') {
            // The archived Pilibhit LGD village directory identifies district 173; its Census 2011 district is 151.
            $lgd = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
                ->where('i.place_id', $place->id)->where('r.status', 'accepted')->where('i.namespace', 'lgd:IN:district')->where('i.code', '173')->exists();
            if ($lgd) {
                $codes = collect(['151']);
            }
        }
        if ($codes->count() !== 1) {
            return null;
        }
        $rows = DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
            ->where('e.status', 'published')->where('e.year', 2011)->where('c.state_code', '09')
            ->where('c.district_code', $codes->first())->where('c.level', 'DISTRICT')->where('c.residence', 'Total')
            ->orderByRaw("CASE WHEN e.source_key = 'india-basic-2011-total' THEN 0 ELSE 1 END")
            ->orderBy('e.id')->orderBy('c.id')->value('c.id');

        return $rows ? (int) $rows : null;
    }

    public function url(object $place): string
    {
        $record = $this->record($place);

        return $record ? route('civic.place', ['record' => $record]) : route('geography.show', $place->slug);
    }
}
