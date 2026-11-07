<?php

namespace App\Http\Controllers;

use App\Services\ElectoralVillageMap;
use App\Services\NamedPlaceUrls;
use App\Services\PlaceTopics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ElectoralVillageMapController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('india-map.index', $request->only(['state', 'kind', 'code', 'name']));
    }

    public function membership(Request $request, ElectoralVillageMap $map): JsonResponse
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'kind' => 'required|in:pc,ac', 'code' => 'required|regex:/^[0-9]{1,8}$/']);

        return response()->json($map->membership($input['state'], $input['kind'], $input['code']));
    }

    public function geometry(string $layer, ElectoralVillageMap $map): BinaryFileResponse|JsonResponse
    {
        $path = $map->geometryPath($layer);
        if (str_ends_with($path, '.json')) {
            $source = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (isset($source['rows'])) {
                $features = array_map(fn (array $row): array => ['type' => 'Feature', 'properties' => ['name' => $row['name'], 'source_code' => $row['code'], 'subdistrict' => $row['subdistrict']], 'geometry' => $row['geometry'] ?? null], $source['rows']);

                return response()->json(['type' => 'FeatureCollection', 'features' => $features], headers: ['Content-Type' => 'application/geo+json']);
            }
        }

        return response()->file($path, ['Content-Type' => 'application/geo+json', 'Cache-Control' => 'public, max-age=300']);
    }

    public function namedVillage(Request $request, string $state, string $district, string $slug, string $code, ElectoralVillageMap $map): View|RedirectResponse
    {
        return $this->village($code, $map, $request);
    }

    public function village(string $code, ElectoralVillageMap $map, Request $request): View|RedirectResponse
    {
        $village = $map->village($code, PlaceTopics::selected('village') === 'overview');
        $canonical = app(NamedPlaceUrls::class)->village($village, false);
        if ($request->getPathInfo() !== $canonical) {
            return redirect(url($canonical).($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
        }

        return view('electoral-village-place', compact('village'));
    }
}
