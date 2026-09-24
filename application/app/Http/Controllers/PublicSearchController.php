<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PublicSearchController extends Controller
{
    public function index(Request $request): View
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
                $results = DB::table('historical_constituency_index')->whereRaw('LOWER(constituency_name) LIKE ?', ['%'.mb_strtolower($q).'%'])
                    ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
                    ->orderByDesc('year')->orderBy('state_label')->orderBy('constituency_name')->paginate(20)->withQueryString();
            }
            if (in_array($kind, ['', 'village'])) {
                $release = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
                    ->where('s.key', 'census-pilibhit-villages-2011')->where('r.status', 'accepted')->orderByDesc('r.id')->value('r.payload');
                $data = $release ? json_decode($release, true, 512, JSON_THROW_ON_ERROR) : [];
                $villages = collect($data['villages'] ?? [])->filter(fn ($v) => str_contains(mb_strtolower($v['name']), mb_strtolower($q)) || (string) $v['code'] === $q)
                    ->sortBy('name')->take(20)->map(fn ($v) => $v + ['url' => route('villages.show', ['code' => $v['code'], 'slug' => Str::slug($v['name'])])])->values();
            }
        }

        return view('public-search', compact('q', 'kind', 'profiles', 'villages', 'results'));
    }
}
