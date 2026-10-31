
@extends('layouts.app')

@section('content')

<div class="current-position-page">

    {{-- PAGE HEADER --}}
    <div class="current-position-heading">
        <div>
            <div class="current-position-eyebrow">
                GPS MONITORING
            </div>
            <h2>Current Position</h2>
            <p>Monitor your current location and GPS information.</p>
        </div>

        <button type="button" id="locateMe" class="btn btn-primary">
            <i class="fa-solid fa-location-crosshairs"></i>
            Locate me
        </button>
    </div>

    {{-- STATUS --}}
    <div class="current-position-status" id="locationStatus">
        <i class="fa-solid fa-circle-info"></i>
        <span>Press "Locate me" to get your current position.</span>
    </div>

    {{-- MAP + INFORMATION --}}
    <div class="current-position-grid">

        <section class="current-position-map-card">
            <div class="current-position-card-header">
                <div>
                    <h5>Live Location</h5>
                    <small>Your current GPS position</small>
                </div>
                <span class="position-map-indicator">
                    <span></span> GPS
                </span>
            </div>

            <div class="current-position-map">
                <div id="currentPositionMap"></div>
            </div>

            <div class="current-position-map-footer">
                <i class="fa-solid fa-map"></i>
                Map data © OpenStreetMap contributors
            </div>
        </section>

        <div class="current-position-information">

            {{-- ADDRESS CARD --}}
            <section class="current-position-card">
                <div class="current-position-card-header">
                    <div>
                        <h5>Location Address</h5>
                        <small>Geographical information</small>
                    </div>
                    <i class="fa-solid fa-map-location-dot position-card-icon"></i>
                </div>

                <div class="position-address-main" id="positionFullAddress">
                    Address not available
                </div>

                <div class="position-address-details">

                    <div class="position-detail">
                        <span>Street</span>
                        <strong id="positionStreet">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>House number</span>
                        <strong id="positionHouseNumber">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>District / Area</span>
                        <strong id="positionDistrict">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>City / Locality</span>
                        <strong id="positionCity">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>County</span>
                        <strong id="positionCounty">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>Postal code</span>
                        <strong id="positionPostcode">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>Country</span>
                        <strong id="positionCountry">--</strong>
                    </div>

                </div>
            </section>

            {{-- GPS CARD --}}
            <section class="current-position-card">
                <div class="current-position-card-header">
                    <div>
                        <h5>GPS Information</h5>
                        <small>Technical position data</small>
                    </div>
                    <i class="fa-solid fa-satellite-dish position-card-icon"></i>
                </div>

                <div class="position-gps-details">

                    <div class="position-detail">
                        <span>Latitude</span>
                        <strong id="positionLatitude">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>Longitude</span>
                        <strong id="positionLongitude">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>Accuracy</span>
                        <strong id="positionAccuracy">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>Altitude</span>
                        <strong id="positionAltitude">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>Speed</span>
                        <strong id="positionSpeed">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>Heading</span>
                        <strong id="positionHeading">--</strong>
                    </div>

                    <div class="position-detail position-detail-full">
                        <span>Last update</span>
                        <strong id="positionUpdated">--</strong>
                    </div>

                </div>
            </section>

        </div>
    </div>

</div>

@endsection

@push('css')
    @vite([
    'resources/sass/pages/dashboard.scss',
    'resources/sass/pages/current-position.scss'
    ])
@endpush

@push('js')
    @vite('resources/js/pages/current-position.js')
@endpush
