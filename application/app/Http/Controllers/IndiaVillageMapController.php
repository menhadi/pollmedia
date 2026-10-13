<?php

namespace App\Http\Controllers;

use App\Services\IndiaVillageMap;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IndiaVillageMapController extends Controller
{
    public function index(IndiaVillageMap $map): View
    {
        return view('india-village-map', ['catalogue' => $map->catalogue()]);
    }

    public function catalogue(IndiaVillageMap $map): JsonResponse
    {
        return response()->json($map->catalogue());
    }

    public function search(Request $request, IndiaVillageMap $map): JsonResponse
    {
        $input = $request->validate(['q' => 'required|string|min:2|max:100', 'state' => 'nullable|string|max:100', 'layer' => 'nullable|regex:/^[a-f0-9]{20}$/']);

        return response()->json(['results' => $map->search($input['q'], $input['state'] ?? '', $input['layer'] ?? ''), 'limit' => 25]);
    }

    public function geometry(string $layer, IndiaVillageMap $map): BinaryFileResponse
    {
        return response()->file($map->geometryPath($map->layer($layer)), ['Content-Type' => 'application/geo+json', 'Cache-Control' => 'public, max-age=300']);
    }

    public function feature(Request $request, string $layer, int $index, IndiaVillageMap $map): JsonResponse
    {
        $input = $request->validate(['sha' => 'nullable|regex:/^[a-f0-9]{64}$/']);
        if (isset($input['sha'])) {
            abort_unless(hash_equals($map->layer($layer)['sha256'], $input['sha']), 409, 'The map catalogue changed. Refresh the map before selecting this shape.');
        }

        return response()->json($map->feature($layer, $index));
    }

    public function place(string $id, IndiaVillageMap $map): View
    {
        $place = $map->place($id);

        return view('india-map-place', ['place' => $place, 'layer' => $map->layer($place['layer'])]);
    }

    public function detail(string $id, IndiaVillageMap $map): JsonResponse
    {
        return response()->json($map->place($id));
    }
}
