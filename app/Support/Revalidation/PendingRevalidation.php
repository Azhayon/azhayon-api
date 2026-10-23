<?php

namespace App\Support\Revalidation;

use Illuminate\Support\Facades\DB;

/** The outbox of tags waiting to be sent to the frontend. */
final class PendingRevalidation
{
    public static function configured(): bool
    {
        $secret = config('azhayon.revalidate.secret');

        return is_string($secret) && strlen($secret) >= 32 && self::endpoint() !== null;
    }

    public static function endpoint(): ?string
    {
        $url = config('azhayon.revalidate.url')
            ?: rtrim((string) config('azhayon.frontend_url'), '/').'/api/revalidate';

        $parts = parse_url((string) $url);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            ? (string) $url
            : null;
    }

    /**
     * Records tags to be sent on the next flush. Does nothing until revalidation is configured.
     *
     * @param  list<string>  $tags
     */
    public static function add(array $tags): void
    {
        if (! self::configured()) {
            return;
        }

        $tags = array_values(array_unique(array_filter(
            $tags,
            fn (mixed $tag): bool => is_string($tag) && preg_match(RevalidationTags::PATTERN, $tag) === 1,
        )));

        if ($tags === []) {
            return;
        }

        DB::table('pending_revalidations')->insertOrIgnore(
            array_map(fn (string $tag): array => ['tag' => $tag, 'created_at' => now()], $tags)
        );
    }
}