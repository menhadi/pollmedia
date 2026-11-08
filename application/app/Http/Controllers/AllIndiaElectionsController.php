<?php

namespace App\Http\Controllers;

use App\Services\AllIndiaElectionTable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllIndiaElectionsController extends Controller
{
    public function __invoke(Request $request, AllIndiaElectionTable $table): View|JsonResponse
    {
        $filters = $request->validate([
            'type' => 'nullable|in:pc,ac,by',
            'year' => 'nullable|integer|min:0|max:9999',
            'state' => 'nullable|string|max:120',
            'q' => 'nullable|string|max:120',
            'rows_only' => 'nullable|in:1',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);

        unset($filters['rows_only']);
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

        return view('all-india-elections', $data);
    }
}
