<?php

use App\Http\Controllers\AirportFlightsController;
use App\Http\Controllers\Api\Transit\CoverageController;
use App\Http\Controllers\Api\Transit\TransitMapController;
use App\Http\Controllers\Api\Transit\VehicleController;
use App\Http\Controllers\CityLocationController;
use App\Http\Controllers\CityBoundaryController;
use App\Http\Controllers\FuelBestPriceController;
use App\Http\Controllers\FuelPriceController;
use App\Http\Controllers\GhostController;
use App\Http\Controllers\GoogleMapsController;
use App\Http\Controllers\GoogleMapsKmlController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\NavigationController;
use App\Http\Controllers\NavigationPoiController;
use App\Http\Controllers\PoiSubcategoryController;
use App\Http\Controllers\RouteKmlController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\TrafficTileController;
use App\Http\Controllers\TransportPoiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReverseGeocodeController;
use App\Http\Controllers\UserProfileController;

Route::get('/', fn () => view('welcome'));

Auth::routes();

Route::get('lang/{locale}', function ($locale) {
    if (in_array($locale, ['en', 'ro', 'it'])) {
        session(['locale' => $locale]);
        app()->setLocale($locale);
    }
    return redirect()->back();
})->name('lang.switch');

Route::view('/pending-approval', 'auth.pending')->middleware('auth')->name('pending');

Route::middleware(['auth', 'approved'])->group(function () {
    Route::post('/ghost/leave', [GhostController::class, 'leave'])->name('ghost.leave');
});

