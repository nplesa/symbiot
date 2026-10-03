<?php

namespace App\Providers;

use App\Contracts\TrafficProvider;
use App\Services\TomTomTrafficProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->bind(TrafficProvider::class, TomTomTrafficProvider::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(
                    strtolower($request->input('email')) . '|' . $request->ip()
                ),
            ];
        });

        RateLimiter::for('device-register', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('tracking-control', function (Request $request) {
            return Limit::perMinute(20)->by((string) $request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('tracking-location', function (Request $request) {
            return Limit::perMinute(180)->by((string) $request->user()?->id ?: $request->ip());
        });
    }
}
