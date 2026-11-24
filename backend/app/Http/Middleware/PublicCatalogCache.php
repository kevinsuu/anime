<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks successful catalog reads as shareable so Cloudflare can serve them
 * from the edge. Catalog data is the same for every visitor and only changes
 * through the weekly import, so a few minutes of staleness is harmless.
 *
 * Only 200 responses are marked: errors (404, 422, 5xx) must not be pinned at
 * the edge, which is why Laravel's generic cache.headers middleware (which
 * applies to any status) is not used here.
 */
final class PublicCatalogCache
{
    private const BROWSER_MAX_AGE = 60;

    private const EDGE_MAX_AGE = 300;

    private const STALE_WHILE_REVALIDATE = 600;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $response->setCache([
            'public' => true,
            'max_age' => self::BROWSER_MAX_AGE,
            's_maxage' => self::EDGE_MAX_AGE,
            'stale_while_revalidate' => self::STALE_WHILE_REVALIDATE,
            'etag' => hash('xxh128', (string) $response->getContent()),
        ]);
        $response->isNotModified($request);

        return $response;
    }
}
