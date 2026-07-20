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
                            Location
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12 d-flex flex-row flex-wrap align-items-center justify-content-between">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="toggleLocation">
                                        <label class="form-check-label" for="toggleLocation">Activate my location</label>
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


                            <div class="card mt-3 main-mobility-card d-none" id="mobility_card">
                                <div class="card-header">
                                    Mobility Features
                                </div>
                                <div class="card-body">
                                    <div class="page-header mb-0 pb-0 border-bottom-0 d-none count-locations">
                                        <div class="row align-items-end">
                                            <div class="col-sm mb-2 mb-sm-0">
                                                <h6 class="page-header-title" id="locations_number"></h6>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-3 mobility-cards-container d-none">
                                         @foreach ($locations as $location)

                                            <div class="col-md-4 col-12 mobility mobility-{{$location}} d-none">
                                                <div class="card text-center mobility-card mb-3" id="{{$location}}">
                                                    <div class="card-header d-flex flex-row justify-content-between align-items-center">
                                                        <span class="category-color"></span>
                                                        <div>

                                                    <div class="form-check form-switch d-flex flex-row-reverse align-items-center">
                                                        <input class="form-check-input location-category mx-2"
                                                           type="checkbox"
                                                           role="switch"
                                                           id="cat_{{ $loop->index }}"
                                                           data-type="{{ $location }}"
                                                           checked>
                                                        <label class="form-check-label me-2" for="cat_{{ $loop->index }}">
                                                            <span>{{ ucfirst($location) }} Locations</span>
                                                        </label>
                                                    </div>

                                                        </div>  
                                                    </div>
                                                    <div class="card-body mobility-card-body">

                                                    </div>
                                                    <div class="card-footer text-body-secondary">
                                                    
                                                    </div>
                                                </div>
                                            </div>    


                                         @endforeach   

                                    </div>
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="form-check form-switch">
                                              <input class="form-check-input" type="checkbox" role="switch" id="auto_detect_location" disabled>
                                              <label class="form-check-label" for="auto_detect_location">Activate stations auto-location</label>
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
@endsection
@push('js')
    @vite([
        'resources/sass/pages/dashboard.scss',
        'resources/js/pages/dashboard.js',
        'resources/js/pages/map.js'
        ])
@endpush
