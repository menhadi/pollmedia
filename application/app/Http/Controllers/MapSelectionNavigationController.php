<?php

namespace App\Http\Controllers;

use App\Services\MapSelectionNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MapSelectionNavigationController extends Controller
{
    public function index(Request $request, MapSelectionNavigation $navigation): JsonResponse
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'nullable|required_with:subdistrict|regex:/^[0-9]{1,8}$/', 'subdistrict' => 'nullable|regex:/^[0-9]{1,8}$/']);

        return response()->json($navigation->selection($input['state'], $input['district'] ?? null, $input['subdistrict'] ?? null));
    }

    public function show(Request $request, string $state, string $district, MapSelectionNavigation $navigation, ?string $subdistrict = null): View
    {
        $selection = $navigation->selection($state, $district, $subdistrict);
        $villages = collect($navigation->villages($state, $district, $subdistrict));

        return view('map-selection-place', ['selection' => $selection, 'villages' => $villages->forPage(max(1, (int) $request->query('page', 1)), 50), 'total' => $villages->count(), 'page' => max(1, (int) $request->query('page', 1))]);
    }
}
