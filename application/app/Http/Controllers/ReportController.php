<?php

namespace App\Http\Controllers;

use App\Services\ElectionResults;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function show(Request $request): View|Response
    {
        $input = $request->validate(['edition' => 'nullable|in:quarterly,annual', 'download' => 'nullable|boolean', 'year' => 'prohibited', 'quarter' => 'prohibited']);
        $edition = $input['edition'] ?? 'quarterly';
        $view = $this->draft($edition);
        if ($request->boolean('download')) {
            $view->with('standalone', true);
            $generatedAt = $view->getData()['generatedAt'];

            return response($view->render())->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('Content-Disposition', 'attachment; filename="pilibhit-'.$edition.'-'.$generatedAt->format('Y-m-d').'-draft.html"');
        }

        return $view;
    }

    public function draft(string $edition): View
    {
        $generatedAt = now('Asia/Kolkata');
        $period = $edition === 'annual' ? (string) $generatedAt->year : 'Q'.$generatedAt->quarter.' '.$generatedAt->year;
        $datasets = [];
        $references = [];
        foreach (['census-pilibhit-villages-2011', 'census-pilibhit-villages-2001', 'lgd-pilibhit', 'lgd-pilibhit-electoral'] as $key) {
            $release = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
                ->where('s.key', $key)->where('r.status', 'accepted')->orderByDesc('r.id')
                ->select('r.id', 'r.payload', 'r.url', 'r.retrieved_at', 'r.sha256', 's.publisher')->first();
            abort_unless($release && $release->payload, 503, 'Required report evidence is unavailable.');
            $datasets[$key] = json_decode($release->payload, true, 512, JSON_THROW_ON_ERROR);
            $references[$key] = $release;
        }
        $census = $datasets['census-pilibhit-villages-2011'];
        $historical = $datasets['census-pilibhit-villages-2001'];
        $lgd = collect($datasets['lgd-pilibhit']['villages'])->keyBy('code');
        $electoral = collect($datasets['lgd-pilibhit-electoral']['villages'])->where('district_code', '173');
        $censusCodes = collect($census['villages'])->pluck('code')->flip();
        $coverage = $electoral->groupBy('ac')->sortKeys()->map(function ($rows, $ac) use ($lgd, $censusCodes) {
            $codes = $rows->map(fn ($row) => $lgd->get($row['lgd_code'])['census_2011_code'] ?? null)
                ->filter(fn ($code) => $code !== null && $censusCodes->has($code))->unique();

            return ['ac' => ucfirst($ac), 'lgd_count' => $rows->pluck('lgd_code')->unique()->count(), 'profiles' => $codes->count()];
        })->values();
        $matchedCodes = $electoral->map(fn ($row) => $lgd->get($row['lgd_code'])['census_2011_code'] ?? null)->filter()->flip();
        $unmapped = collect($census['villages'])->reject(fn ($village) => $matchedCodes->has($village['code']))->values();
        $contests = collect();
        foreach (DB::table('places')->whereIn('slug', ['pc-pilibhit', 'ac-pilibhit', 'ac-barkhera', 'ac-puranpur', 'ac-bisalpur'])->orderBy('type')->orderBy('name')->get() as $place) {
            $contest = app(ElectionResults::class)->forPlace($place->id)->first();
            if ($contest) {
                $contests->push(['place' => $place, 'result' => $contest]);
            }
        }

        return view('district-report', compact('edition', 'period', 'generatedAt', 'references', 'census', 'historical', 'lgd', 'coverage', 'unmapped', 'contests'));
    }
}
