<?php

namespace App\Support\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Every cached API query is stored under a key that includes the current
 * content version. Changing any public content bumps the version, which makes
 * all older entries unreachable at once (they expire by their own TTL).
 */
final class ContentVersion
{
    private const KEY = 'content-version';

    public static function current(): int
    {
        return (int) Cache::rememberForever(self::KEY, fn (): int => self::stamp());
    }

    public static function bump(): int
    {
        $next = max(self::stamp(), self::current() + 1);

        Cache::forever(self::KEY, $next);

        return $next;
    }

    /** @template T  @param  Closure(): T  $callback  @return T */
    public static function remember(string $key, int $seconds, Closure $callback): mixed
    {
        return Cache::remember('api:'.self::current().':'.$key, $seconds, $callback);
    }

    private static function stamp(): int
    {
        return (int) (microtime(true) * 1000);
    }
}