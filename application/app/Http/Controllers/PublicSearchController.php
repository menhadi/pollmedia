<?php

namespace App\Http\Controllers;

use App\Services\CensusPlaceNavigation;
use App\Services\ElectionPlaceIdentity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PublicSearchController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $input = $request->validate(['q' => 'nullable|string|max:100', 'kind' => 'nullable|in:pc,ac,village,district', 'page' => 'nullable|integer|min:1|max:10000']);
        $q = trim($input['q'] ?? '');
        $kind = $input['kind'] ?? '';
        $profiles = collect();
        $villages = collect();
        $results = null;
        if (mb_strlen($q) >= 2) {
            if ($kind !== 'village') {
                $profiles = DB::table('places')->whereIn('type', ['pc', 'ac', 'district'])
                    ->when($kind !== '', fn ($query) => $query->where('type', $kind))
                    ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%'])->orderByRaw("CASE type WHEN 'pc' THEN 0 WHEN 'district' THEN 1 ELSE 2 END")->orderBy('name')->limit(20)->get();
            }
            if (in_array($kind, ['', 'pc', 'ac']) && Schema::hasTable('historical_constituency_index')) {
                $stateSql = ElectionPlaceIdentity::stateSql();
                $latest = DB::table('historical_constituency_index')->select('kind', DB::raw($stateSql.' as state_key'), DB::raw('MAX(year) as latest_year'))->groupBy('kind', DB::raw($stateSql));
                $matches = DB::table('historical_constituency_index')->whereNotNull('state_label')->where('state_label', '!=', '')->whereRaw('LOWER(constituency_name) LIKE ?', ['%'.mb_strtolower($q).'%'])
                    ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
                    ->select('kind', DB::raw($stateSql.' as state_label'), DB::raw('LOWER(constituency_name) as constituency_name'), DB::raw('MIN(year) as first_year'), DB::raw('MAX(year) as last_year'))
                    ->groupBy('kind', DB::raw($stateSql), DB::raw('LOWER(constituency_name)'));
                $results = DB::query()->fromSub($matches, 'matches')->leftJoinSub($latest, 'latest', fn ($join) => $join->on('matches.kind', '=', 'latest.kind')->on('matches.state_label', '=', 'latest.state_key'))
                    ->select('matches.*', 'latest.latest_year')
                    ->orderByRaw('CASE WHEN matches.last_year < latest.latest_year THEN 1 ELSE 0 END')
                    ->orderByRaw("CASE matches.kind WHEN 'pc' THEN 0 ELSE 2 END")
                    ->orderBy('matches.constituency_name')->orderBy('matches.state_label')->paginate(20)->withQueryString();
                $results->through(function ($row) {
                    $row->state_label = ElectionPlaceIdentity::state($row->state_label);

                    return $row;
                });
            }
            if (in_array($kind, ['', 'village'])) {
                $release = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
                    ->where('s.key', 'census-pilibhit-villages-2011')->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');
                $data = $release ? json_decode($release, true, 512, JSON_THROW_ON_ERROR) : [];
                $villages = collect($data['villages'] ?? [])->filter(fn ($v) => str_contains(mb_strtolower($v['name']), mb_strtolower($q)) || (string) $v['code'] === $q)
                    ->sortBy('name')->take(20)->map(fn ($v) => $v + ['url' => route('villages.show', ['code' => $v['code'], 'slug' => Str::slug($v['name'])])])->values();
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
        foreach ($results?->items() ?? [] as $r) {
            $earlier = $r->last_year < $r->latest_year;
            $suggestions->push(['label' => Str::title($r->constituency_name), 'type' => ($earlier ? 'Archive · ' : '').['pc' => 'Lok Sabha', 'ac' => 'Assembly (AC)'][$r->kind],
                'rank' => ($earlier ? 3 : 0) + ($r->kind === 'pc' ? 0 : 2), 'period' => $r->state_label.($earlier ? ' · '.$r->first_year.'–'.$r->last_year : ''),
                'identity' => $r->kind.'|'.mb_strtolower($r->state_label).'|'.mb_strtolower($r->constituency_name),
                'url' => route('constituency.overview', ['kind' => $r->kind, 'state' => $r->state_label, 'name' => $r->constituency_name])]);
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
            return response()->json(['suggestions' => $suggestions->take(20)->values()]);
        }

        return view('public-search', compact('q', 'kind', 'profiles', 'villages', 'results', 'suggestions'));
    }
}
