<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListSeriesRequest;
use App\Http\Requests\Api\NoParametersRequest;
use App\Models\Series;
use App\Support\Api\TaxonomyPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SeriesController extends Controller
{
    public function index(ListSeriesRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? config('azhayon.api.taxonomy_per_page'));
        $page = (int) ($filters['page'] ?? 1);

        $query = $this->query();

        if (($filters['sort'] ?? 'latest') === 'title') {
            $query->orderBy('title');
        } else {
            $query->orderByDesc('latest_post_at')->orderBy('title');
        }

        $paginator = $query->paginate($perPage, page: $page);

        abort_if($page > $paginator->lastPage(), 404);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Series $series): array => TaxonomyPresenter::series($series))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(NoParametersRequest $request, string $slug): JsonResponse
    {
        $series = $this->query()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => TaxonomyPresenter::seriesDetail($series)]);
    }

    /** Only series with at least one live post; counts and the latest date use live posts only. */
    private function query(): Builder
    {
        return Series::query()
            ->whereHas('posts', fn (Builder $posts) => $posts->published())
            ->withCount(['posts as posts_count' => fn (Builder $posts) => $posts->published()])
            ->withMax(['posts as latest_post_at' => fn (Builder $posts) => $posts->published()], 'published_at');
    }
}