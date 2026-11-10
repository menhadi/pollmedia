<?php

namespace App\Http\Controllers;

use App\Services\PlacePostalCodes;
use App\Services\PostalPlaceNavigation;
use App\Services\UrbanPostalParents;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlacePostalController extends Controller
{
    public function index(Request $request, PlacePostalCodes $postal): View
    {
        $validated = $request->validate(['pin' => ['nullable', 'regex:/^[1-9][0-9]{5}$/D'], 'page' => ['nullable', 'integer', 'min:1', 'max:20000'], 'q' => ['nullable', 'string', 'max:100']]);
        $pin = (string) ($validated['pin'] ?? '');
        $query = trim((string) ($validated['q'] ?? ''));
        $results = $postal->search($pin, (int) ($validated['page'] ?? 1), $query);
        $results['rows'] = app(PostalPlaceNavigation::class)->rows($results['rows']);

        return view('postal-areas', compact('pin', 'results', 'query'));
    }

    public function show(string $kind, string $code, PlacePostalCodes $postal): View
    {
        $result = $postal->lookup($kind, $code);
        abort_unless($result['place'], 404);
        $place = app(PostalPlaceNavigation::class)->rows([$result['place']])[0];
        $villageUrl = $place['current_identity_linked'] ? $place['place_url'] : null;

        return view('postal-area', ['postal' => $result, 'place' => $place, 'villageUrl' => $villageUrl]);
    }

    public function state(string $state, PostalPlaceNavigation $navigation): View
    {
        $directory = $navigation->state($state);
        abort_unless($directory, 404);

        return view('postal-state', $directory);
    }

    public function geography(string $state, string $level, string $code, UrbanPostalParents $parents, PlacePostalCodes $postal): View
    {
        $scope = $parents->scope($state, $level, $code);
        abort_unless($scope, 404);
        $bodies = [];
        foreach ($scope['bodies'] as $body) {
            $place = $postal->lookup('local-body', $body, $state)['place'];
            if ($place) {
                $bodies[] = $place;
            }
        }

        return view('postal-geography', compact('scope', 'bodies'));
    }
}
