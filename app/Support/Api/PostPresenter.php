<?php

namespace App\Support\Api;

use App\Models\Post;
use App\Models\Tag;
use App\Support\Images\ImagePresenter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/** Turns posts into the arrays the API returns. Relations must already be loaded. */
final class PostPresenter
{
    /** Everything a card needs, and nothing heavy (no content). */
    public const CARD_COLUMNS = [
        'id', 'slug', 'title', 'subtitle', 'excerpt', 'post_type', 'category_id', 'subject_title',
        'featured_image', 'featured_image_alt', 'featured_image_width', 'featured_image_height',
        'published_at', 'updated_at', 'reading_time_minutes', 'rating_avg', 'rating_count', 'review_score',
    ];

    /** Live posts with exactly the columns and relations a card uses. */
    public static function cardQuery(): Builder
    {
        return Post::published()
            ->select(self::CARD_COLUMNS)
            ->with('category:id,name,slug');
    }

    public static function path(string $slug): string
    {
        return rtrim((string) config('azhayon.paths.post'), '/').'/'.$slug;
    }

    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->toIso8601ZuluString();
    }

    /** @return array<string, mixed> */
    public static function card(Post $post): array
    {
        return [
            'slug' => $post->slug,
            'path' => self::path($post->slug),
            'title' => $post->title,
            'subtitle' => $post->subtitle,
            'excerpt' => $post->excerpt,
            'type' => $post->post_type->value,
            'subject' => $post->subject_title,
            'category' => ['name' => $post->category->name, 'slug' => $post->category->slug],
            'image' => ImagePresenter::present(
                $post->featured_image,
                $post->featured_image_width,
                $post->featured_image_height,
                $post->featured_image_alt,
            ),
            'published_at' => self::date($post->published_at),
            'updated_at' => self::date($post->updated_at),
            'reading_time_minutes' => (int) $post->reading_time_minutes,
            'rating' => ['average' => round((float) $post->rating_avg, 2), 'count' => (int) $post->rating_count],
            'review_score' => $post->review_score === null ? null : (float) $post->review_score,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $related
     * @param  array<string, mixed>|null  $series
     * @return array<string, mixed>
     */
    public static function detail(Post $post, array $related, ?array $series): array
    {
        $frontend = rtrim((string) config('azhayon.frontend_url'), '/');

        return self::card($post) + [
            'content' => is_array($post->content) ? $post->content : ['type' => 'doc', 'content' => []],
            'author' => ['name' => $post->author?->name ?? 'Azhayon Media'],
            'tags' => $post->tags
                ->sortBy('name')
                ->map(fn (Tag $tag): array => ['name' => $tag->name, 'slug' => $tag->slug, 'type' => $tag->type->value])
                ->values()
                ->all(),
            'series' => $series,
            'related' => $related,
            'seo' => [
                'title' => $post->meta_title ?: $post->title,
                'description' => $post->meta_description ?: $post->excerpt,
                'canonical_url' => $post->canonical_url ?: $frontend.self::path($post->slug),
                'noindex' => (bool) $post->noindex,
                'image' => ImagePresenter::url($post->og_image) ?? ImagePresenter::url($post->featured_image),
            ],
        ];
    }
}