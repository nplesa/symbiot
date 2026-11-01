
@extends('layouts.app')

@section('content')

<div class="tracking-page">

    {{-- PAGE HEADER --}}
    <div class="tracking-heading">
        <div>
            <div class="tracking-eyebrow">GPS MONITORING</div>
            <h2>Track History</h2>
            <p>Review your recorded routes and replay your movements.</p>
        </div>

        <div class="tracking-map-indicator">
            <span></span>
            GPS TRACKING
        </div>
    </div>

    {{-- STATUS --}}
    <div class="tracking-status" id="trackStatus">
        <i class="fa-solid fa-circle-info"></i>
        <span>Select a date to view your available tracks.</span>
    </div>

    {{-- FILTERS AND PLAYER --}}
    <section class="tracking-controls-card">

        <div class="tracking-card-header">
            <div>
                <h5>Track selection</h5>
                <small>Choose a date and a recorded session</small>
            </div>
            <i class="fa-solid fa-route tracking-card-icon"></i>
        </div>

        <div class="tracking-filters">

            <div class="tracking-field">
                <label for="trackDay">Date</label>
                <div class="tracking-input-wrap">
                    <i class="fa-regular fa-calendar"></i>
                    <input
                        type="date"
                        id="trackDay"
                        class="form-control">
                </div>
            </div>

            <div class="tracking-field tracking-session-field">
                <label for="trackDate">Available tracks</label>
                <div class="tracking-input-wrap">
                    <i class="fa-solid fa-route"></i>
                    <select
                        id="trackDate"
                        class="form-select"
                        disabled>
                        <option value="">Select a date first</option>
                    </select>
                </div>
            </div>

        </div>

        <div class="tracking-player">

            <div class="tracking-player-label">
                <span>Track playback</span>
                <small>Replay your recorded route</small>
            </div>

            <div class="tracking-player-actions">
                <button type="button" id="trackPlay" class="tracking-player-button primary" title="Play" disabled>
                    <i class="fa-solid fa-play"></i>
                    <span>Play</span>
                </button>

                <button type="button" id="trackPause" class="tracking-player-button" title="Pause" disabled>
                    <i class="fa-solid fa-pause"></i>
                    <span>Pause</span>
                </button>

                <button type="button" id="trackReset" class="tracking-player-button" title="Reset" disabled>
                    <i class="fa-solid fa-rotate-left"></i>
                    <span>Reset</span>
                </button>

                <div class="tracking-speed-control">
                    <label for="trackSpeed">Speed</label>
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
                <i class="fa-solid fa-route"></i>
            </div>
            <div>
                <span>Distance</span>
                <strong id="trackDistance">--</strong>
            </div>
        </div>

        <div class="tracking-summary-item">
            <div class="tracking-summary-icon">
                <i class="fa-solid fa-location-dot"></i>
            </div>
            <div>
                <span>GPS points</span>
                <strong id="trackPointCount">--</strong>
            </div>
        </div>

        <div class="tracking-summary-item">
            <div class="tracking-summary-icon">
                <i class="fa-regular fa-clock"></i>
            </div>
            <div>
                <span>Duration</span>
                <strong id="trackDuration">--</strong>
            </div>
        </div>

    </section>

    {{-- MAP --}}
    <section class="tracking-map-card">

        <div class="tracking-card-header">
            <div>
                <h5>Route map</h5>
                <small>Recorded GPS route and playback position</small>
            </div>

            <span class="tracking-map-legend">
                <span class="legend-start"></span> Start
                <span class="legend-end"></span> Finish
                <span class="legend-current"></span> Playback
            </span>
        </div>

        <div class="tracking-map-container">
            <div id="track_map"></div>

            <div class="tracking-map-empty" id="trackMapEmpty">
                <div class="tracking-empty-icon">
                    <i class="fa-solid fa-map-location-dot"></i>
                </div>
                <strong>No route selected</strong>
                <span>Select a date and track to display the route.</span>
            </div>
        </div>

        <div class="tracking-map-footer">
            <i class="fa-solid fa-map"></i>
            Map data © OpenStreetMap contributors
        </div>

    </section>

</div>

@endsection

@push('css')
    @vite([
        'resources/sass/pages/dashboard.scss',
        'resources/sass/pages/tracks.scss'
    ])
@endpush

@push('js')
    @vite('resources/js/pages/tracks.js')
@endpush
