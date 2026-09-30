<?php

namespace App\Http\Middleware;

use App\Services\SeoPages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class PageMetadata
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
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
        $response->setContent($html);

        return $response;
    }
}
