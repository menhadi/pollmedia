<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicPageCanonical
{
    public function apply(Request $request, Response $response): void
    {
        if (str_starts_with(strtolower($response->headers->get('Content-Disposition', '')), 'attachment') || ! $request->isMethodSafe() || $request->is('admin', 'admin/*', 'api/*', 'login', 'register', 'logout', 'account', 'account/*', 'password/*') || ! $response->isSuccessful() || ! str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            return;
        }
        $html = $response->getContent();
        if (! str_contains($html, '</head>')) {
            return;
        }
        $utility = $request->is('report-problem');
        $filtered = $utility || $request->is('india/map', 'india/elections/constituencies', 'search');
        $canonical = null;
        if (! $filtered && preg_match('~<link\b(?=[^>]*\brel=["\']canonical["\'])(?=[^>]*\bhref=["\']([^"\']+)["\'])[^>]*>~i', $html, $match)) {
            $canonical = html_entity_decode($match[1], ENT_QUOTES);
        }
        if ($canonical === null) {
            $canonical = 'https://pollmedia.org'.$request->getPathInfo();
            if (! $filtered && $request->query('year') === '2001' && $request->is('india/village/*', 'india/villages')) {
                $canonical .= '?year=2001';
            }
        }
        $canonical = preg_replace('~^https?://(?:www\.)?pollmedia\.org(?=/|$)~i', 'https://pollmedia.org', $canonical);
        if (str_starts_with($canonical, '/')) {
            $canonical = 'https://pollmedia.org'.$canonical;
        }
        $html = preg_replace('~<link\b(?=[^>]*\brel=["\']canonical["\'])[^>]*>~i', '', $html);
        $html = preg_replace('~<meta\b(?=[^>]*\bproperty=["\']og:url["\'])[^>]*>~i', '', $html);
        $tags = '<link rel="canonical" href="'.e($canonical).'"><meta property="og:url" content="'.e($canonical).'">';
        $filters = array_diff(array_keys($request->query()), ['lang', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid']);
        if ($utility || ($filtered && $filters !== [])) {
            $nofollow = str_contains(strtolower($response->headers->get('X-Robots-Tag', '')), 'nofollow');
            $robots = $nofollow ? 'noindex,nofollow' : 'noindex,follow';
            $html = preg_replace('~<meta\b(?=[^>]*\bname=["\']robots["\'])[^>]*>~i', '', $html);
            $tags .= '<meta name="robots" content="'.$robots.'">';
            $response->headers->set('X-Robots-Tag', $nofollow ? 'noindex, nofollow' : 'noindex, follow');
        }
        $original = $response instanceof LaravelResponse ? $response->getOriginalContent() : null;
        $response->setContent(str_replace('</head>', $tags.'</head>', $html));
        if ($response instanceof LaravelResponse) {
            $response->original = $original;
        }
    }
}
