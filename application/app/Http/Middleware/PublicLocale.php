<?php

namespace App\Http\Middleware;

use App\Services\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $public = ! $request->is('admin', 'admin/*', 'api/*');
        $locale = 'en';
        if ($public) {
            if (in_array($request->query('lang'), ['en', 'hi'], true)) {
                $request->session()->put('public_locale', $request->query('lang'));
            }
            $locale = $request->session()->get('public_locale', 'en');
        }
        app()->setLocale(in_array($locale, ['en', 'hi'], true) ? $locale : 'en');
        config(['public_translations' => array_replace(config('hindi'), app(SiteSettings::class)->get('translations.hi', []))]);
        $response = $next($request);
        if ($public && str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            $response->headers->set('Content-Language', app()->getLocale());
            $response->setPrivate();
        }

        return $response;
    }
}
