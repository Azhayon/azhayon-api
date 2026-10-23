<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NoParametersRequest;
use App\Models\Redirect;
use App\Support\Cache\ContentVersion;
use Illuminate\Http\JsonResponse;

class RedirectController extends Controller
{
    public function __invoke(NoParametersRequest $request): JsonResponse
    {
        $limit = max(1, (int) config('azhayon.api.redirects_limit'));

        $result = ContentVersion::remember("redirects:{$limit}", 300, fn (): array => [
            'total' => Redirect::query()->count(),
            'items' => Redirect::query()
                ->orderBy('id')
                ->limit($limit)
                ->get(['from_path', 'to_path', 'status_code'])
                ->map(fn (Redirect $redirect): array => [
                    'from' => $redirect->from_path,
                    'to' => $redirect->to_path,
                    'status' => (int) $redirect->status_code,
                ])
                ->all(),
        ]);

        return response()->json([
            'data' => $result['items'],
            'meta' => ['total' => $result['total'], 'truncated' => $result['total'] > $limit],
        ]);
    }
}