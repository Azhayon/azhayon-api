<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Outside production: throw on lazy loading (N+1), on filling non-fillable
        // attributes and on reading attributes that don't exist.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}