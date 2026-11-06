<?php

namespace App\Http\Controllers;

use App\Services\GroundwaterEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroundwaterController extends Controller
{
    public function records(Request $request, GroundwaterEvidence $evidence): JsonResponse
    {
        $input = $request->validate(['state' => 'nullable|regex:/^[0-9]{1,3}$/', 'district' => 'nullable|regex:/^[0-9]{1,8}$/', 'village' => 'nullable|regex:/^[0-9]{1,8}$/', 'census_state' => 'nullable|required_with:census_district|regex:/^[0-9]{1,3}$/', 'census_district' => 'nullable|required_with:census_state|regex:/^[0-9]{1,8}$/']);
        if (isset($input['census_state'])) {
            $identity = $evidence->censusIdentity($input['census_state'], $input['census_district']);
            if (! $identity || isset($input['state']) || isset($input['district'])) {
                return response()->json(['status' => 'unavailable', 'stations' => [], 'sources' => [], 'datasets' => [], 'note' => 'No unique accepted Census to current district correspondence.']);
            }
            $input['state'] = $identity['state'];
            $input['district'] = $identity['district'];
        }
        if (isset($input['village'])) {
            $identity = $evidence->villageIdentity($input['village'], $input['state'] ?? null);
            if (! $identity || (isset($input['district']) && $identity['district'] !== $input['district'])) {
                return response()->json(['status' => 'unavailable', 'stations' => [], 'sources' => [], 'datasets' => [], 'note' => 'Village identity is unavailable or conflicts with the selected area.']);
            }
            $input['state'] = $identity['state'];
            $input['district'] = $identity['district'];
        }

        return response()->json($evidence->scope($input['state'] ?? null, $input['district'] ?? null, $input['village'] ?? null));
    }

    public function show(Request $request, GroundwaterEvidence $evidence): View
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'village' => 'nullable|regex:/^[0-9]{1,8}$/', 'station' => 'nullable|regex:/^[a-f0-9]{16}$/']);
        $pilot = $evidence->district($input['state'], $input['district']);
        abort_unless($pilot, 404, 'No accepted groundwater snapshot for this district.');

        return view('pilibhit-groundwater', ['pilot' => $pilot, 'waterContext' => $input]);
    }
}
