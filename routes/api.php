<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        try {
            DB::connection()->getPdo();
            $database = 'ok';
        } catch (\Throwable $e) {
            $database = 'error';
        }

        return response()->json([
            'status'   => 'ok',
            'app'      => config('app.name'),
            'php'      => PHP_VERSION,
            'laravel'  => app()->version(),
            'database' => $database,
        ]);
    });
});