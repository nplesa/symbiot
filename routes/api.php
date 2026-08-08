<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\PlannedRouteController;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/login',
        [AuthController::class, 'login']
    )
    ->middleware('throttle:login');


    Route::middleware('auth:sanctum')
        ->group(function () {


            /*
            |--------------------------------------------------------------------------
            | Logout
            |--------------------------------------------------------------------------
            */

            Route::post(
                '/logout',
                [AuthController::class, 'logout']
            );


            /*
            |--------------------------------------------------------------------------
            | Device
            |--------------------------------------------------------------------------
            */

            Route::prefix('device')
                ->controller(DeviceController::class)
                ->group(function () {

                    Route::get(
                        '/',
                        'show'
                    );

                    Route::post(
                        '/register',
                        'register'
                    )->middleware('throttle:device-register');

                    Route::put(
                        '/',
                        'update'
                    );

                });


            /*
            |--------------------------------------------------------------------------
            | Tracking
            |--------------------------------------------------------------------------
            */

            Route::prefix('tracking')
                ->group(function () {


                    /*
                    |--------------------------------------------------------------------------
                    | GPS Tracking
                    |--------------------------------------------------------------------------
                    */

                    Route::controller(TrackingController::class)
                        ->group(function () {

                            Route::get(
                                '/status',
                                'status'
                            );

                            Route::get(
                                '/sessions',
                                'sessions'
                            );

                            Route::post(
                                '/start',
                                'start'
                            )->middleware('throttle:tracking-control');

                            Route::post(
                                '/location',
                                'location'
                            )->middleware('throttle:tracking-location');

                            Route::post(
                                '/stop',
                                'stop'
                            )->middleware('throttle:tracking-control');


                            /*
                            |--------------------------------------------------------------------------
                            | Replay / Route
                            |--------------------------------------------------------------------------
                            */

                            Route::get(
                                '/{session}',
                                'show'
                            );

                            Route::get(
                                '/{session}/points',
                                'points'
                            );

                            Route::get(
                                '/{session}/route',
                                'route'
                            );


                            Route::delete(
                                '/{session}',
                                'destroy'
                            );

                        });


                    /*
                    |--------------------------------------------------------------------------
                    | Planned Routes
                    |--------------------------------------------------------------------------
                    */

                    Route::post(
                        '/planned',
                        [PlannedRouteController::class, 'store']
                    );

                });

        });

});