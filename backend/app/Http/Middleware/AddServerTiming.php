<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds `Server-Timing: app;dur=<ms>` so the time spent inside Laravel can be
 * told apart from network time (Cloudflare to origin) in browser dev tools
 * or curl, without access to the host. Only a duration is exposed.
 */
final class AddServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (microtime(true) - $start) * 1000;
        $response->headers->set('Server-Timing', sprintf('app;dur=%.1f', $durationMs), false);

        return $response;
    }
}
