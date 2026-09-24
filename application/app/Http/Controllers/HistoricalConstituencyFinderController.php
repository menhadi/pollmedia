<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HistoricalConstituencyFinderController extends Controller
{
    public function index(Request $request): View
    {
        $input = $request->validate([
            'q' => 'nullable|string|max:100',
            'kind' => 'nullable|in:pc,ac',
            'year' => 'nullable|integer|min:1951|max:2100',
            'state' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:10000',
        ]);
        $indexed = Schema::hasTable('historical_constituency_index');
        $states = $indexed ? DB::table('historical_constituency_index')->whereNotNull('state_label')->distinct()->orderBy('state_label')->pluck('state_label') : collect();
        $years = $indexed ? DB::table('historical_constituency_index')->distinct()->orderByDesc('year')->pluck('year') : collect();
        $results = $indexed ? DB::table('historical_constituency_index')
            ->when(! empty($input['q']), fn ($query) => $query->whereRaw('LOWER(constituency_name) LIKE ?', ['%'.mb_strtolower(trim($input['q'])).'%']))
            ->when(! empty($input['kind']), fn ($query) => $query->where('kind', $input['kind']))
            ->when(! empty($input['year']), fn ($query) => $query->where('year', (int) $input['year']))
            ->when(! empty($input['state']), fn ($query) => $query->where('state_label', $input['state']))
            ->orderByDesc('year')->orderBy('state_label')->orderBy('constituency_name')->paginate(30)->withQueryString() : null;

        return view('historical-constituencies', compact('indexed', 'input', 'states', 'years', 'results'));
    }
}
