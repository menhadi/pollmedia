<?php

namespace App\Http\Middleware;

use App\Services\GoogleAnalyticsSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\Response;

class GoogleAnalytics
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (str_starts_with(strtolower($response->headers->get('Content-Disposition', '')), 'attachment')) {
            return $response;
        }
        if (! $request->isMethod('GET') || $request->is('admin', 'admin/*', 'api/*') || ! $response->isSuccessful() || ! str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            return $response;
        }
        $settings = app(GoogleAnalyticsSettings::class)->current();
        if (! $settings['enabled'] || ! preg_match('/^G-[A-Z0-9]{4,20}$/D', $settings['measurement_id'])) {
            return $response;
        }
        $html = $response->getContent();
        if (! is_string($html) || stripos($html, '</head>') === false || str_contains($html, 'googletagmanager.com/gtag/js')) {
            return $response;
        }
        $snippet = view('google-analytics-tag', ['measurementId' => $settings['measurement_id'], 'pageLocation' => $request->url()])->render();
        $original = $response instanceof LaravelResponse ? $response->getOriginalContent() : null;
        $response->setContent(preg_replace_callback('~</head>~i', fn () => $snippet.'</head>', $html, 1));
        if ($response instanceof LaravelResponse) {
            $response->original = $original;
        }
        $response->headers->remove('Content-Length');

        return $response;
    }
}
