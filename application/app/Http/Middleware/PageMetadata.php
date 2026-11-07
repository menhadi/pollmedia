<?php

namespace App\Http\Middleware;

use App\Services\PublicPageCanonical;
use App\Services\SeoPages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class PageMetadata
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (str_starts_with(strtolower($response->headers->get('Content-Disposition', '')), 'attachment')) {
            return $response;
        }
        if (! $request->is('admin', 'admin/*', 'api/*') && str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            $html = $response->getContent();
            if (is_string($html) && stripos($html, '</head>') !== false) {
                $assets = '<link rel="stylesheet" href="'.e(asset('css/place-topics.css')).'?v=6"><script defer src="'.e(asset('js/place-topics.js')).'?v=6"></script>';
                $original = $response instanceof LaravelResponse ? $response->getOriginalContent() : null;
                $response->setContent(str_ireplace('</head>', $assets.'</head>', $html));
                if ($response instanceof LaravelResponse) {
                    $response->original = $original;
                }
                $response->headers->remove('Content-Length');
            }
        }
        app(PublicPageCanonical::class)->apply($request, $response);
        if ($request->is('admin', 'admin/*', 'api/*') || ! $response->isSuccessful() || ! str_contains($response->headers->get('Content-Type', ''), 'text/html') || ! Schema::hasTable('seo_metadata')) {
            return $response;
        }
        $meta = app(SeoPages::class)->current($request->getRequestUri());
        if (! $meta) {
            return $response;
        }
        $html = $response->getContent();
        if ($meta->title) {
            $html = preg_replace_callback('~<title>.*?</title>~is', fn () => '<title>'.e($meta->title).'</title>', $html, 1);
        }
        if ($meta->description) {
            $html = preg_replace('~<meta\s+name=["\']description["\'][^>]*>~i', '', $html);
            $html = str_replace('</head>', '<meta name="description" content="'.e($meta->description).'"></head>', $html);
        }
        foreach (['title' => $meta->title, 'description' => $meta->description] as $field => $value) {
            if ($value) {
                $html = preg_replace('~<meta\b(?=[^>]*\b(?:name|property)\s*=\s*["\'](?:og:|twitter:)'.$field.'["\'])[^>]*>~i', '', $html);
                $html = str_replace('</head>', '<meta property="og:'.$field.'" content="'.e($value).'"><meta name="twitter:'.$field.'" content="'.e($value).'"></head>', $html);
            }
        }
        if ($meta->keywords ?? null) {
            $html = preg_replace('~<meta\s+name=["\']keywords["\'][^>]*>~i', '', $html);
            $html = str_replace('</head>', '<meta name="keywords" content="'.e($meta->keywords).'"></head>', $html);
        }
        $original = $response instanceof LaravelResponse ? $response->getOriginalContent() : null;
        $response->setContent($html);
        if ($response instanceof LaravelResponse) {
            $response->original = $original;
        }

        return $response;
    }
}
