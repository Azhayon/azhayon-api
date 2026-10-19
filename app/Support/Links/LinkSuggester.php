<?php

namespace App\Support\Links;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

class LinkSuggester
{
    /**
     * Published posts related to the one being written, best match first.
     *
     * @param  array<string, mixed>  $context  category_id, series_id, subject_title, tags (ids)
     * @return list<array{id: int, title: string, path: string}>
     */
    public function suggest(array $context, ?int $exceptPostId = null, int $limit = 8): array
    {
        $categoryId = $this->id($context['category_id'] ?? null);
        $seriesId = $this->id($context['series_id'] ?? null);
        $subject = is_string($context['subject_title'] ?? null) ? trim($context['subject_title']) : '';
        $tagIds = collect(is_array($context['tags'] ?? null) ? $context['tags'] : [])
            ->map(fn ($id) => $this->id($id))
            ->filter()
            ->values()
            ->all();

        $hasSignals = $categoryId || $seriesId || $subject !== '' || $tagIds !== [];

        $candidates = Post::published()
            ->when($exceptPostId, fn (Builder $query) => $query->whereKeyNot($exceptPostId))
            ->when($hasSignals, fn (Builder $query) => $query->where(function (Builder $query) use ($categoryId, $seriesId, $subject, $tagIds): void {
                if ($seriesId) {
                    $query->orWhere('series_id', $seriesId);
                }

                if ($subject !== '') {
                    $query->orWhere('subject_title', $subject);
                }

                if ($categoryId) {
                    $query->orWhere('category_id', $categoryId);
                }

                if ($tagIds !== []) {
                    $query->orWhereHas('tags', fn (Builder $tags) => $tags->whereIn('tags.id', $tagIds));
                }
            }))
            ->with('tags:id')
            ->orderByDesc('published_at')
            ->limit(200)
            ->get(['id', 'title', 'slug', 'series_id', 'category_id', 'subject_title', 'published_at']);

        $prefix = rtrim((string) config('azhayon.paths.post'), '/');

        return $candidates
            ->map(function (Post $post) use ($categoryId, $seriesId, $subject, $tagIds, $prefix): array {
                $score = ($seriesId && $post->series_id === $seriesId ? 5 : 0)
                    + 3 * count(array_intersect($tagIds, $post->tags->pluck('id')->all()))
                    + ($subject !== '' && $post->subject_title === $subject ? 2 : 0)
                    + ($categoryId && $post->category_id === $categoryId ? 1 : 0);

                return ['score' => $score, 'id' => $post->id, 'title' => $post->title, 'path' => $prefix.'/'.$post->slug];
            })
            ->sortByDesc('score') // stable: equal scores keep newest first
            ->take($limit)
            ->map(fn (array $row): array => ['id' => $row['id'], 'title' => $row['title'], 'path' => $row['path']])
            ->values()
            ->all();
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}