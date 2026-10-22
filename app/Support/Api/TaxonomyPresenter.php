<?php

namespace App\Support\Api;

use App\Models\Category;
use App\Models\Series;
use App\Models\Tag;
use App\Support\Images\ImagePresenter;
use Carbon\Carbon;

/**
 * Presents categories, tags and series. Models must come from a query that
 * loaded posts_count (live posts only); series also need latest_post_at.
 */
final class TaxonomyPresenter
{
    /** @param  'category'|'tag'|'series'  $kind */
    public static function path(string $kind, string $slug): string
    {
        return rtrim((string) config("azhayon.paths.{$kind}"), '/').'/'.$slug;
    }

    private static function canonical(string $kind, string $slug): string
    {
        return rtrim((string) config('azhayon.frontend_url'), '/').self::path($kind, $slug);
    }

    /** @return array<string, mixed> */
    public static function category(Category $category): array
    {
        return [
            'name' => $category->name,
            'slug' => $category->slug,
            'path' => self::path('category', $category->slug),
            'description' => $category->description,
            'posts_count' => (int) $category->posts_count,
        ];
    }

    /** @return array<string, mixed> */
    public static function categoryDetail(Category $category): array
    {
        return self::category($category) + ['seo' => [
            'title' => $category->name,
            'description' => $category->description,
            'canonical_url' => self::canonical('category', $category->slug),
            'noindex' => false,
        ]];
    }

    /** @return array<string, mixed> */
    public static function tag(Tag $tag): array
    {
        return [
            'name' => $tag->name,
            'slug' => $tag->slug,
            'type' => $tag->type->value,
            'path' => self::path('tag', $tag->slug),
            'posts_count' => (int) $tag->posts_count,
        ];
    }

    /** @return array<string, mixed> */
    public static function tagDetail(Tag $tag): array
    {
        return self::tag($tag) + ['seo' => [
            'title' => $tag->name,
            'description' => null,
            'canonical_url' => self::canonical('tag', $tag->slug),
            // A tag page with only a post or two is thin content: keep it out of search results.
            'noindex' => (int) $tag->posts_count < (int) config('azhayon.api.min_tag_posts'),
        ]];
    }

    /** @return array<string, mixed> */
    public static function series(Series $series): array
    {
        $latest = $series->latest_post_at;

        return [
            'title' => $series->title,
            'slug' => $series->slug,
            'path' => self::path('series', $series->slug),
            'description' => $series->description,
            'image' => ImagePresenter::present($series->cover_image, $series->cover_width, $series->cover_height, $series->title),
            'posts_count' => (int) $series->posts_count,
            'latest_post_at' => $latest === null ? null : Carbon::parse($latest)->utc()->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function seriesDetail(Series $series): array
    {
        return self::series($series) + ['seo' => [
            'title' => $series->meta_title ?: $series->title,
            'description' => $series->meta_description ?: $series->description,
            'canonical_url' => self::canonical('series', $series->slug),
            'image' => ImagePresenter::url($series->cover_image),
            'noindex' => false,
        ]];
    }
}