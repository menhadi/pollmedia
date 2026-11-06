<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockOverloadingCrawlers
{
    private const BLOCKED_AGENTS = '~(?<![a-z0-9_-])(?:Applebot(?:-Extended)?|GPTBot|ShapBot|OAI-SearchBot|Claude-SearchBot|meta-webindexer|SemrushBot|Amzn-SearchBot)(?=[/;)\s]|$)~i';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() && $request->getPathInfo() === '/robots.txt') {
            return $next($request);
        }
        if (preg_match(self::BLOCKED_AGENTS, $request->userAgent() ?? '') === 1) {
            return new Response('Forbidden', 403, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex',
            ]);
        }

        return $next($request);
    }
}
