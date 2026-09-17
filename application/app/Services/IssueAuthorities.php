<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IssueAuthorities
{
    public function available(string $issue): Collection
    {
        return DB::table('office_jurisdictions as j')->join('offices as o', 'o.id', '=', 'j.office_id')
            ->join('source_releases as r', 'r.id', '=', 'j.source_release_id')->where('r.status', 'accepted')
            ->whereIn('j.place_id', DB::table('citizen_issue_places')->where('issue_id', $issue)->select('place_id'))
            ->where(fn ($q) => $q->whereNull('j.valid_from')->orWhere('j.valid_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('j.valid_to')->orWhere('j.valid_to', '>', today()->toDateString()))
            ->select('o.id', 'o.title', 'o.kind', 'r.url')->distinct()->orderBy('o.title')->get();
    }

    public function holders(array $offices): Collection
    {
        return DB::table('office_assignments as a')->join('people as p', 'p.id', '=', 'a.person_id')
            ->join('source_releases as r', 'r.id', '=', 'a.source_release_id')
            ->whereIn('a.office_id', $offices)->where('r.status', 'accepted')->whereNull('a.superseded_at')
            ->whereIn('a.status', ['last_verified', 'confirmed', 'acting', 'additional_charge'])
            ->where(fn ($q) => $q->whereNull('a.effective_from')->orWhere('a.effective_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('a.effective_to')->orWhere('a.effective_to', '>', today()->toDateString()))
            ->select('a.office_id', 'p.display_name', 'a.verified_at', 'a.status', 'r.url')->get()->groupBy('office_id');
    }
}
