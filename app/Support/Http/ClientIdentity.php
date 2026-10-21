<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * Next.js calls this API from its own server, so every visitor would look like
 * one IP. When a request proves it comes from our frontend (shared secret),
 * the visitor IP it forwards is used for rate limiting instead.
 */
final class ClientIdentity
{
    public static function isTrustedFrontend(Request $request): bool
    {
        $expected = config('azhayon.api.frontend_key');
        $given = $request->header('X-Frontend-Key');

        // A short or missing key never counts, so an unset .env cannot match an empty header.
        return is_string($expected)
            && strlen($expected) >= 32
            && is_string($given)
            && hash_equals($expected, $given);
    }

    public static function ip(Request $request): string
    {
        if (self::isTrustedFrontend($request)) {
            $forwarded = trim((string) $request->header('X-Client-IP'));

            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                return $forwarded;
            }
        }

        return $request->ip() ?? 'unknown';
    }
}