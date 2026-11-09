@extends('layouts.app')

@section('content')
<div class="dashboard-map-page">
    <header class="dashboard-topbar">
        <form id="dashboard-search-form" class="dashboard-search-form" role="search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input id="dashboard-search" type="search" placeholder="Caută o localitate..." autocomplete="off" aria-label="Caută o localitate pentru hartă">
            <button type="submit">Caută</button>
            <div class="dashboard-search-results" id="dashboard-search-results" hidden>
                <label for="dashboard-search-select">Alege localitatea</label>
                <select id="dashboard-search-select" class="form-select"></select>
            </div>
            <div class="dashboard-search-status" id="dashboard-search-status" role="status" aria-live="polite"></div>
        </form>
        <div class="dashboard-topbar-actions">
            <button type="button" class="dashboard-track-button" id="dashboard-track-me" aria-pressed="false">
                <i class="fa-solid fa-person-walking" aria-hidden="true"></i><span>Track me</span>
            </button>
            <button type="button" class="dashboard-location-button" id="dashboard-use-location" aria-label="Folosește locația mea">
                <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i><span>Location</span>
            </button>
        </div>
    </header>

<div class="container dashboard-map-content">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">{{ __('Dashboard') }}</div>

                <div class="card-body">
                    @if (session('status'))
                        <div class="alert alert-success" role="alert">
                            {{ session('status') }}
                        </div>
                    @endif

                    <div class="card mt-3" id="location_card">
                        <div class="card-header">
                            <div class="row">
                                <div class="col-4 dashboard-element-title">
                                    Location
                                </div>
                                <div class="col-4">
                                </div>
                                <div class="col-4 dashboard-element">
                                    <div class="btn locate-user dashboard-button">
                                        <i class="fa-solid fa-2x fa-location-dot" id="i_location"></i>
                                    </div>    
                                    <div class="btn track-user dashboard-button">
                                        <i class="fa-solid fa-2x fa-street-view" id="i_tracking"></i>
                                    </div>    
                                </div>
                            </div>
                            
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12 d-flex flex-row flex-wrap align-items-center justify-content-between">
                                    <div class="d-flex flex-column gap-2 align-items-start">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="location-mode" id="toggleLocation" value="device">
                                            <label class="form-check-label" for="toggleLocation">Activate my location</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="location-mode" id="city-location-mode" value="city">
                                            <label class="form-check-label" for="city-location-mode">Add City</label>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="trackingmyself">
                                        <label class="form-check-label" for="trackingmyself">Tracking Me</label>
                                    </div>
                                    <div class="">
                                        <button
                                            id="shareLocation"
                                            class="btn btn-sm btn-warning d-none"
                                        >
                                            Share Location
                                        </button>
                                        <button
                                            id="turismLocations"
                                            class="btn btn-sm btn-warning d-none btn-hidden"
                                        >
                                            Visit
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <form class="row g-2 align-items-end mt-2 d-none" id="city-location-form">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="city-location-input">Search city </label>
                                    <input class="form-control" id="city-location-input" type="text" autocomplete="address-level2" placeholder="Ex.: Quebec, Canada" minlength="2" maxlength="100" required>
                                </div>
                                <div class="col-12 col-sm-6 col-md-3">
                                    <label class="form-label" for="city-location-radius">Search radius (meters)</label>
                                    <input class="form-control" id="city-location-radius" type="number" min="100" max="35000" step="100" value="5000" required>
                                </div>
                                <div class="col-12 col-sm-6 col-md-auto">
                                    <button class="btn btn-primary w-100" id="city-location-submit" type="submit">Set Location</button>
                                </div>
                                <div class="col-12 d-none" id="city-location-results-container">
                                    <label class="form-label" for="city-location-results">Select city and country</label>
                                    <select class="form-select" id="city-location-results" disabled>
                                        <option value="">Select a city</option>
                                    </select>
                                </div>
                                <div class="col-12 small" id="city-location-status" aria-live="polite"></div>
                            </form>


                            <div class="card mt-3 main-mobility-card d-none" id="mobility_card">
                                <div class="card-header">
                                    Puncte de interes și filtre
                                </div>
                                <div class="card-body">
                                    <div class="poi-filter-toolbar">
                                        <p>Selectează categoriile pe care vrei să le vezi pe hartă.</p>
                                        <label class="poi-filter-search">
                                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                            <input type="search" class="poi-category-search" placeholder="Caută o categorie..." aria-label="Caută o categorie POI">
                                        </label>
                                        <button type="button" class="poi-clear-selection">Șterge selecția</button>
                                    </div>
                                    <div class="poi-category-filters">
                                        @foreach ($locations as $location)
                                            @if ($location === 'police' || $poiCategories[$location]['group'] !== 'Poliție')
                                                @include('partials.poi-category-filter', ['categoryIndex' => $loop->index])
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            <div class="card main-map-card" id="map_card">
                                <div class="card-header">
                                    Map
                                </div>
                                <div class="card-body p-0">
                                    <div class="row">
                                        <div class="col-12">
                                            <div id="map" style="height: 600px;">
                                                <div class="map-tile-progress d-none" id="map-tile-progress" role="status" aria-live="polite">
                                                    <div class="d-flex justify-content-between gap-3 small mb-1">
                                                        <span id="map-tile-progress-label">Se încarcă harta...</span>
                                                        <span id="map-tile-progress-count">0 / 0 (0%)</span>
                                                    </div>
                                                    <div
                                                        class="progress"
                                                        role="progressbar"
                                                        aria-label="Progresul încărcării hărții"
                                                        aria-valuemin="0"
                                                        aria-valuemax="100"
                                                        aria-valuenow="0"
                                                    >
                                                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="map-tile-progress-bar" style="width: 0%"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="card mt-3 d-none" id="phone_card">
                      <div class="card-header">
                        Phone
                      </div>
                      <div class="card-body">
                            <div class="row">
                                <div class="col-12 d-flex flex-row flex-wrap align-items-center justify-content-between">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="toggleEnableDevice">
                                        <label class="form-check-label" for="toggleEnableDevice">Activate target</label>
                                    </div>
                                    <div class="mt-md-1 mt-3">
                                        <button
                                            id="configureDevice"
                                            class="btn btn-sm btn-primary"
                                        >
                                            Configure
                                        </button>
                                        <button
                                            id="approachDevice"
                                            class="btn btn-sm btn-danger"
                                        >
                                            Approach
                                        </button>
                                        <button
                                            id="listenDevice"
                                            class="btn btn-sm btn-success"
                                        >
                                            Listen
                                        </button>
                                        <button
                                            id="recordCall"
                                            class="btn btn-sm btn-warning"
                                        >
                                            Record
                                        </button>
                                    </div>
                                </div>
                            </div>
                      </div>
                    </div>

                    <div class="card mt-3 d-none" id="wifi_card">
                        <div class="card-header">
                        Wifi
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12 d-flex flex-row flex-wrap align-items-center justify-content-between">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="toggleWifi">
                                        <label class="form-check-label" for="toggleEnableDevice">Activate Wifi</label>
                                    </div>
                                    <div class="mt-md-1 mt-3">
                                        <button
                                            id="wifiConnect"
                                            class="btn btn-sm btn-primary"
                                        >
                                            Connect
                                        </button>
                                        <button
                                            id="wifiDisconnect"
                                            class="btn btn-sm btn-danger"
                                        >
                                            Disconnect
                                        </button>
                                        <button
                                            id="checkWifiData"
                                            class="btn btn-sm btn-success"
                                        >
                                            Check
                                        </button>
                                        <button
                                            id="recordWifiData"
                                            class="btn btn-sm btn-warning"
                                        >
                                            Record
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mt-3 d-none" id="camera_card">
                      <div class="card-header">
                        Camera
                      </div>
                      <div class="card-body">
                            <div class="row">
                                <div class="col-12 d-flex flex-row flex-wrap align-items-center justify-content-between">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="toggleCameras">
                                        <label class="form-check-label" for="toggleCameras">Detect Cameras</label>
                                    </div>
                                    <div class="mt-md-1 mt-3">
                                        <button
                                            id="acquireCameras"
                                            class="btn btn-sm btn-primary"
                                        >
                                            Acquire
                                        </button>
                                        <button
                                            id="searchObject"
                                            class="btn btn-sm btn-danger"
                                        >
                                            Search
                                        </button>
                                        <button
                                            id="checkCameraData"
                                            class="btn btn-sm btn-success"
                                        >
                                            Check
                                        </button>
                                        <button
                                            id="removeCameras"
                                            class="btn btn-sm btn-warning"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                      </div>
                    </div>

                    <div class="card mt-3 d-none" id="drone_card">
                      <div class="card-header">
                        iDrone System
                      </div>
                      <div class="card-body">
                            <div class="row">
                                <div class="col-12 d-flex flex-row flex-wrap align-items-center justify-content-between">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="toggleCameras">
                                        <label class="form-check-label" for="toggleCameras">Activate</label>
                                    </div>
                                    <div class="mt-md-1 mt-3">
                                        <button
                                            id="fixTarget"
                                            class="btn btn-sm btn-primary"
                                        >
                                            Set Target
                                        </button>
                                        <button
                                            id="controlDrone"
                                            class="btn btn-sm btn-primary"
                                        >
                                            Control
                                        </button>
                                        <button
                                            id="destroyDrone"
                                            class="btn btn-sm btn-danger"
                                        >
                                            Destroy
                                        </button>
                                        <button
                                            id="downloadData"
                                            class="btn btn-sm btn-success"
                                        >
                                            Download Data
                                        </button>
                                    </div>
                                </div>
                            </div>
                      </div>
                    </div>


                </div>
            </div>
        </div>
    </div>
    <aside class="dashboard-route-panel" id="dashboard-route-panel" aria-live="polite">
        <div class="dashboard-panel-toolbar">
            <div class="dashboard-panel-heading">
                <span class="dashboard-panel-icon"><i class="fa-solid fa-route"></i></span>
                <div><span class="dashboard-eyebrow">SYMBIOT · HARTĂ</span><h1>Explorează</h1></div>
                <span class="dashboard-live-poi-count" id="dashboard-panel-poi-count">0 POI</span>
            </div>
            <button type="button" class="dashboard-icon-button dashboard-panel-close" id="dashboard-panel-close" aria-label="Ascunde panoul"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="dashboard-transit-progress-host" class="dashboard-transit-progress-host" aria-live="polite"></div>

        <section class="dashboard-radius-control d-none" id="dashboard-radius-control" aria-labelledby="dashboard-radius-label">
            <div class="dashboard-radius-heading">
                <label id="dashboard-radius-label" for="dashboard-search-radius">Raza cercului</label>
                <output id="dashboard-radius-value" for="dashboard-search-radius">5 km</output>
            </div>
            <label class="dashboard-citywide-choice d-none" id="dashboard-citywide-wrap" for="dashboard-citywide">
                <input class="form-check-input" type="checkbox" id="dashboard-citywide">
                <span>Tot orașul</span>
            </label>
            <input class="form-range" id="dashboard-search-radius" type="range" min="100" max="35000" step="100" value="5000" aria-label="Raza cercului">
            <div class="dashboard-radius-scale"><span>100 m</span><span>35 km</span></div>
            <div class="dashboard-radius-status" id="dashboard-radius-status" role="status" aria-live="polite"></div>
        </section>

        <section class="dashboard-route-empty" id="dashboard-route-empty">
            <div class="dashboard-empty-illustration"><i class="fa-solid fa-bus-simple"></i></div>
            <h2 id="dashboard-empty-title">Explorează zona</h2>
            <p id="dashboard-empty-copy">Alege o localitate, apoi selectează categoriile POI pe care vrei să le vezi pe hartă.</p>
            <button type="button" class="btn dashboard-primary-button" id="dashboard-search-location"><i class="fa-solid fa-magnifying-glass-location"></i> Caută o localitate</button>
            <a href="{{ route('app.trasee.index') }}" class="dashboard-routes-link"><i class="fa-solid fa-map-location-dot"></i> Traseele mele</a>
        </section>

        <div class="accordion dashboard-poi-disclosure d-none" id="dashboard-poi-disclosure">
            <div class="accordion-item">
                <h2 class="accordion-header" id="dashboard-poi-heading">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#dashboard-poi-collapse" aria-expanded="true" aria-controls="dashboard-poi-collapse">
                        <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                        <span>Alege categoriile POI</span>
                        <span class="dashboard-poi-count" id="dashboard-poi-count">0 găsite</span>
                    </button>
                </h2>
                <div id="dashboard-poi-collapse" class="accordion-collapse collapse show" aria-labelledby="dashboard-poi-heading" data-bs-parent="#dashboard-poi-disclosure">
                    <div class="accordion-body" id="dashboard-poi-filters"></div>
                </div>
            </div>
        </div>

        <section class="dashboard-stop-routes" id="dashboard-stop-routes" hidden aria-labelledby="dashboard-stop-routes-title">
            <div class="dashboard-stop-routes-heading">
                <div><span class="dashboard-eyebrow">STAȚIE SELECTATĂ</span><h2 id="dashboard-stop-routes-title">Linii în stație</h2></div>
                <span class="dashboard-stop-routes-count" id="dashboard-stop-routes-count">0 linii</span>
            </div>
            <p class="dashboard-stop-routes-stop" id="dashboard-stop-routes-stop"></p>
            <div class="dashboard-stop-routes-list" id="dashboard-stop-routes-list"></div>
        </section>

        <section class="dashboard-route-details" id="dashboard-route-details" hidden>
            <div class="dashboard-route-title-row">
                <span class="dashboard-route-badge" id="dashboard-route-badge">—</span>
                <div><span class="dashboard-eyebrow" id="dashboard-route-mode">Transport public</span><h2 id="dashboard-route-title">Traseu</h2><p id="dashboard-route-subtitle"></p></div>
            </div>
            <div class="dashboard-route-metrics">
                <div><i class="fa-solid fa-route"></i><span>Distanță</span><strong id="dashboard-route-distance">—</strong></div>
                <div><i class="fa-regular fa-clock"></i><span>Plecare</span><strong id="dashboard-route-departure">Se caută…</strong></div>
            </div>
            <section class="dashboard-next-trip">
                <h3><i class="fa-solid fa-clock"></i> Următoarea plecare</h3>
                <strong class="dashboard-countdown" id="dashboard-route-countdown">Se caută orarul…</strong>
                <p id="dashboard-route-stop"></p>
                <small id="dashboard-route-headsign"></small>
            </section>
            <section class="dashboard-route-map-info">
                <div class="dashboard-section-title"><h3>Traseul pe hartă</h3><button type="button" id="dashboard-fit-route" aria-label="Arată tot traseul"><i class="fa-solid fa-expand"></i></button></div>
                <p id="dashboard-route-shape-status">Linia selectată este evidențiată pe hartă.</p>
            </section>
            <a href="{{ route('app.trasee.index') }}" class="dashboard-routes-link"><i class="fa-solid fa-list"></i> Deschide traseele mele</a>
        </section>

        <section class="dashboard-nearby-section" aria-labelledby="dashboard-nearby-title">
            <div class="dashboard-section-title">
                <h3 id="dashboard-nearby-title">Puncte în apropiere</h3>
                <span class="dashboard-nearby-count" id="dashboard-nearby-count">0</span>
            </div>
            <p class="dashboard-nearby-status" id="dashboard-nearby-status">Selectează una sau mai multe categorii POI pentru a încărca punctele din apropiere.</p>
            <ul class="dashboard-nearby-list" id="dashboard-nearby-list"></ul>
        </section>

    </aside>
</div>
</div>
<input type="hidden" name="radius" id="radius" value="{{config('app.distance_number')}}" data-unit="{{config('app.distance_unit')}}">
@include('modals.acquire')
@include('modals.train-status')
@include('modals.lodging-details')
@include('modals.fuel-prices')
@include('modals.fuel-best')
@endsection
@push('js')
    @vite([
        'resources/sass/pages/dashboard.scss',
        'resources/js/pages/dashboard.js',
        'resources/js/pages/map.js'
        ])
@endpush
