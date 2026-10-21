<?php

namespace App\Models\Concerns;

use App\Support\Cache\ContentVersion;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Public API caches become stale whenever one of these models changes.
 * Counters updated with query-builder increments (such as view counts) fire
 * no events on purpose, so they never reset the caches.
 */
trait BumpsContentVersion
{
    protected static function bootBumpsContentVersion(): void
    {
        $bump = static fn () => ContentVersion::bump();

        static::saved($bump);
        static::deleted($bump);

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored($bump);
        }
    }
}