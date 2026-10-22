<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListPostsRequest;
use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Support\Api\PostPresenter;
use App\Support\Cache\ContentVersion;
use App\Support\Links\LinkSuggester;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class PostController extends Controller
{
    private const RELATED_LIMIT = 6;

    public function index(ListPostsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? config('azhayon.api.per_page'));
        $page = (int) ($filters['page'] ?? 1);

        $query = PostPresenter::cardQuery()
            ->when($filters['category'] ?? null, fn (Builder $q, string $slug) => $q->whereIn(
                'category_id', Category::query()->where('slug', $slug)->select('id')
            ))
            ->when($filters['tag'] ?? null, fn (Builder $q, string $slug) => $q->whereHas(
                'tags', fn (Builder $tags) => $tags->where('tags.slug', $slug)
            ))
            ->when($filters['series'] ?? null, fn (Builder $q, string $slug) => $q->whereIn(
                'series_id', Series::query()->where('slug', $slug)->select('id')
            ))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('post_type', $type));

        $this->applySort($query, $filters['sort'] ?? 'newest');

        $paginator = $query->paginate($perPage, page: $page);

        // A page past the end is a 404, so search engines never index empty duplicates.
        abort_if($page > $paginator->lastPage(), 404);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Post $post): array => PostPresenter::card($post))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        // Cheap indexed lookup first: only real, live posts ever get a cache entry.
        $id = Post::published()->where('slug', $slug)->value('id');

        abort_if($id === null, 404);

        $data = ContentVersion::remember("post:{$id}", 300, fn (): array => $this->detail((int) $id));

        return response()->json(['data' => $data]);
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'popular' => $query->orderByDesc('view_count')->orderByDesc('published_at')->orderByDesc('id'),
            'rating' => $query
                ->where('rating_count', '>=', (int) config('azhayon.api.min_ratings'))
                ->orderByDesc('rating_avg')->orderByDesc('rating_count')->orderByDesc('published_at')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /** @return array<string, mixed> */
    private function detail(int $id): array
    {
        $post = Post::published()
            ->whereKey($id)
            ->with(['category:id,name,slug', 'author:id,name', 'tags:id,name,slug,type', 'series:id,title,slug'])
            ->firstOrFail();

        return PostPresenter::detail($post, $this->related($post), $this->seriesNavigation($post));
    }

    /** @return list<array<string, mixed>> */
    private function related(Post $post): array
    {
        $ids = array_column(app(LinkSuggester::class)->suggest([
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

        $link = fn (?Post $other): ?array => $other === null ? null : [
            'title' => $other->title,
            'slug' => $other->slug,
            'path' => PostPresenter::path($other->slug),
        ];

        $inSeries = fn () => Post::published()->where('series_id', $post->series_id);

        $previous = $inSeries()
            ->where(fn (Builder $q) => $q->where('published_at', '<', $post->published_at)
                ->orWhere(fn (Builder $same) => $same->where('published_at', $post->published_at)->where('id', '<', $post->id)))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->first(['id', 'title', 'slug']);

        $next = $inSeries()
            ->where(fn (Builder $q) => $q->where('published_at', '>', $post->published_at)
                ->orWhere(fn (Builder $same) => $same->where('published_at', $post->published_at)->where('id', '>', $post->id)))
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