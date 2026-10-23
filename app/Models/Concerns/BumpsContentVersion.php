<?php

namespace App\Models\Concerns;

use App\Support\Cache\ContentVersion;
use App\Support\Revalidation\PendingRevalidation;
use App\Support\Revalidation\RevalidationTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Public content changes do two things: they make the API's own caches stale
 * (the content version) and queue a notification for the Next.js frontend.
 *
 * Counters updated with query-builder increments (such as view counts) fire
 * no events on purpose, so they never reset caches or notify anyone.
 */
trait BumpsContentVersion
{
    protected static function bootBumpsContentVersion(): void
    {
        $changed = static function (Model $model, string $event): void {
            ContentVersion::bump();

            if (PendingRevalidation::configured()) {
                PendingRevalidation::add(RevalidationTags::forModel($model, $event));
            }
        };

        static::saved(static fn (Model $model) => $changed($model, 'saved'));
        static::deleted(static fn (Model $model) => $changed($model, 'deleted'));

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(static fn (Model $model) => $changed($model, 'restored'));
        }
    }
}