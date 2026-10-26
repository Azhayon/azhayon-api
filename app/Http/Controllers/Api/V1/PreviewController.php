<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PreviewRequest;
use App\Models\Post;
use App\Support\Api\PostDetailBuilder;
use App\Support\Api\PostPresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class PreviewController extends Controller
{
    /** Reached only through a valid, unexpired signed link (see the route). */
    public function __invoke(PreviewRequest $request, int $id, PostDetailBuilder $builder): JsonResponse
    {
        $post = Post::query()->find($id); // deleted posts are excluded; drafts and scheduled posts are not

        abort_if($post === null, 404);

        $data = $builder->build($post);

        $data['seo']['noindex'] = true;
        $data['published_at'] ??= PostPresenter::date(now()); // a draft has no publish date yet

        return response()
            ->json([
                'data' => $data,
                'meta' => ['preview' => [
                    'status' => $post->status->value,
                    'expires_at' => PostPresenter::date(Carbon::createFromTimestamp((int) $request->validated('expires'))),
                ]],
            ])
            ->header('Cache-Control', 'private, no-store');
    }
}