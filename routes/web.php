<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\LocationController;
use App\Http\Controllers\TransportPoiController;


Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::name('app.')->middleware(['auth'])->group(function () {
    Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

    Route::post('/location/update', [LocationController::class, 'update']);

    Route::get('/api/transport-nearby', [TransportPoiController::class, 'nearby']);

});
