<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\Api\TaxonomyPresenter;
use App\Support\Cache\ContentVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $data = ContentVersion::remember('categories', 300, fn (): array => $this->query()
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => TaxonomyPresenter::category($category))
            ->all());

        return response()->json(['data' => $data]);
    }

    public function show(string $slug): JsonResponse
    {
        $category = $this->query()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => TaxonomyPresenter::categoryDetail($category)]);
    }

    /** Counts live posts only. */
    private function query(): Builder
    {
        return Category::query()
            ->withCount(['posts as posts_count' => fn (Builder $posts) => $posts->published()]);
    }
}