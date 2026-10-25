<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Site-wide page limit. The API and downloads have their own limits, and the owner console loads dozens of
        // panels at once, so counting those here left console pages stuck on "Loading…" after a minute of use.
        RateLimiter::for('pages', function (Request $r) {
            if ($r->is('api/*', 'export/*') || $r->user()?->is_admin) {
                return Limit::none();
            }

            return Limit::perMinute(240)->by($r->user()?->id ?: $r->ip());
        });
    }
}
