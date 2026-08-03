<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\TrackingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);

        /*
        |--------------------------------------------------------------------------
        | Device
        |--------------------------------------------------------------------------
        */

        Route::prefix('device')
            ->controller(DeviceController::class)
            ->group(function () {

                Route::get('/', 'show');

                Route::post('/register', 'register');

                Route::put('/', 'update');

            });

        /*
        |--------------------------------------------------------------------------
        | Tracking
        |--------------------------------------------------------------------------
        */

        Route::prefix('tracking')
            ->controller(TrackingController::class)
            ->group(function () {

                Route::get('/status', 'status');

                Route::get('/sessions', 'sessions');

                Route::get('/{session}', 'show');

                Route::get('/{session}/points', 'points');

                Route::get('/{session}/route', 'route');

                Route::post('/start', 'start');

                Route::post('/location', 'location');

                Route::post('/stop', 'stop');

                Route::delete('/{session}', 'destroy');

            });

    });

});