Route::name('app.')->middleware(['auth', 'approved'])->group(function () {
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', fn () => view('admin.users'))->name('users');
        Route::post('/users/{user}/ghost', [GhostController::class, 'enter'])->name('users.ghost');
    });
    Route::get('/home', [HomeController::class, 'index'])->name('home');
    Route::post('/location/update', [LocationController::class, 'update'])->name('location.update');
    Route::get('/api/location/city', CityLocationController::class)->middleware('throttle:30,1')->name('api.location.city');
    Route::get('/api/location/city-boundary', CityBoundaryController::class)->middleware('throttle:10,1')->name('api.location.city-boundary');
    Route::get('/api/transport/airport/{airport}/flights', AirportFlightsController::class)
        ->where('airport', '[A-Za-z0-9]{3,4}')
        ->middleware('throttle:30,1')->name('api.transport.airport.flights');
    Route::get('/api/poi/subcategories', PoiSubcategoryController::class)->middleware('throttle:60,1')->name('api.poi.subcategories');
    Route::get('/api/fuel/best', FuelBestPriceController::class)->middleware('throttle:30,1')->name('api.fuel.best');
    Route::get('/api/fuel/prices', FuelPriceController::class)->middleware('throttle:60,1')->name('api.fuel.prices');

    // Each request can fan out to several Geoapify/Overpass/OSM calls.
    Route::middleware('throttle:poi-search')->group(function (): void {
        // One URL and route name per mode/category (route('app.api.poi.fuel') etc.); the name
        // is passed to the controller as a route default.
        foreach (['bus', 'train', 'subway', 'airport'] as $mode) {
            Route::get("/api/transport/{$mode}", [TransportPoiController::class, 'nearbyForType'])
                ->defaults('type', $mode)
                ->name("api.transport.{$mode}");
        }
        foreach ([
            'fuel', 'parking', 'restaurant', 'cafe', 'lodging', 'supermarket', 'taxi', 'hospital', 'pharmacy',
            'fire', 'tourism', 'charging_station', 'police', 'speed_camera', 'speed_limit', 'traffic_sign',
            'vignette_control', 'control', 'locality',
        ] as $category) {
            Route::get("/api/poi/{$category}", [TransportPoiController::class, 'nearbyForCategory'])
                ->defaults('category', $category)
                ->name("api.poi.{$category}");
        }
        Route::get('/api/poi/waze-traffic', [TransportPoiController::class, 'wazeTrafficAlerts'])
            ->name('api.poi.waze_traffic');
        Route::get('/api/transport-nearby', [TransportPoiController::class, 'nearby'])->name('api.transport-nearby');
        Route::get('/api/transit-route/{relationId}', [TransportPoiController::class, 'routeGeometry'])
            ->whereNumber('relationId')->name('api.transit-route');
    });

    Route::get('/api/transit/coverage', CoverageController::class)
        ->middleware('throttle:60,1')->name('api.transit.coverage');
    Route::middleware('throttle:120,1')->prefix('api/transit')->name('api.transit.')->group(function (): void {
        Route::get('/vehicles', VehicleController::class)->name('vehicles');
        Route::get('/stops', [TransitMapController::class, 'stops'])->name('stops');
        Route::get('/stops/nearest-next-departure', [TransitMapController::class, 'nearestNextDeparture'])->name('nearest-next-departure');
        Route::get('/stops/{feed}/{stop}/routes', [TransitMapController::class, 'stopRoutes'])->where('feed', '[0-9]+')->where('stop', '.+')->name('stop-routes');
        Route::get('/stops/{feed}/{stop}/next-departure', [TransitMapController::class, 'nextDeparture'])->where('feed', '[0-9]+')->where('stop', '.+')->name('next-departure');
        Route::get('/routes/{feed}/{route}/shape', [TransitMapController::class, 'routeShape'])->where('feed', '[0-9]+')->where('route', '.+')->name('route-shape');
    });

    Route::post('/location/toggle', [LocationController::class, 'toggle'])->name('user.location.toggle');
    Route::post('/tracking/toggle', [TrackingController::class, 'toggle'])
        ->middleware('throttle:tracking-control')->name('user.tracking.toggle');

    Route::get('/tracking/index', [TrackingController::class, 'index'])->name('trackings.index');
    Route::get('/trasee', fn () => view('trasee'))->name('trasee.index');
    Route::get('/trasee/{route}/navigation', [NavigationController::class, 'show'])->name('navigation.show');
    Route::get('/trasee/{route}/navigation/pois', [NavigationPoiController::class, 'index'])
        ->middleware('throttle:10,1')->name('navigation.pois');
    Route::get('/trasee/{route}/export-kml', [RouteKmlController::class, 'export'])->name('trasee.export-kml');
    Route::post('/tracking/start', [TrackingController::class, 'start'])
        ->middleware('throttle:tracking-control')->name('tracking.start');
    Route::post('/tracking/point', [TrackingController::class, 'point'])
        ->middleware('throttle:tracking-location')->name('tracking.point');
    Route::post('/tracking/stop', [TrackingController::class, 'stop'])
        ->middleware('throttle:tracking-control')->name('tracking.stop');

    Route::get('/map/tiles/{z}/{x}/{y}', [TrackingController::class, 'tile'])
        ->whereNumber(['z', 'x', 'y'])->middleware('throttle:map-tiles')->name('map.tiles');
    Route::get('/map/traffic/{z}/{x}/{y}', TrafficTileController::class)
        ->whereNumber(['z', 'x', 'y'])->middleware('throttle:240,1')->name('navigation.traffic');
    Route::get('/tracks/{session}', [TrackingController::class, 'show'])->name('tracking.show');
    Route::get('/tracking/sessions', [TrackingController::class, 'sessions'])->name('tracking.sessions');
    Route::get('/tracking/{session}/points', [TrackingController::class, 'points'])->name('tracking.points');
    Route::get('/tracking/{session}/route', [TrackingController::class, 'route'])->name('tracking.route');

    Route::get('/current-position', function () {
        return view('current-position');
    })->name('position');

    Route::get('/api/location/reverse', ReverseGeocodeController::class)
    ->middleware('throttle:30,1')
    ->name('api.location.reverse');

    Route::prefix('profile')->name('profile.')->controller(UserProfileController::class)->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::put('/', 'update')->name('update');
        Route::post('/avatar', 'avatar')->name('avatar');
        Route::delete('/avatar', 'removeAvatar')->name('avatar.remove');
        Route::put('/password', 'updatePassword')->name('password');
    });
});

// Both may follow goo.gl redirects; keep outbound calls per user bounded.
Route::middleware(['auth', 'approved', 'throttle:google-maps'])->get('/api/google-maps/resolve', [GoogleMapsController::class, 'resolve'])
    ->name('api.google-maps.resolve');

Route::middleware(['auth', 'approved', 'throttle:google-maps'])->post('/api/trasee/google-maps-kml', GoogleMapsKmlController::class)
    ->name('api.trasee.google-maps-kml');