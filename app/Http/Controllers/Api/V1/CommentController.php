<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListCommentsRequest;
use App\Models\Comment;
use App\Models\Post;
use App\Support\Api\CommentPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Api\StoreCommentRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    public function index(ListCommentsRequest $request, string $slug): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? config('azhayon.api.comments_per_page'));
        $page = (int) ($filters['page'] ?? 1);

        $postId = Post::query()->published()->where('slug', $slug)->value('id');

        abort_if($postId === null, 404);

        $paginator = Comment::query()
            ->approved()
            ->where('post_id', $postId)
            ->whereNull('parent_id')
            ->with([
                'user',
                'replies' => fn ($replies) => $replies->approved()->with('user')->orderBy('created_at')->orderBy('id'),
            ])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, page: $page);

        abort_if($page > $paginator->lastPage(), 404);

        // Top-level comments plus replies that are shown, so a reply under a hidden comment is never counted.
        $total = Comment::query()
            ->approved()
            ->where('post_id', $postId)
            ->where(fn (Builder $q) => $q
                ->whereNull('parent_id')
                ->orWhereHas('parent', fn (Builder $parent) => $parent->approved()->whereNull('parent_id')))
            ->count();

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Comment $comment): array => CommentPresenter::comment($comment))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'comments_total' => $total,
            ],
        ]);
    }

    public function store(StoreCommentRequest $request, string $slug): JsonResponse
    {
        Gate::authorize('create', Comment::class);

        $postId = Post::query()->published()->where('slug', $slug)->value('id');

        abort_if($postId === null, 404);

        $data = $request->validated();
        $userId = $request->user()->id;
        $parentId = $data['parent_id'] ?? null;

        if ($parentId !== null) {
            $visibleParent = Comment::query()->approved()
                ->where('post_id', $postId)->whereNull('parent_id')->whereKey($parentId)->exists();

            if (! $visibleParent) {
                throw ValidationException::withMessages(['parent_id' => 'You can only reply to a visible comment.']);
            }
        }

        $repeat = Comment::query()->withTrashed()
            ->where('user_id', $userId)->where('post_id', $postId)->where('body', $data['body'])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($repeat) {
            throw ValidationException::withMessages(['body' => 'You already posted this comment.']);
        }

        // status is not fillable: it starts as pending and only staff can approve.
        $comment = Comment::create([
            'post_id' => $postId,
            'user_id' => $userId,
            'parent_id' => $parentId,
            'body' => $data['body'],
            'is_spoiler' => (bool) ($data['is_spoiler'] ?? false),
        ]);

        return response()
            ->json(['data' => CommentPresenter::created($comment)], 201)
            ->header('Cache-Control', 'no-store');
    }
}