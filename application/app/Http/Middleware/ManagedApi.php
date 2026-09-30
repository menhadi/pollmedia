<?php

namespace App\Http\Middleware;

use App\Services\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ManagedApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }
        $path = $request->route()?->uri() ?? $request->path();
        $settings = app(SiteSettings::class)->get('apis', [])[$path] ?? ['enabled' => true, 'limit' => 120];
        if (! $settings['enabled']) {
            return response()->json(['message' => 'This API is temporarily disabled.'], 503)->header('Retry-After', '300');
        }
        $key = 'managed-api:'.hash('sha256', $path.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, (int) $settings['limit'])) {
            return response()->json(['message' => 'Too many requests.'], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
