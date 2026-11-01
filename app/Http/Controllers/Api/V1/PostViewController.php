<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\Http\BotDetector;
use App\Support\Http\ClientIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Counts one view of a live post. Always answers 204, whether or not the view was counted,
 * so nobody can tell a draft from a missing post or probe how counting works.
 */
class PostViewController extends Controller
{
    public function __invoke(Request $request, string $slug): Response
    {
        $done = response()->noContent()->header('Cache-Control', 'no-store');

        if (BotDetector::isBot($request->userAgent())) {
            return $done;
        }

        $postId = Post::query()->published()->where('slug', $slug)->value('id');

        if ($postId === null) {
            return $done;
        }

        // The visitor is never stored, only a salted hash of address and browser.
        // add() writes the key only when it is new, in one atomic step: false means already counted.
        $visitor = hash_hmac('sha256', ClientIdentity::ip($request).'|'.$request->userAgent(), (string) config('app.key'));

        if (! Cache::add("view:{$postId}:{$visitor}", 1, now()->addDay())) {
            return $done;
        }

        DB::transaction(function () use ($postId): void {
            DB::table('post_views_daily')->upsert(
                [['post_id' => $postId, 'date' => now()->toDateString(), 'views' => 1]],
                ['post_id', 'date'],
                ['views' => DB::raw('views + 1')],
            );

            // Query builder on purpose: a model increment could change updated_at.
            DB::table('posts')->where('id', $postId)->increment('view_count');
        });

        return $done;
    }
}