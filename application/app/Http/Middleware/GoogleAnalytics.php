<?php

namespace App\Http\Middleware;

use App\Services\GoogleAnalyticsSettings;
use Closure;
use Illuminate\Http\Request;
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
        $response->setContent(preg_replace_callback('~</head>~i', fn () => $snippet.'</head>', $html, 1));
        $response->headers->remove('Content-Length');

        return $response;
    }
}
