@extends('layouts.app')

@section('content')
<div class="container">
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

                    {{ __('You are logged in!') }}

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
                                            <label class="form-check-label" for="city-location-mode">Introdu oraș</label>
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

                            <form class="row g-2 mt-2 d-none" id="city-location-form">
                                <div class="col-sm-6 col-md-5">
                                    <label class="form-label" for="city-location-input">Oraș din România</label>
                                    <input class="form-control form-control-sm" id="city-location-input" type="text" autocomplete="address-level2" placeholder="Ex.: București">
                                </div>
                                <div class="col-sm-4 col-md-3">
                                    <label class="form-label" for="city-location-radius">Raza de căutare (metri)</label>
                                    <input class="form-control form-control-sm" id="city-location-radius" type="number" min="100" max="35000" step="100" value="5000" required>
                                </div>
                                <div class="col-auto align-self-end">
                                    <button class="btn btn-sm btn-primary" id="city-location-submit" type="submit">Fixează locația</button>
                                </div>
                                <div class="col-12 small" id="city-location-status" aria-live="polite"></div>
                            </form>


                            <div class="card mt-3 main-mobility-card d-none" id="mobility_card">
                                <div class="card-header">
                                    Mobility Features
                                </div>
                                <div class="card-body">
                                    <div class="row poi-category-filters">
                                        <div class="col-12">
                                            <div class="fw-semibold mb-2">Selectează categoriile POI afișate pe hartă</div>
                                            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-2">
                                                @foreach ($locations as $location)
                                                    <div class="col">
                                                        <div class="border rounded p-2 h-100 poi-category-group" data-type="{{ $location }}">
                                                            <div class="d-flex align-items-center justify-content-between gap-2">
                                                                <div class="form-check mb-0">
                                                                    <input
                                                                        class="form-check-input location-category"
                                                                        type="checkbox"
                                                                        id="poi-category-{{ $loop->index }}"
                                                                        data-type="{{ $location }}"
                                                                    >
                                                                    <label class="form-check-label fw-semibold" for="poi-category-{{ $loop->index }}">
                                                                        {{ $poiCategories[$location]['label'] }}
                                                                    </label>
                                                                </div>
                                                                <button
                                                                    class="btn btn-sm btn-outline-secondary"
                                                                    type="button"
                                                                    data-bs-toggle="collapse"
                                                                    data-bs-target="#poi-subcategories-{{ $loop->index }}"
                                                                    aria-expanded="false"
                                                                    aria-controls="poi-subcategories-{{ $loop->index }}"
                                                                >
                                                                    Subcategorii
                                                                </button>
                                                            </div>
                                                            <div class="collapse mt-2" id="poi-subcategories-{{ $loop->index }}">
                                                                @if ($location === 'police')
                                                                    <div class="small fw-semibold mb-1">Filtre de Poliție</div>
                                                                @endif
                                                                <div class="ps-2">
                                                                    @foreach ($poiSubcategories[$location] as $subcategory)
                                                                        <div class="form-check">
                                                                            <input
                                                                                class="form-check-input location-subcategory"
                                                                                type="checkbox"
                                                                                id="poi-subcategory-{{ $loop->parent->index }}-{{ $loop->index }}"
                                                                                data-type="{{ $location }}"
                                                                                data-filter="{{ $subcategory['id'] }}"
                                                                            >
                                                                            <label class="form-check-label" for="poi-subcategory-{{ $loop->parent->index }}-{{ $loop->index }}">
                                                                                {{ $subcategory['label'] }}
                                                                            </label>
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card mt-3 main-map-card d-none" id="map_card">
                                <div class="card-header">
                                    Map
                                </div>
                                <div class="card-body p-0">
                                    <div class="row">
                                        <div class="col-12">
                                            <div id="map" style="height: 600px;"></div>
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
</div>
<input type="hidden" name="radius" id="radius" value="{{config('app.distance_number')}}" data-unit="{{config('app.distance_unit')}}">
@include('modals.acquire')
@include('modals.train-status')
@include('modals.lodging-details')
@endsection
@push('js')
    @vite([
        'resources/sass/pages/dashboard.scss',
        'resources/js/pages/dashboard.js',
        'resources/js/pages/map.js'
        ])
@endpush
