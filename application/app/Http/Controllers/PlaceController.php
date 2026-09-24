<?php

namespace App\Http\Controllers;

use App\Services\CensusHistory;
use App\Services\ElectionPlaceIdentity;
use App\Services\ElectionResults;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlaceController extends Controller
{
    public function show(Request $request, string $type, string $slug): View|RedirectResponse
    {
        abort_unless(in_array($type, ['district', 'pc', 'ac']), 404);
        $place = DB::table('places')->where('slug', $type.'-'.$slug)->first();
        abort_unless($place, 404);
        if (in_array($type, ['pc', 'ac']) && Schema::hasTable('historical_constituency_index') && DB::table('place_identifiers')->where('place_id', $place->id)->where('namespace', 'electoral:IN:UP:'.$type)->exists()) {
            $entry = DB::table('historical_constituency_index')->where('kind', $type)->whereRaw(ElectionPlaceIdentity::stateSql().' = ?', ['uttar pradesh'])->whereRaw('LOWER(constituency_name) = ?', [mb_strtolower($place->name)])->orderByDesc('year')->first();
            if ($entry) {
                return redirect()->route('constituency.overview', ['kind' => $type, 'state' => 'Uttar Pradesh', 'name' => $entry->constituency_name]);
            }
        }
        $scope = [$place->id];
        if ($type === 'district') {
            $scope = array_merge($scope, DB::table('place_relationships')->where('to_place_id', $place->id)->where('type', 'district_directory_lists')->pluck('from_place_id')->all());
        }
        $people = DB::table('office_assignments as a')->join('people as p', 'p.id', '=', 'a.person_id')
            ->join('offices as o', 'o.id', '=', 'a.office_id')->join('office_jurisdictions as j', 'j.office_id', '=', 'o.id')
            ->join('places as g', 'g.id', '=', 'j.place_id')->join('source_releases as r', 'r.id', '=', 'a.source_release_id')
            ->whereIn('j.place_id', $scope)->whereNull('a.superseded_at')
            ->where(fn ($q) => $q->whereNull('j.valid_from')->orWhere('j.valid_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('j.valid_to')->orWhere('j.valid_to', '>', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('a.effective_from')->orWhere('a.effective_from', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('a.effective_to')->orWhere('a.effective_to', '>', today()->toDateString()))
            ->whereIn('a.status', ['last_verified', 'confirmed', 'acting', 'additional_charge'])
            ->select('p.id as person_id', 'p.display_name', 'o.title', 'o.kind', 'g.name as area', 'g.type as area_type', 'a.verified_at', 'r.url')->distinct()->get();
        foreach ($people as $person) {
            $person->profiles = DB::table('public_profiles')->where('person_id', $person->person_id)->get();
        }
        $observations = DB::table('observations as v')->join('indicators as i', 'i.id', '=', 'v.indicator_id')->join('source_releases as r', 'r.id', '=', 'v.source_release_id')->where('v.place_id', $place->id)->select('i.label', 'i.key', 'v.value', 'v.period', 'r.url')->get();
        $census = $place->slug === 'district-pilibhit' ? $this->payload('census-pilibhit') : [];
        $pageTitle = $place->name.' '.(['district' => 'District', 'pc' => 'Parliamentary Constituency', 'ac' => 'Assembly Constituency'][$type]);
        $code = DB::table('place_identifiers')->where('place_id', $place->id)->orderByDesc('id')->value('code');
        $relations = DB::table('place_relationships as r')->join('source_releases as s', 's.id', '=', 'r.source_release_id')
            ->where(fn ($q) => $q->where('r.from_place_id', $place->id)->orWhere('r.to_place_id', $place->id))
            ->whereNull('r.valid_to')->where('s.status', 'accepted')->orderByDesc('r.id')->select('r.*', 's.url', 's.retrieved_at')->get()
            ->map(function ($r) use ($place) {
                $other = DB::table('places')->find($r->from_place_id === $place->id ? $r->to_place_id : $r->from_place_id);
                $other->url = route('places.show', ['type' => $other->type, 'slug' => substr($other->slug, strlen($other->type) + 1)]);
                $other->source_url = $r->url;
                $other->relationship_type = $r->type;
                $other->checked_on = substr($r->retrieved_at, 0, 10);
                $other->source_locator = $r->source_locator;
                $other->reference_date = $r->reference_date;

                return $other;
            })->unique('id')->sortBy('name')->values();
        $crossBoundaryLinks = collect();
        if ($type === 'district') {
            $crossBoundaryLinks = DB::table('place_relationships as district_link')
                ->join('place_relationships as pc_link', 'pc_link.from_place_id', '=', 'district_link.from_place_id')
                ->join('places as p', 'p.id', '=', 'pc_link.to_place_id')
                ->where('district_link.to_place_id', $place->id)->where('district_link.type', 'district_directory_lists')
                ->where('pc_link.type', 'assembly_segment_of')->whereNull('district_link.valid_to')->whereNull('pc_link.valid_to')
                ->select('p.id', 'p.name', 'p.slug', 'p.type', DB::raw('count(distinct district_link.from_place_id) as shared_acs'))
                ->groupBy('p.id', 'p.name', 'p.slug', 'p.type')->orderBy('p.name')->get();
        } elseif ($type === 'pc') {
            $crossBoundaryLinks = DB::table('place_relationships as pc_link')
                ->join('place_relationships as district_link', 'district_link.from_place_id', '=', 'pc_link.from_place_id')
                ->join('places as p', 'p.id', '=', 'district_link.to_place_id')
                ->where('pc_link.to_place_id', $place->id)->where('pc_link.type', 'assembly_segment_of')
                ->where('district_link.type', 'district_directory_lists')->whereNull('district_link.valid_to')->whereNull('pc_link.valid_to')
                ->select('p.id', 'p.name', 'p.slug', 'p.type', DB::raw('count(distinct pc_link.from_place_id) as shared_acs'))
                ->groupBy('p.id', 'p.name', 'p.slug', 'p.type')->orderBy('p.name')->get();
        }
        $crossBoundaryLinks->each(function (object $related): void {
            $related->url = route('places.show', ['type' => $related->type, 'slug' => substr($related->slug, strlen($related->type) + 1)]);
        });
        $input = $request->validate(['year' => 'nullable|integer|min:1951|max:2100']);
        $elections = app(ElectionResults::class)->forPlace($place->id);
        $withheldElections = collect();
        if ($type === 'ac') {
            $acCode = DB::table('place_identifiers')->where('place_id', $place->id)->where('namespace', 'electoral:IN:UP:ac')->value('code');
            if ($acCode !== null) {
                $withheldElections = DB::table('election_import_batch_rows as r')->join('election_import_batches as b', 'b.id', '=', 'r.batch_id')
                    ->where('r.code', (int) $acCode)->where('r.status', 'invalid')->whereNotNull('b.published_at')->whereNotIn('b.year', $elections->pluck('year'))
                    ->select('b.year', 'b.source_url', 'r.error')->orderByDesc('b.year')->get();
            }
        }
        $selectedElection = isset($input['year']) ? $elections->firstWhere('year', (int) $input['year']) : $elections->first();
        abort_if(isset($input['year']) && ! $selectedElection, 404, 'Election year not imported for this place.');
        $villageCoverage = $this->villageCoverage($type, $slug);
        $populationHistoryAvailable = $place->slug === 'district-pilibhit' && app(CensusHistory::class)->population() !== null;

        return view('place', compact('place', 'type', 'people', 'observations', 'census', 'elections', 'selectedElection', 'pageTitle', 'code', 'relations', 'crossBoundaryLinks', 'villageCoverage', 'populationHistoryAvailable', 'withheldElections'));
    }

    private function villageCoverage(string $type, string $slug): array
    {
        if (! in_array($slug, ['pilibhit', 'bareilly', 'baheri', 'barkhera', 'puranpur', 'bisalpur'], true)) {
            return ['rows' => collect(), 'url' => null, 'checked_on' => null];
        }
        $electoral = $this->payload('lgd-pilibhit-electoral');
        $rows = collect($electoral['villages'])->filter(fn ($row) => match ($type) {
            'pc' => $row['pc'] === $slug,
            'ac' => $row['ac'] === $slug,
            'district' => $row['district_code'] === (['pilibhit' => '173', 'bareilly' => '130'][$slug] ?? ''),
        });
        $lgd = collect($this->payload('lgd-pilibhit')['villages'])->keyBy('code');
        $editions = [];
        foreach (['2001', '2011'] as $year) {
            $editions[$year] = collect($this->payload('census-pilibhit-villages-'.$year)['villages'])->pluck('code')->flip();
        }
        $groups = $rows->groupBy('ac')->sortKeys()->map(function ($villages, $ac) use ($lgd, $editions) {
            $counts = [];
            foreach ($editions as $year => $codes) {
                $counts[$year] = $villages->map(fn ($row) => $lgd->get($row['lgd_code'])['census_'.$year.'_code'] ?? null)
                    ->filter(fn ($code) => $code !== null && $codes->has($code))->unique()->count();
            }

            return ['ac' => $ac, 'reported' => $villages->pluck('lgd_code')->unique()->count(), 'profiles' => $counts];
        })->values();

        return ['rows' => $groups, 'url' => $electoral['url'], 'checked_on' => $electoral['checked_on']];
    }

    public function payload(string $key): array
    {
        $r = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')->where('s.key', $key)->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');
        abort_unless($r, 503, 'Dataset unavailable');

        return json_decode($r, true, 512, JSON_THROW_ON_ERROR);
    }

    public function sir(Request $request)
    {
        $input = $request->validate(['state' => 'nullable|string|max:10', 'pc' => 'nullable|string|max:10', 'ac' => 'nullable|string|max:10', 'page' => 'nullable|integer|min:1|max:10000', 'q' => 'nullable|string|max:100']);
        $data = $this->payload('sir-pilibhit');
        $page = (int) ($input['page'] ?? 1);
        $valid = ($input['state'] ?? '') === '09' && in_array($input['pc'] ?? '', ['', '26']) && in_array($input['ac'] ?? '', ['', '127']) && (($input['pc'] ?? '') !== '' || ($input['ac'] ?? '') !== '');
        $rows = collect($valid ? $data['parts'] : []);
        if ($q = mb_strtolower(trim($input['q'] ?? ''))) {
            $rows = $rows->filter(fn ($p) => str_contains(mb_strtolower($p['name']), $q) || (string) $p['part'] === $q);
        }
        unset($data['parts']);

        return response()->json(array_merge($data, ['rows' => $rows->slice(($page - 1) * 10, 10)->values(), 'total_rows' => $rows->count(), 'listed_records' => $rows->sum('listed_records'), 'page' => $page, 'imported_at' => '2026-09-15', 'partial_coverage' => true]));
    }
}
