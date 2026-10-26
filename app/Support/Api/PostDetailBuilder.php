<?php

namespace App\Support\Api;

use App\Models\Post;
use App\Support\Links\LinkSuggester;
use Illuminate\Database\Eloquent\Builder;

/** Builds the full data for a post page. Shared by the public endpoint and the signed draft preview. */
final class PostDetailBuilder
{
    private const RELATED_LIMIT = 6;

    public function __construct(private readonly LinkSuggester $suggester) {}

    /** @return array<string, mixed> */
    public function build(Post $post): array
    {
        $post->loadMissing([
            'category:id,name,slug',
            'author:id,name',
            'tags:id,name,slug,type',
            'series:id,title,slug',
        ]);

        return PostPresenter::detail($post, $this->related($post), $this->seriesNavigation($post));
    }

    /** @return list<array<string, mixed>> */
    private function related(Post $post): array
    {
        $ids = array_column($this->suggester->suggest([
            'category_id' => $post->category_id,
            'series_id' => $post->series_id,
            'subject_title' => $post->subject_title,
            'tags' => $post->tags->pluck('id')->all(),
        ], $post->id, self::RELATED_LIMIT), 'id');

        if ($ids === []) {
            return [];
        }

        return PostPresenter::cardQuery()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Post $related): int => (int) array_search($related->id, $ids, true))
            ->map(fn (Post $related): array => PostPresenter::card($related))
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function seriesNavigation(Post $post): ?array
    {
        if ($post->series === null) {
            return null;
        }

        // A draft has no publish date yet: treat it as if it were published right now.
        $moment = $post->published_at ?? now();

        $link = fn (?Post $other): ?array => $other === null ? null : [
            'title' => $other->title,
            'slug' => $other->slug,
            'path' => PostPresenter::path($other->slug),
        ];

        $inSeries = fn () => Post::published()->where('series_id', $post->series_id);

        $previous = $inSeries()
            ->where(fn (Builder $q) => $q->where('published_at', '<', $moment)
                ->orWhere(fn (Builder $same) => $same->where('published_at', $moment)->where('id', '<', $post->id)))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->first(['id', 'title', 'slug']);

        $next = $inSeries()
            ->where(fn (Builder $q) => $q->where('published_at', '>', $moment)
                ->orWhere(fn (Builder $same) => $same->where('published_at', $moment)->where('id', '>', $post->id)))
            ->orderBy('published_at')->orderBy('id')
            ->first(['id', 'title', 'slug']);

        return [
            'title' => $post->series->title,
            'slug' => $post->series->slug,
            'path' => rtrim((string) config('azhayon.paths.series'), '/').'/'.$post->series->slug,
            'total' => $inSeries()->count(),
            'previous' => $link($previous),
            'next' => $link($next),
        ];
    }
}