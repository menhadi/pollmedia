<?php

namespace App\Http\Controllers;

use App\Services\IssueLocationResolver;
use App\Services\MyPlaceDirectory;
use App\Services\OfficialMarketPrices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

class MyPlaceHubController extends Controller
{
    public function markets(Request $request): JsonResponse
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'commodity' => 'nullable|string|max:160', 'offset' => 'nullable|integer|min:0|max:1000000']);
        $context = $this->marketContext($input);

        return response()->json(app(OfficialMarketPrices::class)->forPlace($context, $input['commodity'] ?? null, (int) ($input['offset'] ?? 0)));
    }

    public function marketHistory(Request $request): JsonResponse
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'commodity' => 'required|string|max:160', 'variety' => 'present|nullable|string|max:160', 'grade' => 'present|nullable|string|max:80']);

        return response()->json(app(OfficialMarketPrices::class)->history($this->marketContext($input), $input['commodity'], $input['variety'] ?? '', $input['grade'] ?? ''));
    }

    private function marketContext(array $input): array
    {
        $directory = app(MyPlaceDirectory::class);
        $state = collect($directory->states())->firstWhere('code', $input['state']);
        $district = collect($directory->districts($input['state']))->firstWhere('code', $input['district']);
        abort_unless($state && $district, 404);

        return ['state' => $state, 'district' => $district];
    }

    public function report(Request $request): JsonResponse
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'village' => 'nullable|regex:/^[0-9]{1,8}$/', 'place_confirmed' => 'accepted']);
        $context = app(MyPlaceDirectory::class)->context($input['state'], $input['district'], $input['village'] ?? null);
        $location = $context['village'] ? app(IssueLocationResolver::class)->village($context['village']['code']) : [
            'name' => $context['district']['name'], 'state' => $context['state']['code'], 'district' => $context['district']['code'],
            'identity_key' => 'district:'.$context['state']['code'].':'.$context['district']['code'], 'source' => ['basis' => 'confirmed_existing_district_directory'],
            'scopes' => [['key' => 'state:'.$context['state']['code'], 'name' => $context['state']['name'], 'kind' => 'state'], ['key' => 'district:'.$context['state']['code'].':'.$context['district']['code'], 'name' => $context['district']['name'], 'kind' => 'district']],
        ];
        $token = (string) Str::uuid();
        $session = new Store('my-place-report', new ArraySessionHandler(30));
        $session->start();
        $session->put('issue_locations', [$token => ['location' => $location, 'coordinates' => null, 'expires' => now()->addMinutes(30)->timestamp]]);
        $report = $request->duplicate(array_replace($request->request->all(), ['location_token' => $token, 'location_confirmed' => '1', 'reporter_token' => null]));
        $report->setLaravelSession($session);
        $response = app(LocatedIssueController::class)->store($report);

        return response()->json(['url' => $response->getTargetUrl()], 201)->header('Cache-Control', 'private, no-store');
    }
}
