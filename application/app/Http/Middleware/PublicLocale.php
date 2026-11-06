<?php

namespace App\Http\Middleware;

use App\Services\PublicLanguage;
use App\Services\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() === 'www.pollmedia.org' && $request->isMethodSafe()
            && $request->is('/', 'india', 'india/*', 'sources', 'sources/*', 'reports/*', 'explore', 'explore/*', 'data', 'search', 'sitemap.xml', 'robots.txt')) {
            return redirect()->away('https://pollmedia.org'.$request->getRequestUri(), 301);
        }

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
        if ($public && ! str_starts_with(strtolower($response->headers->get('Content-Disposition', '')), 'attachment') && str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            $response->headers->set('Content-Language', app()->getLocale());
            $response->setPrivate();
            if (app()->getLocale() === 'hi') {
                $original = $response instanceof LaravelResponse ? $response->getOriginalContent() : null;
                $response->setContent(PublicLanguage::html($response->getContent()));
                if ($response instanceof LaravelResponse) {
                    $response->original = $original;
                }
            }
        }

        return $response;
    }
}
