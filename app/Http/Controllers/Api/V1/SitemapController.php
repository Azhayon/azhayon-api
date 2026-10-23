<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListSitemapPostsRequest;
use App\Http\Requests\Api\NoParametersRequest;
use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Support\Api\PostPresenter;
use App\Support\Api\TaxonomyPresenter;
use App\Support\Cache\ContentVersion;
use App\Support\Images\ImagePresenter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SitemapController extends Controller
{
    private const TTL = 600;

    public function summary(NoParametersRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->summaryData()]);
    }

    public function posts(ListSitemapPostsRequest $request): JsonResponse
    {
        $page = (int) ($request->validated('page') ?? 1);
        $summary = $this->summaryData();

        abort_if($page > $summary['posts']['pages'], 404);

        $perPage = $summary['posts']['per_page'];

        // Only pages that exist get a cache entry, so this cannot be flooded with made-up page numbers.
        $data = ContentVersion::remember("sitemap:posts:{$perPage}:{$page}", self::TTL, fn (): array => $this->indexablePosts()
            ->select(['id', 'slug', 'published_at', 'updated_at', 'featured_image'])
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (Post $post): array => [
                'path' => PostPresenter::path($post->slug),
                'lastmod' => $this->iso($post->updated_at, $post->published_at),
                'image' => ImagePresenter::url($post->featured_image),
            ])
            ->all());

        return response()->json([
            'data' => $data,
            'meta' => ['page' => $page, 'pages' => $summary['posts']['pages']],
        ]);
    }

    public function taxonomy(NoParametersRequest $request): JsonResponse
    {
        $data = ContentVersion::remember('sitemap:taxonomy', self::TTL, function (): array {
            $live = fn (Builder $posts) => $posts->published();

            $categories = Category::query()
                ->whereHas('posts', $live)
                ->withMax(['posts as lastmod' => $live], 'updated_at')
                ->orderBy('slug')
                ->get();

            // Same threshold the tag endpoint uses to mark thin tag pages noindex.
            $tags = Tag::query()
                ->whereHas('posts', $live, '>=', (int) config('azhayon.api.min_tag_posts'))
                ->withMax(['posts as lastmod' => $live], 'updated_at')
                ->orderBy('slug')
                ->get();

            $series = Series::query()
                ->whereHas('posts', $live)
                ->withMax(['posts as lastmod' => $live], 'updated_at')
                ->orderBy('slug')
                ->get();

            return [
                'categories' => $categories->map(fn (Category $category): array => [
                    'path' => TaxonomyPresenter::path('category', $category->slug),
                    'lastmod' => $this->iso($category->updated_at, $category->lastmod),
                ])->all(),
                'tags' => $tags->map(fn (Tag $tag): array => [
                    'path' => TaxonomyPresenter::path('tag', $tag->slug),
                    'lastmod' => $this->iso($tag->updated_at, $tag->lastmod),
                ])->all(),
                'series' => $series->map(fn (Series $item): array => [
                    'path' => TaxonomyPresenter::path('series', $item->slug),
                    'lastmod' => $this->iso($item->updated_at, $item->lastmod),
                    'image' => ImagePresenter::url($item->cover_image),
                ])->all(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    private function indexablePosts(): Builder
    {
        return Post::published()->where('noindex', false);
    }

    /** @return array{posts: array{total: int, per_page: int, pages: int}, last_modified: ?string} */
    private function summaryData(): array
    {
        $perPage = max(1, (int) config('azhayon.api.sitemap_per_page'));

        return ContentVersion::remember("sitemap:summary:{$perPage}", self::TTL, function () use ($perPage): array {
            $total = $this->indexablePosts()->count();

            return [
                'posts' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'pages' => max(1, (int) ceil($total / $perPage)),
                ],
                'last_modified' => $this->iso($this->indexablePosts()->max('updated_at')),
            ];
        });
    }

    /** The latest of the given dates (strings, Carbon or null) as ISO 8601 UTC, or null when there are none. */
    private function iso(mixed ...$dates): ?string
    {
        $latest = collect($dates)->filter()->map(fn ($date) => Carbon::parse($date))->max();

        return $latest?->copy()->utc()->toIso8601ZuluString();
    }
}