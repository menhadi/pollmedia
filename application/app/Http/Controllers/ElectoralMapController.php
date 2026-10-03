<?php

namespace App\Http\Controllers;

use App\Services\ElectoralMapCatalogue;
use App\Services\ElectoralMapResults;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElectoralMapController extends Controller
{
    public function results(Request $request, ElectoralMapCatalogue $maps, ElectoralMapResults $results): JsonResponse
    {
        $input = $request->validate(['kind' => 'required|in:pc,ac', 'state' => 'nullable|string|max:100', 'edition' => 'nullable|regex:/^[a-f0-9]{24}$/']);
        $state = isset($input['state']) ? ($maps->all()['states'][$input['state']]['name'] ?? null) : null;
        abort_if(isset($input['state']) && ! $state, 404);

        return response()->json(['records' => $results->records($input['kind'], $state, $input['edition'] ?? null), 'colors' => $results->colors()]);
    }

    public function index(Request $request, ElectoralMapCatalogue $maps, ?string $state = null): View
    {
        $input = $request->validate(['state' => 'nullable|string|max:100', 'kind' => 'nullable|in:pc,ac']);
        $catalogue = $maps->all();
        $stateSlug = $state ?? ($input['state'] ?? 'uttar-pradesh');
        abort_unless(isset($catalogue['states'][$stateSlug]), 404);
        $selectedState = $catalogue['states'][$stateSlug];
        $layerSources = [];
        foreach (['pc', 'ac'] as $kind) {
            $entry = $stateSlug === 'telangana' && $kind === 'ac' ? $catalogue['states']['andhra-pradesh'] : $selectedState;
            $layerSources[$kind] = asset('maps/electoral/'.$entry['file']).'?v='.substr($entry['sha256'], 0, 12);
        }
        $sourceNotes = [
            'telangana' => 'The Assembly source groups Telangana with Andhra Pradesh. Its combined layer is shown without assigning the shapes to present-day states.',
            'andhra-pradesh' => 'The Assembly layer includes both Andhra Pradesh and Telangana as recorded in the source. Present-day state assignment is unverified.',
            'jammu-and-kashmir' => 'The source predates territory changes and recent delimitation. Its Assembly layer is historical and is not the current 90-seat map.',
            'ladakh' => 'The 2019 parliamentary shape was recorded under Jammu & Kashmir. This source has no separate Ladakh Assembly layer.',
            'dadra-and-nagar-haveli-and-daman-and-diu' => 'Both former territories are retained as separate 2019 parliamentary records. They have not been merged geometrically.',
            'assam' => 'The source predates the 2023 delimitation. Its shapes are historical and must not be treated as the current boundaries.',
        ];
        $sourceNote = $sourceNotes[$stateSlug] ?? null;
        $initialKind = $input['kind'] ?? 'pc';

        return view('electoral-map-pilot', compact('catalogue', 'selectedState', 'layerSources', 'sourceNote', 'initialKind'));
    }
}
