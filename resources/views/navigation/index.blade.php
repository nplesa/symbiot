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
            <div class="text-muted small">Mod Navigație</div>
            <h1 class="h3 mb-1">{{ $route->name ?: 'Traseu fără nume' }}</h1>
            <div class="text-muted">{{ number_format($route->distance / 1000, 2, ',', '.') }} km</div>
        </div>
        <a href="{{ route('app.trasee.index') }}" class="btn btn-outline-secondary">Înapoi la trasee</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-header navigation-traffic-controls">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="navigation-realtime" aria-describedby="navigation-traffic-status">
                <label class="form-check-label" for="navigation-realtime">RealTime <strong id="navigation-realtime-state">Off</strong></label>
            </div>
            <span id="navigation-traffic-status" class="small text-muted" role="status">Trafic TomTom oprit.</span>
            <div id="navigation-traffic-legend" class="small" hidden>
                <span style="color: #168747">●</span> Fluent
                <span style="color: #aa830e">●</span> Încetinit
                <span style="color: #fb2d09">●</span> Aglomerat
                <span style="color: #ad0000">●</span> Foarte aglomerat
                <span class="text-muted"> · Lipsa culorii nu confirmă trafic liber.</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div id="navigation-toast-container" class="navigation-toast-container" aria-live="polite" aria-atomic="true"></div>
            <div id="navigation-map" class="navigation-map">
                <div class="navigation-map-tools" aria-label="Controale hartă">
                    <button type="button" id="navigation-zoom-in" title="Mărește harta">+</button>
                    <button type="button" id="navigation-zoom-out" title="Micșorează harta">−</button>
                    <button type="button" id="navigation-fit" title="Arată traseul">⌖</button>
                    <button type="button" id="navigation-locate" title="Poziția mea">●</button>
                </div>
                <details class="navigation-map-filters" id="navigation-poi-menu">
                    <summary>POI <span id="navigation-poi-count"></span></summary>
                    <div class="navigation-poi-options">
                        <div class="navigation-poi-heading">
                            <strong>Puncte de interes</strong>
                            <button type="button" id="navigation-poi-close" aria-label="Închide filtrele POI">×</button>
                        </div>
                        <div class="navigation-poi-actions">
                            <button type="button" data-poi-select="all">Selectează toate</button>
                            <button type="button" data-poi-select="none">Deselectează toate</button>
                        </div>
                        @foreach (collect($poiCategories)->groupBy('group', preserveKeys: true) as $group => $categories)
                            <fieldset><legend>{{ $group }}</legend>
                                @foreach ($categories as $type => $category)
                                    <label><input type="checkbox" data-poi-filter="{{ $type }}" @checked($category['default'])> <span>{{ $category['label'] }}</span></label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                </details>
                <aside id="navigation-alert-panel" class="navigation-alert-panel" aria-live="polite">
                    <div class="navigation-alert-panel__title">Indicatoare pe traseu</div>
                    <div id="navigation-alert-list" class="navigation-alert-list">
                        <div class="navigation-alert-empty">Niciun indicator întâlnit încă.</div>
                    </div>
                </aside>
                <div class="navigation-map-attribution">© OpenStreetMap contributors</div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <span id="navigation-status" class="text-muted">Se pregătește navigația...</span>
                <span id="navigation-simulation-speed" class="navigation-simulation-speed" hidden>Viteză: -- km/h</span>
            </div>
            <div class="d-flex gap-2">
                <button type="button" id="navigation-simulate" class="btn btn-outline-primary btn-sm" disabled>Simulare Traseu</button>
                <button type="button" id="navigation-stop" class="btn btn-outline-danger btn-sm">Oprește navigația</button>
            </div>
        </div>
        <div class="card-footer small d-flex flex-wrap gap-3">
            <span><span class="navigation-legend-dot navigation-legend-police"></span> Poliție</span>
            <span><span class="navigation-legend-dot navigation-legend-speed-camera"></span> Cameră viteză</span>
            <span><span class="navigation-legend-dot navigation-legend-vignette"></span> Control rovinietă</span>
            <span><span class="navigation-legend-dot navigation-legend-control"></span> Control</span>
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
