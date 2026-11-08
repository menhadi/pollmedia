<?php

namespace App\Http\Middleware;

use App\Services\ElectionArchive;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectSupersededElection
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() || ! $request->routeIs('elections.history', 'states.show', 'constituency.named', 'constituency.overview', 'elections.maps.results')) {
            return $next($request);
        }
        $query = $request->query();
        $changes = [];
        foreach (['edition', 'pc_edition'] as $key) {
            if (($query[$key] ?? null) === ElectionArchive::PC_2019_SUPERSEDED) {
                $changes[$key] = ElectionArchive::PC_2019_FINAL;
            }
        }
        if (! $changes) {
            return $next($request);
        }
        if (isset($changes['edition'], $query['code'])) {
            $code = filter_var($query['code'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 542]]);
            abort_if($code === false, 404);
            // All 542 records match by official state and PC code. Vellore enters the final report at row 328.
            $changes['code'] = $code >= 328 ? $code + 1 : $code;
        }

        return redirect()->to($request->fullUrlWithQuery($changes), 301);
    }
}
