<?php

namespace App\Support\Links;

class OwnSite
{
    public static function isOwnHost(string $host): bool
    {
        $own = strtolower((string) parse_url((string) config('azhayon.frontend_url'), PHP_URL_HOST));
        $host = strtolower($host);

        return $own !== '' && ($host === $own || $host === 'www.'.$own || 'www.'.$host === $own);
    }

    /**
     * For a link to our own site (relative, or absolute on our domain) returns the
     * site-relative address, including any query and fragment. Otherwise null.
     */
    public static function relative(string $href): ?string
    {
        $href = trim($href);

        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return $href;
        }

        $parts = parse_url($href);

        if (
            ! is_array($parts)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ! self::isOwnHost((string) ($parts['host'] ?? ''))
        ) {
            return null;
        }

        return ($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}