<section class="trasee-panel mt-4">
<div class="trasee-panel__header">
    <div>
        <h2 class="h5 mb-1">Traseu selectat</h2>
        <p class="text-muted small mb-0">Traseul selectat va fi folosit ca traseu planificat pentru următoarea etapă de tracking.</p>
    </div>
</div>

<div
    id="traseu_map_container"
    class="traseu-map-container"
    data-route-id="{{ $selectedRoute?->id ?? '' }}"
    data-route-geometry='{{ json_encode($selectedRoute?->geometry, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}'
>
    <div id="traseu_map" class="traseu-map" wire:ignore></div>

    @if ($selectedRoute)
        <div class="traseu-selected-info">
            <div class="traseu-selected-info__details">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge text-bg-success">Traseu selectat</span>
                    <strong>{{ $selectedRoute->name ?: 'Traseu fără nume' }}</strong>
                    <span class="text-muted">{{ number_format($selectedRoute->distance / 1000, 2, ',', '.') }} km</span>
                </div>
                <div class="traseu-route-meta">
    @if ($selectedRoute->duration)
        <div>
            <span>Durată</span>
            <strong>{{ gmdate('H:i', $selectedRoute->duration) }}</strong>
        </div>
    @endif
    @if ($selectedRoute->elevation_gain !== null)
        <div>
            <span>Urcare</span>
            <strong>{{ number_format($selectedRoute->elevation_gain, 0, ',', '.') }} m</strong>
        </div>
    @endif
    @if ($selectedRoute->elevation_loss !== null)
        <div>
            <span>Coborâre</span>
            <strong>{{ number_format($selectedRoute->elevation_loss, 0, ',', '.') }} m</strong>
        </div>
    @endif
                </div>
            </div>
            <div class="traseu-selected-info__actions d-flex flex-wrap gap-2 ms-lg-auto">
                <button
                    type="button"
                    class="btn btn-success btn-sm"
                    wire:click="startRoute"
                    wire:loading.attr="disabled"
                    wire:target="startRoute"
                >
                    <span wire:loading.remove wire:target="startRoute">
                        <x-trasee-icon name="play" class="me-1" /> Începe traseul
                    </span>
                    <span wire:loading wire:target="startRoute">
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        Se pornește...
                    </span>
                </button>

                <a
                    href="{{ route('app.trasee.export-kml', $selectedRoute) }}"
                    class="btn btn-primary btn-sm"
                    title="Exportă traseul pentru Google Maps / My Maps"
                >
                    <x-trasee-icon name="download" class="me-1" /> Export Google Maps
                </a>
            </div>
        </div>
    @else
        <div class="traseu-map-empty">
<x-trasee-icon name="map" />
<span>Selectează un traseu din listă pentru a vedea preview-ul.</span>
        </div>
    @endif
</div>

@error('selectedRouteId')
    <div class="px-4 pb-4"><div class="alert alert-warning mb-0">{{ $message }}</div></div>
@enderror
</section>
