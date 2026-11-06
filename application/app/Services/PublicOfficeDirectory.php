<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PublicOfficeDirectory
{
    /** @param array<int, int> $placeIds */
    public function forPlaces(array $placeIds): Collection
    {
        if ($placeIds === []) {
            return collect();
        }

        $today = today()->toDateString();
        $people = DB::table('office_assignments as a')
            ->join('people as p', 'p.id', '=', 'a.person_id')
            ->join('offices as o', 'o.id', '=', 'a.office_id')
            ->join('office_jurisdictions as j', 'j.office_id', '=', 'o.id')
            ->join('places as g', 'g.id', '=', 'j.place_id')
            ->join('source_releases as r', 'r.id', '=', 'a.source_release_id')
            ->whereIn('j.place_id', $placeIds)
            ->whereNull('a.superseded_at')
            ->where(fn ($query) => $query->whereNull('j.valid_from')->orWhere('j.valid_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('j.valid_to')->orWhere('j.valid_to', '>', $today))
            ->where(fn ($query) => $query->whereNull('a.effective_from')->orWhere('a.effective_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('a.effective_to')->orWhere('a.effective_to', '>', $today))
            ->whereIn('a.status', ['last_verified', 'confirmed', 'acting', 'additional_charge'])
            ->where('r.status', 'accepted')
            ->select('p.id as person_id', 'p.display_name', 'o.title', 'o.kind', 'g.name as area', 'g.type as area_type', 'a.verified_at', 'r.url')
            ->distinct()->orderBy('o.kind')->orderBy('o.title')->orderBy('p.display_name')->get();

        $profiles = $people->isEmpty() ? collect() : DB::table('public_profiles')->whereIn('person_id', $people->pluck('person_id')->unique())->get()->groupBy('person_id');
        foreach ($people as $person) {
            $person->profiles = $profiles->get($person->person_id, collect());
        }

        return $people;
    }
}
