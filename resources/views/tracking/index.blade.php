@extends('layouts.app')

@section('content')

<div class="tracking-page">

    {{-- PAGE HEADER --}}
    <div class="tracking-heading">
        <div>
            <div class="tracking-eyebrow">{{ __('GPS MONITORING') }}</div>
            <h2>{{ __('Track History') }}</h2>
            <p>{{ __('Review your recorded routes and replay your movements.') }}</p>
        </div>

        <div class="tracking-map-indicator">
            <span></span>
            {{ __('GPS TRACKING') }}
        </div>
    </div>

    {{-- STATUS --}}
    <div class="tracking-status" id="trackStatus">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span>{{ __('Select a date to view your available tracks.') }}</span>
    </div>

    {{-- FILTERS AND PLAYER --}}
    <section class="tracking-controls-card">

        <div class="tracking-card-header">
            <div>
                <h5>{{ __('Track selection') }}</h5>
                <small>{{ __('Choose a date and a recorded session') }}</small>
            </div>
            <i class="fa-solid fa-route tracking-card-icon" aria-hidden="true"></i>
        </div>

        <div class="tracking-filters">

            <div class="tracking-field">
                <label for="trackDay">{{ __('Date') }}</label>
                <div class="tracking-input-wrap">
                    <input
                        type="date"
                        id="trackDay"
                        class="form-control">
                </div>
            </div>

            <div class="tracking-field tracking-session-field">
                <label for="trackDate">{{ __('Available tracks') }}</label>
                <div class="tracking-input-wrap">
                    <i class="fa-solid fa-route" aria-hidden="true"></i>
                    <select
                        id="trackDate"
                        class="form-select"
                        disabled>
                        <option value="">{{ __('Select a date first') }}</option>
                    </select>
                </div>
            </div>

        </div>

        <div class="tracking-player">

            <div class="tracking-player-label">
                <span>{{ __('Track playback') }}</span>
                <small>{{ __('Replay your recorded route') }}</small>
            </div>

            <div class="tracking-player-actions">
                <button type="button" id="trackPlay" class="tracking-player-button primary" title="{{ __('Play') }}" disabled>
                    <i class="fa-solid fa-play" aria-hidden="true"></i>
                    <span>{{ __('Play') }}</span>
                </button>

                <button type="button" id="trackPause" class="tracking-player-button" title="{{ __('Pause') }}" disabled>
                    <i class="fa-solid fa-pause" aria-hidden="true"></i>
                    <span>{{ __('Pause') }}</span>
                </button>

                <button type="button" id="trackReset" class="tracking-player-button" title="{{ __('Reset') }}" disabled>
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                    <span>{{ __('Reset') }}</span>
                </button>

                <div class="tracking-speed-control">
                    <label for="trackSpeed">{{ __('Speed') }}</label>
                    <select id="trackSpeed" class="form-select" disabled>
                        <option value="1">1x</option>
                        <option value="2">2x</option>
                        <option value="5">5x</option>
                    </select>
                </div>
            </div>

        </div>

    </section>

    {{-- TRACK SUMMARY --}}
    <section class="tracking-summary" id="trackStats">

        <div class="tracking-summary-item">
            <div class="tracking-summary-icon">
                <i class="fa-solid fa-route" aria-hidden="true"></i>
            </div>
            <div>
                <span>{{ __('Distance') }}</span>
                <strong id="trackDistance">--</strong>
            </div>
        </div>

        <div class="tracking-summary-item">
            <div class="tracking-summary-icon">
                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
            </div>
            <div>
                <span>{{ __('GPS points') }}</span>
                <strong id="trackPointCount">--</strong>
            </div>
        </div>

        <div class="tracking-summary-item">
            <div class="tracking-summary-icon">
                <i class="fa-regular fa-clock" aria-hidden="true"></i>
            </div>
            <div>
                <span>{{ __('Duration') }}</span>
                <strong id="trackDuration">--</strong>
            </div>
        </div>

    </section>

    {{-- MAP --}}
    <section class="tracking-map-card">

        <div class="tracking-card-header">
            <div>
                <h5>{{ __('Route map') }}</h5>
                <small>{{ __('Recorded GPS route and playback position') }}</small>
            </div>

            <span class="tracking-map-legend">
                <span class="legend-start"></span> {{ __('Start') }}
                <span class="legend-end"></span> {{ __('Finish') }}
                <span class="legend-current"></span> {{ __('Playback') }}
            </span>
        </div>

        <div class="tracking-map-container">
            <div id="track_map"></div>

            <div class="tracking-map-empty" id="trackMapEmpty">
                <div class="tracking-empty-icon">
                    <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
                </div>
                <strong>{{ __('No route selected') }}</strong>
                <span>{{ __('Select a date and track to display the route.') }}</span>
            </div>
        </div>

        <div class="tracking-map-footer">
            <i class="fa-solid fa-map" aria-hidden="true"></i>
            {{ __('Map data © OpenStreetMap contributors') }}
        </div>

    </section>

</div>

@endsection

@push('css')
    @vite('resources/sass/pages/tracks.scss')
@endpush

@push('js')
    @vite('resources/js/pages/tracks.js')
@endpush