<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('transit:sync-catalog')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('transit:poll-realtime')->everyFifteenSeconds()->withoutOverlapping()->runInBackground();
