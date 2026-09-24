<?php

namespace App\Http\Controllers;

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
                    ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%'])->orderBy('name')->limit(20)->get();
            }
            if (in_array($kind, ['', 'pc', 'ac']) && Schema::hasTable('historical_constituency_index')) {
                $results = DB::table('historical_constituency_index')->whereNotNull('state_label')->where('state_label', '!=', '')->whereRaw('LOWER(constituency_name) LIKE ?', ['%'.mb_strtolower($q).'%'])
                    ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
                    ->select('kind', 'state_label', DB::raw('LOWER(constituency_name) as constituency_name'))
                    ->groupBy('kind', 'state_label', DB::raw('LOWER(constituency_name)'))->orderBy('constituency_name')->orderBy('state_label')->paginate(20)->withQueryString();
            }
            if (in_array($kind, ['', 'village'])) {
                $release = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
                    ->where('s.key', 'census-pilibhit-villages-2011')->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');
                $data = $release ? json_decode($release, true, 512, JSON_THROW_ON_ERROR) : [];
                $villages = collect($data['villages'] ?? [])->filter(fn ($v) => str_contains(mb_strtolower($v['name']), mb_strtolower($q)) || (string) $v['code'] === $q)
                    ->sortBy('name')->take(20)->map(fn ($v) => $v + ['url' => route('villages.show', ['code' => $v['code'], 'slug' => Str::slug($v['name'])])])->values();
            }
        }
        if ($results) {
            $profiles = $profiles->reject(fn ($p) => collect($results->items())->contains(fn ($r) => $r->kind === $p->type && mb_strtolower($r->constituency_name) === mb_strtolower($p->name)));
        }
        if ($request->expectsJson()) {
            $suggestions = $profiles->take(5)->map(fn ($p) => ['label' => $p->name, 'type' => ['pc' => 'Parliament (PC)', 'ac' => 'Assembly (AC)', 'district' => 'District'][$p->type], 'url' => route('places.show', ['type' => $p->type, 'slug' => substr($p->slug, strlen($p->type) + 1)])]);
            foreach (collect($results?->items() ?? [])->take(6) as $r) {
                $suggestions->push(['label' => Str::title($r->constituency_name), 'type' => strtoupper($r->kind).' · '.$r->state_label, 'url' => route('constituency.overview', ['kind' => $r->kind, 'state' => $r->state_label, 'name' => $r->constituency_name])]);
            }
            foreach ($villages->take(4) as $v) {
                $suggestions->push(['label' => $v['name'], 'type' => 'Village · Pilibhit, Uttar Pradesh', 'url' => $v['url']]);
            }

            return response()->json(['suggestions' => $suggestions->values()]);
        }

        return view('public-search', compact('q', 'kind', 'profiles', 'villages', 'results'));
    }
}
