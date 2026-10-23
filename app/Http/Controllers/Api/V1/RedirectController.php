<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NoParametersRequest;
use App\Models\Redirect;
use App\Models\Post;
use App\Support\Cache\ContentVersion;
use Illuminate\Http\JsonResponse;

class RedirectController extends Controller
{
    public function __invoke(NoParametersRequest $request): JsonResponse
    {
        $limit = max(1, (int) config('azhayon.api.redirects_limit'));
        $prefix = rtrim((string) config('azhayon.paths.post'), '/').'/';

        $result = ContentVersion::remember("redirects:{$limit}", 300, function () use ($limit, $prefix): array {
            // A live post always wins: a redirect that starts at a live post's address would hide that post.
            $usable = fn () => Redirect::query()->whereNotIn(
                'from_path',
                Post::published()->selectRaw('CONCAT(?, slug)', [$prefix]),
            );

            return [
                'total' => $usable()->count(),
                'items' => $usable()
                    ->orderBy('id')
                    ->limit($limit)
                    ->get(['from_path', 'to_path', 'status_code'])
                    ->map(fn (Redirect $redirect): array => [
                        'from' => $redirect->from_path,
                        'to' => $redirect->to_path,
                        'status' => (int) $redirect->status_code,
                    ])
                    ->all(),
            ];
        });

        return response()->json([
            'data' => $result['items'],
            'meta' => ['total' => $result['total'], 'truncated' => $result['total'] > $limit],
        ]);
    }
}