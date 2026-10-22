<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\Api\HomeFeed;
use App\Support\Api\PostPresenter;
use App\Support\Cache\ContentVersion;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function __invoke(HomeFeed $feed): JsonResponse
    {
        $sizes = config('azhayon.api.home');
        $key = "home:{$sizes['trending']}-{$sizes['latest']}-{$sizes['popular']}";

        // Content changes refresh this at once; view counts (which change constantly) wait for the 2-minute TTL.
        $data = ContentVersion::remember($key, 120, function () use ($feed, $sizes): array {
            $cards = fn (iterable $posts): array => collect($posts)
                ->map(fn (Post $post): array => PostPresenter::card($post))
                ->values()
                ->all();

            return [
                'trending' => $cards($feed->trending((int) $sizes['trending'])),
                'latest' => $cards($feed->latest((int) $sizes['latest'])),
                'popular' => $cards($feed->popular((int) $sizes['popular'])),
            ];
        });

        return response()->json(['data' => $data]);
    }
}