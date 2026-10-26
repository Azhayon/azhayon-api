<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Http\ClientIdentity;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by(ClientIdentity::ip($request));
        });

        // Browser-facing OAuth routes: on failure, send the visitor back to the site.
        RateLimiter::for('auth-browser', function (Request $request) {
            return Limit::perMinute(30)
                ->by(ClientIdentity::ip($request))
                ->response(function () {
                    $base = rtrim((string) config('azhayon.frontend_url'), '/');

                    return redirect()->away($base.'/auth/callback?error=rate_limited');
                });
        });

        // Server-to-server code exchange, called by Next.js.
        RateLimiter::for('auth-exchange', function (Request $request) {
            return Limit::perMinute(60)->by(ClientIdentity::ip($request));
        });

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)->by(ClientIdentity::ip($request)));

        RateLimiter::for('search-suggest', fn (Request $request) => Limit::perMinute(60)->by(ClientIdentity::ip($request)));

        RateLimiter::for('preview', fn (Request $request) => Limit::perMinute(30)->by(ClientIdentity::ip($request)));
    }
}