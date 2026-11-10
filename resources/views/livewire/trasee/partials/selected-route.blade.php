<section class="trasee-panel mt-4">
<div class="trasee-panel__header">
    <div>
        <h2 class="h5 mb-1">{{ __('Selected route') }}</h2>
        <p class="text-muted small mb-0">{{ __('The selected route will be used as the planned route for the next tracking stage.') }}</p>
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
                    <span class="badge text-bg-success">{{ __('Selected route') }}</span>
                    <strong>{{ $selectedRoute->name ?: __('Unnamed route') }}</strong>
                    <span class="text-muted">{{ number_format($selectedRoute->distance / 1000, 2, ',', '.') }} km</span>
                </div>
                <div class="traseu-route-meta">
    @if ($selectedRoute->duration)
        <div>
            <span>{{ __('Duration') }}</span>
            <strong>{{ gmdate('H:i', $selectedRoute->duration) }}</strong>
        </div>
    @endif
    @if ($selectedRoute->elevation_gain !== null)
        <div>
            <span>{{ __('Elevation gain') }}</span>
            <strong>{{ number_format($selectedRoute->elevation_gain, 0, ',', '.') }} m</strong>
        </div>
    @endif
    @if ($selectedRoute->elevation_loss !== null)
        <div>
            <span>{{ __('Elevation loss') }}</span>
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
                        <x-trasee-icon name="play" class="me-1" /> {{ __('Start route') }}
                    </span>
                    <span wire:loading wire:target="startRoute">
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        {{ __('Starting...') }}
                    </span>
                </button>

                <a
                    href="{{ route('app.trasee.export-kml', $selectedRoute) }}"
                    class="btn btn-primary btn-sm"
                    title="{{ __('Export route for Google Maps / My Maps') }}"
                >
                    <x-trasee-icon name="download" class="me-1" /> {{ __('Export Google Maps') }}
                </a>
            </div>
        </div>
    @else
        <div class="traseu-map-empty">
            <x-trasee-icon name="map" />
            <span>{{ __('Select a route from the list to see the preview.') }}</span>
        </div>
    @endif
</div>

@error('selectedRouteId')
    <div class="px-4 pb-4"><div class="alert alert-warning mb-0">{{ $message }}</div></div>
@enderror
</section>