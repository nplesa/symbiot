<?php

namespace App\Providers;

use App\Contracts\TrafficProvider;
use App\Services\TomTomTrafficProvider;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
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
        // A forgotten-password reset must also cut off API tokens (e.g. a stolen phone).
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if (method_exists($event->user, 'tokens')) {
                $event->user->tokens()->delete();
            }
        });

        RateLimiter::for('login', function (Request $request) {
            // Runs before validation, so the input may be missing or an array (email[]=x).
            $email = $request->input('email');

            return [
                Limit::perMinute(5)->by(
                    (is_string($email) ? mb_strtolower(trim($email)) : '') . '|' . $request->ip()
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

        // A full-screen map loads ~30-60 tiles and browsers cache them for 7 days,
        // so this only stops scripted crawling of the paid Geoapify tile API.
        RateLimiter::for('map-tiles', function (Request $request) {
            return Limit::perMinute(300)->by((string) $request->user()?->id ?: $request->ip());
        });

        // Selecting every POI category sends ~20 requests per map load; each one
        // may fan out to several paid or rate-limited upstream APIs.
        RateLimiter::for('poi-search', function (Request $request) {
            return Limit::perMinute(120)->by((string) $request->user()?->id ?: $request->ip());
        });

        // Each planned route can insert up to 10k tracking rows.
        RateLimiter::for('planned-routes', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->user()?->id ?: $request->ip());
        });

        // Shared by the Google Maps link resolver and KML import (each may follow goo.gl redirects).
        RateLimiter::for('google-maps', function (Request $request) {
            return Limit::perMinute(20)->by((string) $request->user()?->id ?: $request->ip());
        });
    }
}
