@extends('layouts.app')

@section('content')

<div class="current-position-page">

    {{-- PAGE HEADER --}}
    <div class="current-position-heading">
        <div>
            <div class="current-position-eyebrow">
                {{ __('GPS MONITORING') }}
            </div>
            <h2>{{ __('Current Position') }}</h2>
            <p>{{ __('Monitor your current location and GPS information.') }}</p>
        </div>

        <button type="button" id="locateMe" class="btn btn-primary">
            <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i>
            {{ __('Locate me') }}
        </button>
    </div>

    {{-- STATUS --}}
    <div class="current-position-status" id="locationStatus">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span>{{ __('Press "Locate me" to get your current position.') }}</span>
    </div>

    {{-- MAP + INFORMATION --}}
    <div class="current-position-grid">

        <section class="current-position-map-card">
            <div class="current-position-card-header">
                <div>
                    <h5>{{ __('Live Location') }}</h5>
                    <small>{{ __('Your current GPS position') }}</small>
                </div>
                <span class="position-map-indicator">
                    <span></span> {{ __('GPS') }}
                </span>
            </div>

            <div class="current-position-map">
                <div id="currentPositionMap"></div>
            </div>

            <div class="current-position-map-footer">
                <i class="fa-solid fa-map" aria-hidden="true"></i>
                {{ __('Map data © OpenStreetMap contributors') }}
            </div>
        </section>

        <div class="current-position-information">

            {{-- ADDRESS CARD --}}
            <section class="current-position-card">
                <div class="current-position-card-header">
                    <div>
                        <h5>{{ __('Location Address') }}</h5>
                        <small>{{ __('Geographical information') }}</small>
                    </div>
                    <i class="fa-solid fa-map-location-dot position-card-icon" aria-hidden="true"></i>
                </div>

                <div class="position-address-main" id="positionFullAddress">
                    {{ __('Address not available') }}
                </div>

                <div class="position-address-details">

                    <div class="position-detail">
                        <span>{{ __('Street') }}</span>
                        <strong id="positionStreet">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('House number') }}</span>
                        <strong id="positionHouseNumber">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('District / Area') }}</span>
                        <strong id="positionDistrict">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('City / Locality') }}</span>
                        <strong id="positionCity">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('County') }}</span>
                        <strong id="positionCounty">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('Postal code') }}</span>
                        <strong id="positionPostcode">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('Country') }}</span>
                        <strong id="positionCountry">--</strong>
                    </div>

                </div>
            </section>

            {{-- GPS CARD --}}
            <section class="current-position-card">
                <div class="current-position-card-header">
                    <div>
                        <h5>{{ __('GPS Information') }}</h5>
                        <small>{{ __('Technical position data') }}</small>
                    </div>
                    <i class="fa-solid fa-satellite-dish position-card-icon" aria-hidden="true"></i>
                </div>

                <div class="position-gps-details">

                    <div class="position-detail">
                        <span>{{ __('Latitude') }}</span>
                        <strong id="positionLatitude">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('Longitude') }}</span>
                        <strong id="positionLongitude">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('Accuracy') }}</span>
                        <strong id="positionAccuracy">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('Altitude') }}</span>
                        <strong id="positionAltitude">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('Speed') }}</span>
                        <strong id="positionSpeed">--</strong>
                    </div>

                    <div class="position-detail">
                        <span>{{ __('Heading') }}</span>
                        <strong id="positionHeading">--</strong>
                    </div>

                    <div class="position-detail position-detail-full">
                        <span>{{ __('Last update') }}</span>
                        <strong id="positionUpdated">--</strong>
                    </div>

                </div>
            </section>

        </div>
    </div>

</div>

@endsection

@push('css')
    @vite('resources/sass/pages/current-position.scss')
@endpush

@push('js')
    @vite('resources/js/pages/current-position.js')
@endpush