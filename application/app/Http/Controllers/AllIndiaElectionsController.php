<?php

namespace App\Http\Controllers;

use App\Services\AllIndiaElectionTable;
use App\Services\ElectionGeographySummary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AllIndiaElectionsController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        return redirect()->route('elections.all-india', $request->query(), 301);
    }

    public function __invoke(Request $request, AllIndiaElectionTable $table): View|JsonResponse|Response
    {
        $filters = $request->validate([
            'type' => 'nullable|in:pc,ac,by',
            'year' => 'nullable|integer|min:0|max:9999',
            'state' => 'nullable|string|max:120',
            'q' => 'nullable|string|max:120',
            'seat' => 'nullable|string|max:200',
            'panel' => 'nullable|in:1',
            'map_edition' => 'nullable|regex:/^[a-f0-9]{24}$/',
            'rows_only' => 'nullable|in:1',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);

        unset($filters['rows_only'], $filters['panel']);
        $data = $table->page($filters);
        $page = $data['current'];
        $link = fn ($n) => route('elections.all-india', array_merge($filters, ['page' => $n, 'lang' => app()->getLocale() === 'hi' ? 'hi' : null]));
        $data['first'] = ($page - 1) * AllIndiaElectionTable::PAGE_SIZE + 1;
        $data['last'] = $data['first'] - 1 + $data['rows']->count();
        $data['nextUrl'] = $data['hasMore'] ? $link($page + 1) : null;
        $data['previousUrl'] = $page > 1 ? $link($page - 1) : null;
        if ($request->boolean('rows_only')) {
            return response()->json([
                'rows' => view('all-india-election-rows', $data)->render(),
                'last' => $data['last'], 'total' => $data['total'], 'next' => $data['nextUrl'],
            ]);
        }

        if ($request->boolean('panel')) {
            return response()->view('national-election-records', $data)->header('X-Robots-Tag', 'noindex, follow');
        }

        $editions = collect($data['mapEditions']);
        $requestedMap = $filters['map_edition'] ?? null;
        $data['mapEdition'] = $requestedMap ? $editions->firstWhere('id', $requestedMap) : $editions->first();
        abort_if($requestedMap && ! $data['mapEdition'], 404);
        $data['stateDirectory'] = app(ElectionGeographySummary::class)->states();

        return view('all-india-elections', $data);
    }
}
