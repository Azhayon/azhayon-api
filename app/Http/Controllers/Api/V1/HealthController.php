<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $database = 'ok';
        } catch (Throwable) {
            $database = 'error';
        }

        return response()->json(
            ['status' => $database === 'ok' ? 'ok' : 'degraded', 'database' => $database],
            $database === 'ok' ? 200 : 503,
        );
    }
}