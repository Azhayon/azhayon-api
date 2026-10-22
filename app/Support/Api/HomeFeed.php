<?php

namespace App\Support\Api;

use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The post lists behind the home page. All of them return live posts only. */
final class HomeFeed
{
    /** @return Collection<int, Post> */
    public function latest(int $limit): Collection
    {
        return PostPresenter::cardQuery()
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Post> */
    public function popular(int $limit): Collection
    {
        return PostPresenter::cardQuery()
            ->orderByDesc('view_count')->orderByDesc('published_at')->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Views are stored per day, so "the last 48 hours" is today plus yesterday
     * (24 to 48 hours of data). When too few posts have recent views, the list
     * is topped up with the most-viewed posts overall.
     *
     * @return Collection<int, Post>
     */
    public function trending(int $limit): Collection
    {
        $views = DB::table('post_views_daily')
            ->select('post_id', DB::raw('SUM(views) AS recent_views'))
            ->where('date', '>=', now()->subDay()->toDateString())
            ->groupBy('post_id');

        $trending = PostPresenter::cardQuery()
            ->joinSub($views, 'recent', 'recent.post_id', '=', 'posts.id')
            ->orderByDesc('recent.recent_views')->orderByDesc('posts.published_at')->orderByDesc('posts.id')
            ->limit($limit)
            ->get();

        if ($trending->count() >= $limit) {
            return $trending;
        }

        $topUp = $this->popular($limit)
            ->reject(fn (Post $post): bool => $trending->contains('id', $post->id))
            ->take($limit - $trending->count());

        return $trending->concat($topUp)->values();
    }
}