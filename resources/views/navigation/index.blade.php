@extends('layouts.app')

@section('content')
<div
    class="container navigation-page"
    id="navigation-page"
    data-route-geometry='{{ json_encode($route->geometry, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}'
    data-poi-categories='{{ json_encode($poiCategories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}'
    data-route-pois-url="{{ route('app.navigation.pois', $route) }}"
    data-routes-url="{{ route('app.trasee.index') }}"
    data-simulate="{{ request()->boolean('simulate') ? '1' : '0' }}"
    data-traffic-url="{{ url('/map/traffic') }}/{z}/{x}/{y}"
    data-traffic-configured="{{ filled(config('services.tomtom.key')) ? '1' : '0' }}"
>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
            <div class="text-muted small">{{ __('Navigation mode') }}</div>
            <h1 class="h3 mb-1">{{ $route->name ?: __('Unnamed route') }}</h1>
            <div class="text-muted">{{ number_format($route->distance / 1000, 2, ',', '.') }} km</div>
        </div>
        <a href="{{ route('app.trasee.index') }}" class="btn btn-outline-secondary">{{ __('Back to routes') }}</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-header navigation-traffic-controls">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="navigation-realtime" aria-describedby="navigation-traffic-status">
                <label class="form-check-label" for="navigation-realtime">RealTime <strong id="navigation-realtime-state">Off</strong></label>
            </div>
            <span id="navigation-traffic-status" class="small text-muted" role="status">{{ __('TomTom traffic is off.') }}</span>
            <div id="navigation-traffic-legend" class="small" hidden>
                <span style="color: #168747">●</span> {{ __('Free flow') }}
                <span style="color: #aa830e">●</span> {{ __('Slow') }}
                <span style="color: #fb2d09">●</span> {{ __('Congested') }}
                <span style="color: #ad0000">●</span> {{ __('Heavily congested') }}
                <span class="text-muted"> · {{ __('No colour does not mean free-flowing traffic.') }}</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div id="navigation-toast-container" class="navigation-toast-container" aria-live="polite" aria-atomic="true"></div>
            <div id="navigation-map" class="navigation-map">
                <div class="navigation-map-tools" aria-label="{{ __('Map controls') }}">
                    <button type="button" id="navigation-zoom-in" title="{{ __('Zoom in') }}">+</button>
                    <button type="button" id="navigation-zoom-out" title="{{ __('Zoom out') }}">−</button>
                    <button type="button" id="navigation-fit" title="{{ __('Show the whole route') }}">⌖</button>
                    <button type="button" id="navigation-locate" title="{{ __('My position') }}">●</button>
                </div>
                <details class="navigation-map-filters" id="navigation-poi-menu">
                    <summary>POI <span id="navigation-poi-count"></span></summary>
                    <div class="navigation-poi-options">
                        <div class="navigation-poi-heading">
                            <strong>{{ __('Points of interest') }}</strong>
                            <button type="button" id="navigation-poi-close" aria-label="{{ __('Close POI filters') }}">×</button>
                        </div>
                        <div class="navigation-poi-actions">
                            <button type="button" data-poi-select="all">{{ __('Select all') }}</button>
                            <button type="button" data-poi-select="none">{{ __('Deselect all') }}</button>
                        </div>
                        @foreach (collect($poiCategories)->groupBy('group_label', preserveKeys: true) as $group => $categories)
                            <fieldset><legend>{{ $group }}</legend>
                                @foreach ($categories as $type => $category)
                                    <label><input type="checkbox" data-poi-filter="{{ $type }}" @checked($category['default'])> <span>{{ $category['label'] }}</span></label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                </details>
                <aside id="navigation-alert-panel" class="navigation-alert-panel" aria-live="polite">
                    <div class="navigation-alert-panel__title">{{ __('Signs on the route') }}</div>
                    <div id="navigation-alert-list" class="navigation-alert-list">
                        <div class="navigation-alert-empty">{{ __('No signs passed yet.') }}</div>
                    </div>
                </aside>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <span id="navigation-status" class="text-muted">{{ __('Preparing navigation...') }}</span>
                <span id="navigation-simulation-speed" class="navigation-simulation-speed" hidden>{{ __('Speed') }}: -- km/h</span>
            </div>
            <div class="d-flex gap-2">
                <button type="button" id="navigation-simulate" class="btn btn-outline-primary btn-sm" disabled>{{ __('Simulate route') }}</button>
                <button type="button" id="navigation-stop" class="btn btn-outline-danger btn-sm">{{ __('Stop navigation') }}</button>
            </div>
        </div>
        <div class="card-footer small d-flex flex-wrap gap-3">
            <span><span class="navigation-legend-dot navigation-legend-police"></span> {{ __('Police') }}</span>
            <span><span class="navigation-legend-dot navigation-legend-speed-camera"></span> {{ __('Speed camera') }}</span>
            <span><span class="navigation-legend-dot navigation-legend-vignette"></span> {{ __('Vignette check') }}</span>
            <span><span class="navigation-legend-dot navigation-legend-control"></span> {{ __('Checkpoint') }}</span>
        </div>
    </div>
</div>
@endsection

@push('css')
    @vite('resources/sass/pages/navigation.scss')
@endpush

@push('js')
    @vite('resources/js/pages/navigation.js')
@endpush
