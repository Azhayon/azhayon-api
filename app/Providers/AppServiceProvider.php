<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Manager\SocialiteWasCalled;

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

        // Banned users are denied every ability, whatever their role.
        // Returning null lets the normal policy checks run for everyone else.
        Gate::before(function (?User $user) {
            return $user?->isBanned() ? false : null;
        });

        // Registers the community Discord driver with Socialite.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
        });
    }
}