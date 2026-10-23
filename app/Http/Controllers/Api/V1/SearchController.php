<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SearchRequest;
use App\Http\Requests\Api\SuggestRequest;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Support\Api\PostPresenter;
use App\Support\Api\SearchQuery;
use App\Support\Api\TaxonomyPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    private const SUGGESTIONS = 8;

    private const CANDIDATES = 10;

    /** When scores tie, entities come before posts. */
    private const TYPE_RANK = ['series' => 0, 'tag' => 1, 'subject' => 2, 'post' => 3];

    public function index(SearchRequest $request): JsonResponse
    {
        $search = SearchQuery::parse((string) $request->validated('q'));

        abort_if($search === null, 422);

        $perPage = (int) ($request->validated('per_page') ?? config('azhayon.api.per_page'));
        $page = (int) ($request->validated('page') ?? 1);

        $prefix = $search->like.'%';
        $contains = '%'.$search->like.'%';
        $boolean = $search->boolean();
        $natural = $search->natural();

        // Exact and prefix title/subject matches rank first; the full-text score only breaks ties inside a tier.
        $relevance = '(CASE WHEN posts.title = ? THEN 100 ELSE 0 END)'
            .' + (CASE WHEN posts.title LIKE ? THEN 50 ELSE 0 END)'
            .' + (CASE WHEN posts.title LIKE ? THEN 20 ELSE 0 END)'
            .' + (CASE WHEN posts.subject_title = ? THEN 60 ELSE 0 END)'
            .' + (CASE WHEN posts.subject_title LIKE ? THEN 30 ELSE 0 END)';
        $bindings = [$search->text, $prefix, $contains, $search->text, $prefix];

        if ($natural !== null) {
            $relevance .= ' + MATCH(posts.title, posts.subtitle, posts.excerpt, posts.subject_title) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $bindings[] = $natural;
        }

        $paginator = PostPresenter::cardQuery()
            ->selectRaw("({$relevance}) AS relevance", $bindings)
            ->where(function (Builder $match) use ($contains, $prefix, $boolean): void {
                $match->where('posts.title', 'like', $contains)
                    ->orWhere('posts.subject_title', 'like', $contains);

                if ($boolean !== null) {
                    $match->orWhereRaw(
                        'MATCH(posts.title, posts.subtitle, posts.excerpt, posts.subject_title) AGAINST (? IN BOOLEAN MODE)',
                        [$boolean],
                    );
                }

                $match->orWhereHas('tags', fn (Builder $tags) => $tags->where('tags.name', 'like', $prefix))
                    ->orWhereHas('series', fn (Builder $series) => $series->where('series.title', 'like', $prefix));
            })
            ->orderByDesc('relevance')
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id')
            ->paginate($perPage, page: $page);

        abort_if($page > $paginator->lastPage(), 404);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Post $post): array => PostPresenter::card($post))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'query' => $search->text,
            ],
        ]);
    }

    public function suggest(SuggestRequest $request): JsonResponse
    {
        $search = SearchQuery::parse((string) $request->validated('q'));

        abort_if($search === null, 422);

        $prefix = $search->like.'%';
        $contains = '%'.$search->like.'%';
        $needle = mb_strtolower($search->text);

        $series = Series::query()
            ->whereHas('posts', fn (Builder $posts) => $posts->published())
            ->where('title', 'like', $contains)
            ->orderByRaw('(title LIKE ?) DESC', [$prefix])
            ->orderBy('title')
            ->limit(self::CANDIDATES)
            ->get(['id', 'title', 'slug']);

        $tags = Tag::query()
            ->whereHas('posts', fn (Builder $posts) => $posts->published())
            ->where('name', 'like', $contains)
            ->orderByRaw('(name LIKE ?) DESC', [$prefix])
            ->orderBy('name')
            ->limit(self::CANDIDATES)
            ->get(['id', 'name', 'slug', 'type']);

        // Posts hidden from search engines are not promoted here either.
        $subjects = Post::published()
            ->where('noindex', false)
            ->whereNotNull('subject_title')
            ->where('subject_title', 'like', $contains)
            ->select('subject_title')
            ->groupBy('subject_title')
            ->orderByRaw('(subject_title LIKE ?) DESC', [$prefix])
            ->orderBy('subject_title')
            ->limit(self::CANDIDATES)
            ->pluck('subject_title');

        $posts = Post::published()
            ->where('noindex', false)
            ->where('title', 'like', $contains)
            ->orderByRaw('(title LIKE ?) DESC', [$prefix])
            ->orderByDesc('view_count')
            ->limit(self::CANDIDATES)
            ->get(['id', 'title', 'slug']);

        $items = [];

        foreach ($series as $item) {
            $items[] = $this->item('series', $item->title, TaxonomyPresenter::path('series', $item->slug), null, $needle);
        }

        foreach ($tags as $item) {
            $items[] = $this->item('tag', $item->name, TaxonomyPresenter::path('tag', $item->slug), $item->type->value, $needle);
        }

        foreach ($subjects as $subject) {
            $items[] = $this->item('subject', $subject, '/search?q='.rawurlencode($subject), null, $needle);
        }

        foreach ($posts as $item) {
            $items[] = $this->item('post', $item->title, PostPresenter::path($item->slug), null, $needle);
        }

        usort($items, fn (array $a, array $b): int => [$b['score'], $a['rank'], $a['label']] <=> [$a['score'], $b['rank'], $b['label']]);

        $data = array_map(
            fn (array $item): array => ['type' => $item['type'], 'label' => $item['label'], 'path' => $item['path'], 'tag_type' => $item['tag_type']],
            array_slice($items, 0, self::SUGGESTIONS),
        );

        return response()->json(['data' => $data]);
    }

    /** @return array<string, mixed> */
    private function item(string $type, string $label, string $path, ?string $tagType, string $needle): array
    {
        $lower = mb_strtolower($label);

        return [
            'type' => $type,
            'label' => $label,
            'path' => $path,
            'tag_type' => $tagType,
            'score' => $lower === $needle ? 3 : (str_starts_with($lower, $needle) ? 2 : 1),
            'rank' => self::TYPE_RANK[$type],
        ];
    }
}