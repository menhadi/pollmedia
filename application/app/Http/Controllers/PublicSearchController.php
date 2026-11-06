<?php

namespace App\Http\Controllers;

use App\Services\CensusPlaceNavigation;
use App\Services\ConstituencyUrls;
use App\Services\DistrictDashboardUrls;
use App\Services\ElectionPlaceIdentity;
use App\Services\NamedPlaceUrls;
use App\Services\SirReferences;
use Illuminate\Contracts\View\View;
use App\Services\PlacePostalCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PublicSearchController extends Controller
{
    public function index(Request $request): View|JsonResponse|RedirectResponse
    {
        $input = $request->validate(['q' => 'nullable|string|max:100', 'kind' => 'nullable|in:pc,ac,village,district,subdistrict,sir', 'year' => 'nullable|integer|min:1800|max:2100', 'page' => 'nullable|integer|min:1|max:10000', 'sir_page' => 'nullable|integer|min:1|max:10000']);
        $q = trim($input['q'] ?? '');
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';
        $kind = $input['kind'] ?? '';
        if ($kind === '' && ! isset($input['year']) && preg_match('/^[1-9][0-9]{5}$/D', $q)) {
            if ($request->expectsJson()) {
                return response()->json(['suggestions' => app(PlacePostalCodes::class)->suggestions($q)]);
            }

            return redirect()->route('postal-areas.index', ['pin' => $q]);
        }
        if ($request->expectsJson() && mb_strlen($q) < 2) {
            return response()->json(['suggestions' => []]);
        }
        $cacheScope = hash('sha256', (string) config('database.connections.'.config('database.default').'.database'));
        $releaseRevision = hash('sha256', json_encode(DB::table('source_releases')->where('status', 'accepted')->selectRaw('COUNT(*) AS total, MAX(id) AS latest_id, MAX(updated_at) AS latest_update')->first()));
        $suggestionKey = 'public-search-v4:'.$cacheScope.':'.$releaseRevision.':'.hash('sha256', json_encode([mb_strtolower($q), $kind, $input['year'] ?? null, $input['page'] ?? 1]));
        if ($request->expectsJson() && ($cached = Cache::get($suggestionKey)) !== null) {
            return response()->json(['suggestions' => $cached]);
        }
        $profiles = collect();
        $villages = collect();
        $results = null;
        $year = isset($input['year']) ? (int) $input['year'] : null;
        $sirDocuments = in_array($kind, ['', 'sir']) ? collect(Cache::remember('search-sir-v3:'.$cacheScope.':'.$releaseRevision, 300, fn () => app(SirReferences::class)->documents()->all())) : collect();
        $sirYears = $sirDocuments->pluck('year')->filter()->unique()->sortDesc()->values();
        $sirMatches = mb_strlen($q) >= 2 ? app(SirReferences::class)->search($sirDocuments, $q, $year) : collect();
        $sirPage = (int) ($input['sir_page'] ?? 1);
        $sirResults = new LengthAwarePaginator($sirMatches->slice(($sirPage - 1) * 20, 20)->values(), $sirMatches->count(), 20, $sirPage, ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'sir_page']);
        if (mb_strlen($q) >= 2) {
            if (in_array($kind, ['', 'pc', 'ac', 'district'], true)) {
                $profiles = DB::table('places')->whereIn('type', ['pc', 'ac', 'district'])
                    ->when($kind !== '', fn ($query) => $query->where('type', $kind))
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])->orderByRaw("CASE type WHEN 'pc' THEN 0 WHEN 'district' THEN 1 ELSE 2 END")->orderBy('name')->limit(20)->get();
            }
            if (in_array($kind, ['', 'pc', 'ac']) && Schema::hasTable('historical_constituency_index')) {
                $revision = DB::table('historical_constituency_index')->max('id') ?? 0;
                $catalogue = Cache::remember('search-election-v3:'.$cacheScope.':'.$revision, 300, function () {
                    $stateSql = ElectionPlaceIdentity::stateSql();
                    $rows = DB::table('historical_constituency_index')->whereNotNull('state_label')->where('state_label', '!=', '')
                        ->select('kind', DB::raw($stateSql.' as state_label'), DB::raw('LOWER(constituency_name) as constituency_name'), DB::raw('MIN(year) as first_year'), DB::raw('MAX(year) as last_year'))
                        ->groupBy('kind', DB::raw($stateSql), DB::raw('LOWER(constituency_name)'))->get();
                    $latest = $rows->groupBy(fn ($row) => $row->kind.'|'.$row->state_label)->map(fn ($group) => $group->max('last_year'));

                    return $rows->map(function ($row) use ($latest) {
                        $row->latest_year = $latest[$row->kind.'|'.$row->state_label];

                        return (array) $row;
                    })->all();
                });
                $matches = collect($catalogue)->map(fn ($row) => (object) $row)->filter(fn ($row) => ($kind === '' || $row->kind === $kind) && str_contains(mb_strtolower($row->constituency_name), mb_strtolower($q)))
                    ->sortBy([fn ($a, $b) => ($a->last_year < $a->latest_year) <=> ($b->last_year < $b->latest_year), fn ($a, $b) => ($a->kind === 'pc' ? 0 : 2) <=> ($b->kind === 'pc' ? 0 : 2), ['constituency_name', 'asc'], ['state_label', 'asc']])->values();
                $page = (int) ($input['page'] ?? 1);
                $results = new LengthAwarePaginator($matches->slice(($page - 1) * 20, 20)->values(), $matches->count(), 20, $page, ['path' => $request->url(), 'query' => $request->query()]);
                $results->through(function ($row) {
                    $row = clone $row;
                    $row->state_label = ElectionPlaceIdentity::state($row->state_label);

                    return $row;
                });
            }
            if (in_array($kind, ['', 'village'])) {
                $data = Cache::remember('search-villages-v2:'.$cacheScope.':'.$releaseRevision, 300, function (): array {
                    $release = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
                        ->where('s.key', 'census-pilibhit-villages-2011')->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');

                    return $release ? json_decode($release, true, 512, JSON_THROW_ON_ERROR) : [];
                });
                $villages = collect($data['villages'] ?? [])->filter(fn ($v) => str_contains(mb_strtolower($v['name']), mb_strtolower($q)) || (string) $v['code'] === $q)
                    ->sortBy('name')->take(20)->map(fn ($v) => $v + ['url' => route('villages.named', ['state' => 'uttar-pradesh', 'district' => 'pilibhit', 'code' => $v['code'], 'slug' => Str::slug($v['name'])])])->values();
            }
        }
        $identifiers = $profiles->isEmpty() ? collect() : DB::table('place_identifiers as i')->join('source_releases as s', 's.id', '=', 'i.source_release_id')
            ->whereIn('i.place_id', $profiles->pluck('id'))->where('s.status', 'accepted')
            ->whereIn('i.namespace', ['electoral:IN:UP:pc', 'electoral:IN:UP:ac'])->select('i.place_id', 'i.namespace')->get()->groupBy('place_id');
        $suggestions = $profiles->map(function ($profile) use ($identifiers): array {
            $electoralIdentity = in_array($profile->type, ['pc', 'ac'], true)
                && $identifiers->get($profile->id, collect())->contains('namespace', 'electoral:IN:UP:'.$profile->type);

            return ['label' => $profile->name, 'type' => ['pc' => 'Lok Sabha', 'ac' => 'Assembly (AC)', 'district' => 'District'][$profile->type],
                'rank' => ['pc' => 0, 'district' => 1, 'ac' => 2][$profile->type], 'period' => $electoralIdentity ? 'Uttar Pradesh' : '',
                'identity' => $electoralIdentity ? $profile->type.'|uttar pradesh|'.mb_strtolower($profile->name) : null,
                'url' => $profile->type === 'district' ? app(CensusPlaceNavigation::class)->url($profile) : (str_starts_with($profile->slug, $profile->type.'-') ? route('places.show', ['type' => $profile->type, 'slug' => substr($profile->slug, strlen($profile->type) + 1)]) : route('geography.show', $profile->slug))];
        });
        $suggestions = $suggestions->reject(fn (array $row): bool => $row['type'] === 'District')->values();
        if (mb_strlen($q) >= 2 && $kind === '') {
            foreach (DB::table('seo_metadata')->where('path', 'like', '/india/census/states/%')->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern])->limit(8)->get() as $statePage) {
                $suggestions->push(['label' => trim(explode(' Census ', $statePage->title)[0]), 'type' => 'Census state', 'rank' => 0, 'period' => 'Census 2011', 'url' => url($statePage->path)]);
            }
        }
        if (mb_strlen($q) >= 2 && in_array($kind, ['', 'district'], true)) {
            foreach (app(DistrictDashboardUrls::class)->all() as $district) {
                if (str_contains(mb_strtolower($district['name']), mb_strtolower($q))) {
                    $suggestions->push(['label' => $district['name'], 'type' => 'District', 'rank' => 1, 'period' => $district['state_name'], 'url' => url($district['path'])]);
                }
            }
        }
        if (mb_strlen($q) >= 2 && in_array($kind, ['', 'subdistrict'], true)) {
            $revision = DB::table('census_editions')->where('status', 'published')->selectRaw('COUNT(*) AS total, MAX(updated_at) AS latest')->first();
            $catalogue = Cache::remember('search-subdistrict-v3:'.$cacheScope.':'.hash('sha256', json_encode($revision)), 300, fn () => DB::table('census_catalogue_rows as c')->join('census_editions as e', 'e.id', '=', 'c.edition_id')
                ->where('e.status', 'published')->where('e.year', 2011)->whereIn('c.level', ['SUB-DISTRICT', 'SUBDISTRICT', 'TEHSIL'])->where('c.residence', 'Total')
                ->select('c.id', 'c.name', 'c.state_code', 'c.district_code', 'c.geography')
                ->orderByRaw("CASE WHEN e.source_key LIKE 'district-pca-2011-%' THEN 0 ELSE 1 END")->orderBy('c.id')->get()
                ->unique(fn ($row) => $row->state_code.':'.$row->district_code.':'.(json_decode($row->geography, true)['Subdistt'] ?? $row->id))->map(fn ($row) => (array) $row)->values()->all());
            $subdistricts = collect($catalogue)->map(fn ($row) => (object) $row)->filter(fn ($row) => str_contains(mb_strtolower($row->name), mb_strtolower($q)));
            foreach ($subdistricts->take(20) as $row) {
                $district = app(DistrictDashboardUrls::class)->identity($row->state_code, $row->district_code);
                $suggestions->push(['label' => $row->name, 'type' => 'Subdistrict', 'rank' => 4, 'period' => $district ? $district['name'].', '.$district['state_name'] : 'Census 2011', 'url' => $district ? url(NamedPlaceUrls::path('subdistricts', $district['state_name'], $district['name'], $row->name, $row->id)) : app(NamedPlaceUrls::class)->census($row)]);
            }
        }
        foreach ($results?->items() ?? [] as $r) {
            $earlier = $r->last_year < $r->latest_year;
            $suggestions->push(['label' => Str::title($r->constituency_name), 'type' => ($earlier ? 'Archive · ' : '').['pc' => 'Lok Sabha', 'ac' => 'Assembly (AC)'][$r->kind],
                'rank' => ($earlier ? 3 : 0) + ($r->kind === 'pc' ? 0 : 2), 'period' => $r->state_label.($earlier ? ' · '.$r->first_year.'–'.$r->last_year : ''),
                'identity' => $r->kind.'|'.mb_strtolower($r->state_label).'|'.mb_strtolower($r->constituency_name),
                'url' => ConstituencyUrls::url(['kind' => $r->kind, 'state' => $r->state_label, 'name' => $r->constituency_name])]);
        }
        foreach ($villages as $v) {
            $suggestions->push(['label' => $v['name'], 'type' => 'Village', 'rank' => 6, 'period' => 'Pilibhit, Uttar Pradesh', 'url' => $v['url']]);
        }
        $suggestions = $suggestions->sortBy([['rank', 'asc'], ['label', 'asc']])
            ->unique(fn (array $suggestion): string => $suggestion['type'] === 'District' ? $suggestion['url'] : ($suggestion['identity'] ?? $suggestion['url']))
            ->map(function (array $suggestion): array {
                unset($suggestion['identity']);

                return $suggestion;
            })->values();
        if ($request->expectsJson()) {
            $suggestions = $suggestions->concat($sirMatches->take(5));

            $items = $suggestions->take(20)->values()->all();
            Cache::put($suggestionKey, $items, 30);

            return response()->json(['suggestions' => $items]);
        }

        return view('public-search', compact('q', 'kind', 'profiles', 'villages', 'results', 'suggestions', 'sirResults', 'sirYears', 'year'));
    }
}
