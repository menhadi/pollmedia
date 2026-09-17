<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CoverageReportController extends Controller
{
    public function show(Request $request, string $scope): View|Response
    {
        $input = $request->validate(['edition' => 'nullable|in:quarterly,annual', 'download' => 'nullable|boolean']);
        $view = $this->draft($input['edition'] ?? 'quarterly', $scope);
        if ($request->boolean('download')) {
            return response($view->with('standalone', true)->render())->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('Content-Disposition', 'attachment; filename="'.$scope.'-'.$view->getData()['edition'].'-draft.html"');
        }

        return $view;
    }

    public function draft(string $edition, string $scope): View
    {
        $area = DB::table('report_scopes')->where('key', $scope)->where('adapter', 'coverage')->first();
        abort_unless($area, 404);
        $scopeLabel = $area->label;
        $generatedAt = now($area->timezone);
        $period = $edition === 'annual' ? (string) $generatedAt->year : 'Q'.$generatedAt->quarter.' '.$generatedAt->year;
        $places = DB::table('places')->where('country_code', $area->country_code);
        if ($area->selection === 'identifiers') {
            $places->whereIn('id', DB::table('place_identifiers as i')->join('source_releases as r', 'r.id', '=', 'i.source_release_id')
                ->where('r.status', 'accepted')->whereIn('i.namespace', json_decode($area->namespaces, true))->select('i.place_id'));
        }
        $placeIds = $places->pluck('id');
        abort_if($placeIds->isEmpty(), 503, 'No supported geographic records are available for this report.');
        $coverage = DB::table('places')->whereIn('id', $placeIds)->selectRaw('type, COUNT(*) as total')->groupBy('type')->orderBy('type')->get();
        $results = DB::table('election_contests as e')->join('source_releases as r', 'r.id', '=', 'e.source_release_id')
            ->whereIn('e.place_id', $placeIds)->where('e.active', true)->where('r.status', 'accepted');
        $elections = (clone $results)->selectRaw('e.year, e.election_type, COUNT(DISTINCT e.place_id) as constituencies')
            ->groupBy('e.year', 'e.election_type')->orderByDesc('e.year')->orderBy('e.election_type')->get();
        $releaseIds = (clone $results)->pluck('e.source_release_id')->merge(DB::table('place_identifiers as i')
            ->join('source_releases as r', 'r.id', '=', 'i.source_release_id')->whereIn('i.place_id', $placeIds)->where('r.status', 'accepted')->pluck('r.id'))->unique();
        $references = DB::table('source_releases as r')->join('data_sources as s', 's.id', '=', 'r.data_source_id')
            ->whereIn('r.id', $releaseIds)->select('r.id', 'r.url', 'r.sha256', 'r.retrieved_at', 's.publisher')->orderBy('r.id')->get();
        $contests = collect();

        return view('coverage-report', compact('scope', 'scopeLabel', 'edition', 'period', 'generatedAt', 'coverage', 'elections', 'references', 'contests'));
    }
}
