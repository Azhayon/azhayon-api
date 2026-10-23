<?php

namespace App\Support\Revalidation;

use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Series;
use App\Models\Setting;
use App\Models\Tag;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Which cached frontend data a change affects. The tags are coarse on purpose:
 * marking pages stale is cheap (they rebuild on the next visit), while a missed
 * tag means a stale page.
 */
final class RevalidationTags
{
    /** The only shape a tag may have; the receiving end must enforce the same pattern. */
    public const PATTERN = '/^[a-z0-9][a-z0-9:_-]{0,189}$/';

    /**
     * @param  'saved'|'deleted'|'restored'  $event
     * @return list<string>
     */
    public static function forModel(Model $model, string $event): array
    {
        return match (true) {
            $model instanceof Post => self::forPost($model, $event),
            $model instanceof Category, $model instanceof Tag, $model instanceof Series => ['taxonomy', 'posts', 'home', 'sitemap', 'site'],
            $model instanceof Redirect => ['redirects'],
            $model instanceof Setting => ['site'],
            default => [],
        };
    }

    /** @return list<string> */
    private static function forPost(Post $post, string $event): array
    {
        $isLive = self::isLive($post->status, $post->published_at);

        // On an edit, a post that WAS live matters even if it no longer is (unpublishing).
        $wasLive = $event === 'saved'
            ? self::isLive($post->getOriginal('status'), $post->getOriginal('published_at'))
            : $isLive;

        if (! $isLive && ! $wasLive) {
            return []; // drafts and future posts are invisible to visitors
        }

        $tags = ['posts', 'home', 'taxonomy', 'sitemap', 'post:'.$post->slug];

        // During the "saved" event the original attributes still hold the old values. A newly
        // created post has none, so only a genuine rename of an existing post gets through.
        $originalSlug = $post->getOriginal('slug');

        if ($event === 'saved' && is_string($originalSlug) && $originalSlug !== $post->slug) {
            $tags[] = 'post:'.$originalSlug;
            $tags[] = 'redirects';
        }

        return $tags;
    }

    private static function isLive(mixed $status, mixed $publishedAt): bool
    {
        $status = $status instanceof BackedEnum ? $status->value : $status;

        if ($status !== PostStatus::Published->value || $publishedAt === null) {
            return false;
        }

        return Carbon::parse($publishedAt)->lte(now());
    }
}