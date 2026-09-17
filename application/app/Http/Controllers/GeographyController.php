<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeographyController extends Controller
{
    public function india(Request $request): View
    {
        $request->merge(['country' => 'IN']);

        return $this->index($request);
    }

    public function index(Request $request): View
    {
        $input = $request->validate(['country' => 'nullable|regex:/^[A-Z]{2}$/', 'type' => 'nullable|string|max:100', 'area' => 'nullable|integer|exists:places,id']);
        $country = $input['country'] ?? '';
        $type = $input['type'] ?? '';
        $area = $input['area'] ?? null;
        $indiaContext = $request->routeIs('geography.india');
        $countries = DB::table('places')->select('country_code')->distinct()->orderBy('country_code')->pluck('country_code');
        $types = DB::table('places')->when($country, fn ($q) => $q->where('country_code', $country))
            ->select('type')->distinct()->orderBy('type')->pluck('type');
        $links = DB::table('place_relationships as relation')->join('source_releases as r', 'r.id', '=', 'relation.source_release_id')
            ->where('r.status', 'accepted')
            ->where(fn ($q) => $q->whereNull('relation.valid_from')->orWhere('relation.valid_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('relation.valid_to')->orWhere('relation.valid_to', '>', today()->toDateString()))
            ->select('relation.from_place_id', 'relation.to_place_id');
        $areas = DB::table('places')->where(fn ($q) => $q
            ->whereIn('id', (clone $links)->select('relation.from_place_id'))
            ->orWhereIn('id', (clone $links)->select('relation.to_place_id')))
            ->when($country, fn ($q) => $q->where('country_code', $country))->orderBy('name')->get();
        abort_if($area && ! $areas->contains('id', (int) $area), 404, 'No current source-backed links for this area.');
        $related = $area ? (clone $links)->where(fn ($q) => $q->where('relation.from_place_id', $area)->orWhere('relation.to_place_id', $area))->get()
            ->map(fn ($link) => $link->from_place_id === (int) $area ? $link->to_place_id : $link->from_place_id)->unique() : collect();
        $places = DB::table('places')->when($country, fn ($q) => $q->where('country_code', $country))
            ->when($area, fn ($q) => $q->whereIn('id', $related))
            ->when($type, fn ($q) => $q->where('type', $type))->orderBy('name')->orderBy('id')->paginate(30)->withQueryString();

        return view('geography-index', compact('countries', 'types', 'places', 'country', 'type', 'areas', 'area', 'indiaContext'));
    }

    public function show(string $slug): View
    {
        $place = DB::table('places')->where('slug', $slug)->first();
        abort_unless($place, 404);
        $identifiers = DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
            ->where('i.place_id', $place->id)->where('r.status', 'accepted')
            ->select('i.namespace', 'i.code', 'i.version', 'r.url')->orderBy('i.namespace')->get();
        $observations = DB::table('observations as v')->join('indicators as i', 'i.id', '=', 'v.indicator_id')
            ->join('source_releases as r', 'r.id', '=', 'v.source_release_id')->where('v.place_id', $place->id)
            ->where('r.status', 'accepted')->where('v.status', 'reported')
            ->select('i.label', 'i.unit', 'i.evidence_class', 'v.period', 'v.value', 'v.source_locator', 'r.url')->orderBy('i.label')->orderByDesc('v.period')->get();
        $relations = DB::table('place_relationships as relation')->join('source_releases as r', 'r.id', '=', 'relation.source_release_id')
            ->where('r.status', 'accepted')->where(fn ($q) => $q->where('relation.from_place_id', $place->id)->orWhere('relation.to_place_id', $place->id))
            ->where(fn ($q) => $q->whereNull('relation.valid_from')->orWhere('relation.valid_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('relation.valid_to')->orWhere('relation.valid_to', '>', today()->toDateString()))
            ->select('relation.*', 'r.url')->get();
        $relatedPlaces = DB::table('places')->whereIn('id', $relations->pluck('from_place_id')->merge($relations->pluck('to_place_id')))->get()->keyBy('id');
        $people = DB::table('office_assignments as a')->join('offices as o', 'o.id', '=', 'a.office_id')
            ->leftJoin('people as p', 'p.id', '=', 'a.person_id')->join('office_jurisdictions as j', 'j.office_id', '=', 'o.id')
            ->join('source_releases as r', 'r.id', '=', 'a.source_release_id')->join('source_releases as jr', 'jr.id', '=', 'j.source_release_id')
            ->where('j.place_id', $place->id)->where('r.status', 'accepted')->where('jr.status', 'accepted')->whereNull('a.superseded_at')
            ->whereIn('a.status', ['last_verified', 'confirmed', 'acting', 'additional_charge'])
            ->where(fn ($q) => $q->whereNull('a.effective_from')->orWhere('a.effective_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('a.effective_to')->orWhere('a.effective_to', '>', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('j.valid_from')->orWhere('j.valid_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('j.valid_to')->orWhere('j.valid_to', '>', today()->toDateString()))
            ->select('o.title', 'o.kind', 'p.display_name', 'a.status', 'a.verified_at', 'r.url')->distinct()->get();
        $elections = DB::table('election_contests as e')->join('source_releases as r', 'r.id', '=', 'e.source_release_id')
            ->where('e.place_id', $place->id)->where('e.active', true)->where('r.status', 'accepted')
            ->select('e.year', 'e.election_type', 'e.source_locator', 'r.url')->orderByDesc('e.year')->get();

        return view('geography-place', compact('place', 'identifiers', 'observations', 'relations', 'relatedPlaces', 'people', 'elections'));
    }
}
