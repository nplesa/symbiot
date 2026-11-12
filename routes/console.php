<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// withoutOverlapping() without an argument keeps its lock for 24 h if a run dies,
// so every task sets an expiry matching how long it may reasonably run.

// Needs the osmium binary on the server (see scripts/setup-osm-linux.sh).
Schedule::command('osm:sync-romania')->dailyAt('02:30')->withoutOverlapping(180)->runInBackground();
Schedule::command('transit:sync-osm-counties')->weeklyOn(1, '03:00')->withoutOverlapping(60);
Schedule::command('transit:sync-catalog')->dailyAt('03:30')->withoutOverlapping(60);
Schedule::command('map:prune-tiles')->dailyAt('04:15')->withoutOverlapping(60);
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('04:30');
Schedule::command('queue:prune-failed --hours=168')->dailyAt('04:45');
Schedule::command('transit:poll-realtime')->everyFifteenSeconds()->withoutOverlapping(2)->runInBackground();
