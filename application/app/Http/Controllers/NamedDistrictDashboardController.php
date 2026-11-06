<?php

namespace App\Http\Controllers;

use App\Services\DistrictDashboardUrls;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class NamedDistrictDashboardController extends Controller
{
    public function index(string $state, string $district, DistrictDashboardUrls $urls): View|RedirectResponse
    {
        $identity = $urls->resolve($state, $district);
        abort_unless($identity, 404);
        request()->attributes->set('district_identity', $identity);

        return $identity['state'] === '19'
            ? app(WestBengalDistrictDashboardController::class)->index($identity['district'])
            : app(DistrictDashboardController::class)->index($identity['state'], $identity['district']);
    }
}
