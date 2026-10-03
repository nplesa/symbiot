<?php

use App\Http\Controllers\AirportPoiController;
use App\Http\Controllers\BusPoiController;
use App\Http\Controllers\CafePoiController;
use App\Http\Controllers\ChargingStationPoiController;
use App\Http\Controllers\CityLocationController;
use App\Http\Controllers\ControlPoiController;
use App\Http\Controllers\FirePoiController;
use App\Http\Controllers\FuelPoiController;
use App\Http\Controllers\GoogleMapsController;
use App\Http\Controllers\GoogleMapsKmlController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HospitalPoiController;
use App\Http\Controllers\LocalityPoiController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LodgingPoiController;
use App\Http\Controllers\NavigationController;
use App\Http\Controllers\NavigationPoiController;
use App\Http\Controllers\ParkingPoiController;
use App\Http\Controllers\PharmacyPoiController;
use App\Http\Controllers\PolicePoiController;
use App\Http\Controllers\RestaurantPoiController;
use App\Http\Controllers\RouteKmlController;
use App\Http\Controllers\SpeedCameraPoiController;
use App\Http\Controllers\SpeedLimitPoiController;
use App\Http\Controllers\SubwayPoiController;
use App\Http\Controllers\SupermarketPoiController;
use App\Http\Controllers\TaxiPoiController;
use App\Http\Controllers\TourismPoiController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\TrafficSignPoiController;
use App\Http\Controllers\TrafficTileController;
use App\Http\Controllers\TrainPoiController;
use App\Http\Controllers\TransportPoiController;
use App\Http\Controllers\VignetteControlPoiController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Auth::routes();

Route::name('app.')->middleware(['auth'])->group(function () {
    Route::get('/home', [HomeController::class, 'index'])->name('home');
    Route::post('/location/update', [LocationController::class, 'update']);
    Route::get('/api/location/city', CityLocationController::class)->middleware('throttle:30,1');
    Route::get('/api/transport/bus', BusPoiController::class)->name('api.transport.bus');
    Route::get('/api/transport/train', TrainPoiController::class)->name('api.transport.train');
    Route::get('/api/transport/subway', SubwayPoiController::class)->name('api.transport.subway');
    Route::get('/api/transport/airport', AirportPoiController::class)->name('api.transport.airport');
    Route::get('/api/poi/fuel', FuelPoiController::class)->name('api.poi.fuel');
    Route::get('/api/poi/parking', ParkingPoiController::class)->name('api.poi.parking');
    Route::get('/api/poi/restaurant', RestaurantPoiController::class)->name('api.poi.restaurant');
    Route::get('/api/poi/cafe', CafePoiController::class)->name('api.poi.cafe');
    Route::get('/api/poi/lodging', LodgingPoiController::class)->name('api.poi.lodging');
    Route::get('/api/poi/supermarket', SupermarketPoiController::class)->name('api.poi.supermarket');
    Route::get('/api/poi/taxi', TaxiPoiController::class)->name('api.poi.taxi');
    Route::get('/api/poi/hospital', HospitalPoiController::class)->name('api.poi.hospital');
    Route::get('/api/poi/pharmacy', PharmacyPoiController::class)->name('api.poi.pharmacy');
    Route::get('/api/poi/fire', FirePoiController::class)->name('api.poi.fire');
    Route::get('/api/poi/tourism', TourismPoiController::class)->name('api.poi.tourism');
    Route::get('/api/poi/charging_station', ChargingStationPoiController::class)->name('api.poi.charging_station');
    Route::get('/api/poi/police', PolicePoiController::class)->name('api.poi.police');
    Route::get('/api/poi/speed_camera', SpeedCameraPoiController::class)->name('api.poi.speed_camera');
    Route::get('/api/poi/speed_limit', SpeedLimitPoiController::class)->name('api.poi.speed_limit');
    Route::get('/api/poi/traffic_sign', TrafficSignPoiController::class)->name('api.poi.traffic_sign');
    Route::get('/api/poi/vignette_control', VignetteControlPoiController::class)->name('api.poi.vignette_control');
    Route::get('/api/poi/control', ControlPoiController::class)->name('api.poi.control');
    Route::get('/api/poi/locality', LocalityPoiController::class)->name('api.poi.locality');
    Route::get('/api/transport-nearby', [TransportPoiController::class, 'nearby']);
    Route::get('/api/transit-route/{relationId}', [TransportPoiController::class, 'routeGeometry'])
        ->whereNumber('relationId');

    Route::post('/location/toggle', [LocationController::class, 'toggle'])->name('user.location.toggle');
    Route::post('/tracking/toggle', [TrackingController::class, 'toggle'])->name('user.tracking.toggle');

    Route::get('/tracking/index', [TrackingController::class, 'index'])->name('trackings.index');
    Route::get('/trasee', fn () => view('trasee'))->name('trasee.index');
    Route::get('/trasee/{route}/navigation', [NavigationController::class, 'show'])->name('navigation.show');
    Route::get('/trasee/{route}/navigation/pois', [NavigationPoiController::class, 'index'])->name('navigation.pois');
    Route::get('/trasee/{route}/export-kml', [RouteKmlController::class, 'export'])->name('trasee.export-kml');
    Route::post('/tracking/start', [TrackingController::class, 'start'])->name('tracking.start');
    Route::post('/tracking/point', [TrackingController::class, 'point'])->name('tracking.point');
    Route::post('/tracking/stop', [TrackingController::class, 'stop'])->name('tracking.stop');

    Route::get('/map/tiles/{z}/{x}/{y}', [TrackingController::class, 'tile']);
    Route::get('/map/traffic/{z}/{x}/{y}', TrafficTileController::class)
        ->whereNumber(['z', 'x', 'y'])->middleware('throttle:240,1')->name('navigation.traffic');
    Route::get('/tracks/{session}', [TrackingController::class, 'show'])->name('tracking.show');
    Route::get('/tracking/sessions', [TrackingController::class, 'sessions'])->name('tracking.sessions');
    Route::get('/tracking/{session}/points', [TrackingController::class, 'points'])->name('tracking.points');
    Route::get('/tracking/{session}/route', [TrackingController::class, 'route'])->name('tracking.route');
});

Route::middleware('auth')->get('/api/google-maps/resolve', [GoogleMapsController::class, 'resolve'])
    ->name('api.google-maps.resolve');

Route::middleware('auth')->post('/api/trasee/google-maps-kml', GoogleMapsKmlController::class)
    ->name('api.trasee.google-maps-kml');
