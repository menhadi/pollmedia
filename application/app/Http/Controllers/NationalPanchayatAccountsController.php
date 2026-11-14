<?php

namespace App\Http\Controllers;

use App\Services\NationalPanchayatSummaries;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NationalPanchayatAccountsController extends Controller
{
    public function index(Request $request, NationalPanchayatSummaries $summaries): View
    {
        $input = $request->validate(['state' => 'nullable|regex:/^[1-9][0-9]?$/', 'q' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1|max:100000']);
        $coverage = $summaries->coverage();
        $state = $input['state'] ?? null;
        abort_if($state !== null && ! isset($coverage['states'][$state]), 404);
        $q = trim($input['q'] ?? '');
        $page = (int) ($input['page'] ?? 1);
        $listing = $state === null ? ['items' => [], 'more' => false] : $summaries->listing($state, $q, $page);

        return view('national-panchayat-accounts', compact('coverage', 'state', 'q', 'page', 'listing'));
    }

    public function show(NationalPanchayatSummaries $summaries, string $state, string $gp): View
    {
        $sourceRecord = $summaries->record($state, $gp);
        abort_unless($sourceRecord, 404);
        $coverage = $summaries->coverage();

        return view('national-panchayat-account', compact('sourceRecord', 'coverage'));
    }
}
