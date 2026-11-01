<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NoParametersRequest;
use App\Http\Requests\Api\SetRatingRequest;
use App\Models\Post;
use App\Models\Rating;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** A signed-in user's own rating of a live post. Counters on the post are recomputed after every change. */
class RatingController extends Controller
{
    public function show(NoParametersRequest $request, string $slug): JsonResponse
    {
        return $this->respond($this->livePostId($slug), $request->user()->id);
    }

    public function store(SetRatingRequest $request, string $slug): JsonResponse
    {
        Gate::authorize('create', Rating::class);

        $postId = $this->livePostId($slug);
        $userId = $request->user()->id;
        $score = (int) $request->validated()['score'];

        DB::transaction(function () use ($postId, $userId, $score): void {
            $this->lockPost($postId);

            DB::table('ratings')->upsert(
                [['post_id' => $postId, 'user_id' => $userId, 'score' => $score, 'created_at' => now(), 'updated_at' => now()]],
                ['post_id', 'user_id'],
                ['score', 'updated_at'],
            );

            $this->refreshCounters($postId);
        });

        return $this->respond($postId, $userId);
    }

    public function destroy(NoParametersRequest $request, string $slug): JsonResponse
    {
        $postId = $this->livePostId($slug);
        $userId = $request->user()->id;

        $rating = Rating::query()->where('post_id', $postId)->where('user_id', $userId)->first();

        if ($rating !== null) {
            Gate::authorize('delete', $rating);

            DB::transaction(function () use ($rating, $postId): void {
                $this->lockPost($postId);

                DB::table('ratings')->where('id', $rating->id)->delete();

                $this->refreshCounters($postId);
            });
        }

        return $this->respond($postId, $userId);
    }

    private function livePostId(string $slug): int
    {
        $postId = Post::query()->published()->where('slug', $slug)->value('id');

        abort_if($postId === null, 404);

        return (int) $postId;
    }

    /** Taken first, so two ratings at once wait for each other and the totals never overwrite each other. */
    private function lockPost(int $postId): void
    {
        DB::table('posts')->where('id', $postId)->lockForUpdate()->value('id');
    }

    /** Query builder on purpose: no model events, and updated_at stays as it is. */
    private function refreshCounters(int $postId): void
    {
        $totals = DB::table('ratings')
            ->where('post_id', $postId)
            ->selectRaw('COUNT(*) AS total, AVG(score) AS average')
            ->first();

        DB::table('posts')->where('id', $postId)->update([
            'rating_count' => (int) $totals->total,
            'rating_avg' => round((float) $totals->average, 2),
        ]);
    }

    private function respond(int $postId, int $userId): JsonResponse
    {
        $score = DB::table('ratings')->where('post_id', $postId)->where('user_id', $userId)->value('score');
        $post = DB::table('posts')->where('id', $postId)->first(['rating_avg', 'rating_count']);

        return response()
            ->json(['data' => [
                'score' => $score === null ? null : (int) $score,
                'average' => (float) $post->rating_avg,
                'count' => (int) $post->rating_count,
            ]])
            ->header('Cache-Control', 'no-store');
    }
}