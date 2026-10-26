<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListPostsRequest;
use App\Http\Requests\Api\NoParametersRequest;
use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Support\Api\PostPresenter;
use App\Support\Cache\ContentVersion;
use App\Support\Links\LinkSuggester;
use App\Support\Api\PostDetailBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class PostController extends Controller
{

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

    public function show(NoParametersRequest $request, string $slug, PostDetailBuilder $builder): JsonResponse
    {
        // Cheap indexed lookup first: only real, live posts ever get a cache entry.
        $id = Post::published()->where('slug', $slug)->value('id');

        abort_if($id === null, 404);

        $data = ContentVersion::remember(
            "post:{$id}",
            300,
            fn (): array => $builder->build(Post::published()->whereKey($id)->firstOrFail()),
        );

        return response()->json(['data' => $data]);
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'popular' => $query->orderByDesc('view_count')->orderByDesc('published_at')->orderByDesc('id'),
            'rating' => $query
                ->where('rating_count', '>=', (int) config('azhayon.api.min_ratings'))
                ->orderByDesc('rating_avg')->orderByDesc('rating_count')->orderByDesc('published_at')->orderByDesc('id'),
                'oldest' => $query->orderBy('published_at')->orderBy('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }
}