<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\LocationController;
use App\Http\Controllers\TransportPoiController;
use App\Http\Controllers\TrackingController;


Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::name('app.')->middleware(['auth'])->group(function () {
    Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
    Route::post('/location/update', [LocationController::class, 'update']);
    Route::get('/api/transport-nearby', [TransportPoiController::class, 'nearby']);

    Route::post('/location/toggle', [LocationController::class, 'toggle'])->name('user.location.toggle');
    Route::post('/tracking/toggle', [TrackingController::class, 'toggle'])->name('user.tracking.toggle');

    Route::get('/tracking/index', [TrackingController::class, 'index'])->name('trackings.index');
    Route::post('/tracking/start', [TrackingController::class, 'start'])->name('tracking.start');
    Route::post('/tracking/point', [TrackingController::class, 'point'])->name('tracking.point');
    Route::post('/tracking/stop', [TrackingController::class, 'stop'])->name('tracking.stop');

    Route::get('/map/tiles/{z}/{x}/{y}', [TrackingController::class, 'tile']);
    Route::get('/tracks/{session}', [TrackingController::class, 'show'])->name('tracking.sessions');
    Route::get('/tracking/sessions', [TrackingController::class, 'sessions'])->name('tracking.sessions');
    Route::get('/tracking/{session}/points', [TrackingController::class, 'points'])->name('tracking.points');
    Route::get('/tracking/{session}/route', [TrackingController::class, 'route'])->name('tracking.route');
});
