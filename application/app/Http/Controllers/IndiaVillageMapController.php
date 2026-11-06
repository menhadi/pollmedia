<?php

namespace App\Http\Controllers;

use App\Services\ElectoralVillageMap;
use App\Services\IndiaVillageMap;
use App\Services\VillageMapMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IndiaVillageMapController extends Controller
{
    public function index(IndiaVillageMap $map): View
    {
        $electoral = app(ElectoralVillageMap::class);

        return view('india-village-map', ['catalogue' => $map->catalogue(), 'electoral' => is_file($electoral->directory().'/links.sqlite') ? $electoral->catalogue() : ['states' => [], 'seats' => []]]);
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

    public function detail(string $id, IndiaVillageMap $map): JsonResponse
    {
        return response()->json($map->place($id));
    }

    public function hierarchy(Request $request, ElectoralVillageMap $map): JsonResponse
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'nullable|regex:/^[0-9]{1,8}$/', 'subdistrict' => 'nullable|regex:/^[0-9]{1,8}$/']);
        abort_if(! empty($input['subdistrict']) && empty($input['district']), 422);

        $version = array_map(fn (string $file): string => is_file($file) ? filemtime($file).':'.filesize($file) : 'missing', [$map->directory().'/links.sqlite', $map->directory().'/display-links.sqlite']);
        $urbanPath = database_path('fixtures/lgd-urban-districts.json');
        $version[] = is_file($urbanPath) ? hash_file('sha256', $urbanPath) : 'missing';
        $key = 'hierarchy-map-v2:'.hash('sha256', json_encode([$version, $input]));

        return response()->json(Cache::remember($key, 300, fn (): array => $map->hierarchy($input['state'], $input['district'] ?? '', $input['subdistrict'] ?? '')))->header('Cache-Control', 'private, max-age=60');
    }

    public function area(Request $request, ElectoralVillageMap $map): JsonResponse
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'subdistrict' => 'nullable|regex:/^[0-9]{1,8}$/']);

        $version = array_map(fn (string $file): string => is_file($file) ? filemtime($file).':'.filesize($file) : 'missing', [$map->directory().'/links.sqlite', $map->directory().'/display-links.sqlite']);
        $key = 'area-map-v1:'.hash('sha256', json_encode([$version, $input]));

        return response()->json(Cache::remember($key, 300, fn (): array => $map->area($input['state'], $input['district'], $input['subdistrict'] ?? '')))->header('Cache-Control', 'private, max-age=60');
    }

    public function village(string $code, ElectoralVillageMap $map, IndiaVillageMap $national, Request $request): JsonResponse
    {
        $request->validate(['records' => 'nullable|boolean']);
        if ($request->boolean('records')) {
            return response()->json($map->villageRecords($code));
        }

        return response()->json($map->mapVillage($code, $national));
    }

    public function history(Request $request): JsonResponse
    {
        $input = $request->validate(['codes' => ['required', 'string', 'regex:/^[0-9]{1,8}(,[0-9]{1,8}){0,199}$/']]);

        return response()->json(['year' => 2001, 'records' => (object) app(\App\Services\VillageProfileLinks::class)->mapHistory(explode(',', $input['codes']))]);
    }

    public function metrics(Request $request, string $layer, VillageMapMetrics $metrics): JsonResponse
    {
        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
        if ($memoryLimit > 0 && $memoryLimit < 268435456) {
            ini_set('memory_limit', '256M');
        }

        $input = $request->validate(['sha' => 'required|regex:/^[a-f0-9]{64}$/', 'year' => 'required|integer|in:2011']);

        return response()->json($metrics->layer($layer, $input['sha'], (int) $input['year']))->header('Cache-Control', 'no-store');
    }
}
