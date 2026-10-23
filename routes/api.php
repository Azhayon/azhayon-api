<?php

use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Middleware\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\SeriesController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\RedirectController;
use App\Http\Controllers\Api\V1\SitemapController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::get('/health', HealthController::class);
    
    Route::get('/site', SiteController::class)
        ->middleware(PublicCache::class.':30,60,120');

    Route::get('/home', HomeController::class)->middleware(PublicCache::class);

    Route::get('/posts', [PostController::class, 'index'])
        ->middleware(PublicCache::class);

    Route::get('/categories', [CategoryController::class, 'index'])->middleware(PublicCache::class);
    Route::get('/categories/{slug}', [CategoryController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')->middleware(PublicCache::class);

    Route::get('/tags', [TagController::class, 'index'])->middleware(PublicCache::class);
    Route::get('/tags/{slug}', [TagController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')->middleware(PublicCache::class);

    Route::get('/series', [SeriesController::class, 'index'])->middleware(PublicCache::class);
    Route::get('/series/{slug}', [SeriesController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')->middleware(PublicCache::class);

    Route::get('/posts/{slug}', [PostController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->middleware(PublicCache::class);

    Route::get('/search', [SearchController::class, 'index'])
        ->middleware(['throttle:search', PublicCache::class]);

    Route::get('/search/suggest', [SearchController::class, 'suggest'])
        ->middleware(['throttle:search-suggest', PublicCache::class]);

    Route::get('/sitemap', [SitemapController::class, 'summary'])->middleware(PublicCache::class);
    Route::get('/sitemap/posts', [SitemapController::class, 'posts'])->middleware(PublicCache::class);
    Route::get('/sitemap/taxonomy', [SitemapController::class, 'taxonomy'])->middleware(PublicCache::class);

    Route::get('/redirects', RedirectController::class)->middleware(PublicCache::class.':30,60,120');
    
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth-browser')->group(function () {
            Route::get('/{provider}/redirect', [SocialAuthController::class, 'redirect'])
                ->whereIn('provider', ['google', 'discord']);

            Route::get('/{provider}/callback', [SocialAuthController::class, 'callback'])
                ->whereIn('provider', ['google', 'discord']);
        });

        Route::post('/exchange', [SessionController::class, 'exchange'])
            ->middleware('throttle:auth-exchange');
    });

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::get('/me', [SessionController::class, 'me']);
        Route::post('/auth/logout', [SessionController::class, 'logout']);
    });
});