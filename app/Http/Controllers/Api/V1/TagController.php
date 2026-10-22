<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListTagsRequest;
use App\Models\Tag;
use App\Support\Api\TaxonomyPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    public function index(ListTagsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? config('azhayon.api.taxonomy_per_page'));
        $page = (int) ($filters['page'] ?? 1);

        $query = $this->query()
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type));

        if (($filters['sort'] ?? 'name') === 'popular') {
            $query->orderByDesc('posts_count')->orderBy('name');
        } else {
            $query->orderBy('name');
        }

        $paginator = $query->paginate($perPage, page: $page);

        abort_if($page > $paginator->lastPage(), 404);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Tag $tag): array => TaxonomyPresenter::tag($tag))->all(),
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
        $tag = $this->query()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => TaxonomyPresenter::tagDetail($tag)]);
    }

    /** Only tags with at least one live post, counting live posts only. */
    private function query(): Builder
    {
        return Tag::query()
            ->whereHas('posts', fn (Builder $posts) => $posts->published())
            ->withCount(['posts as posts_count' => fn (Builder $posts) => $posts->published()]);
    }
}