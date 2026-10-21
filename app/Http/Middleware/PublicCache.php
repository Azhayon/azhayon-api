<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks successful public GET responses as cacheable (browsers, Cloudflare) and
 * answers repeat requests with 304. Usage: PublicCache::class.':maxAge,sMaxAge,stale'
 */
class PublicCache
{
    public function handle(Request $request, Closure $next, ?string $maxAge = null, ?string $sMaxAge = null, ?string $stale = null): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || ! $response->isSuccessful()) {
            return $response;
        }

        $defaults = config('azhayon.api.cache');

        $response->headers->set('Cache-Control', sprintf(
            'public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d',
            (int) ($maxAge ?? $defaults['max_age']),
            (int) ($sMaxAge ?? $defaults['s_maxage']),
            (int) ($stale ?? $defaults['stale']),
        ));

        $response->setEtag(md5((string) $response->getContent()), true);
        $response->isNotModified($request); // turns the response into a 304 when the ETag matches

        return $response;
    }
}