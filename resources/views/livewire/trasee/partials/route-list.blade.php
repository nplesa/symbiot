<section class="trasee-panel trasee-route-list-panel h-100">
    <div class="trasee-panel__header">
        <div>
            <h2 class="h5 mb-1">{{ __('My routes') }}</h2>
            <p class="text-muted small mb-0">{{ __('Choose the route you want to follow.') }}</p>
        </div>
        <span class="badge text-bg-light">{{ $routes->count() }}</span>
    </div>
    <div class="trasee-list">
        @forelse ($routes as $route)
            <div
                class="trasee-item {{ $selectedRouteId === $route->id ? 'is-selected' : '' }}"
                wire:key="route-row-{{ $route->id }}"
                wire:click="selectRoute({{ $route->id }})"
                role="button"
                tabindex="0"
                data-route-id="{{ $route->id }}"
                aria-label="{{ __('Select route') }} {{ $route->name ?: __('unnamed') }}"
            >
                <span class="trasee-item__icon"><x-trasee-icon name="turn" /></span>
                <span class="trasee-item__content">
                    <strong>{{ $route->name ?: __('Unnamed route') }}</strong>
                    <small>
                        {{ strtoupper($route->format ?: 'geojson') }}
                        · {{ number_format($route->distance / 1000, 2, ',', '.') }} km
                        · {{ $route->points_count }} {{ __('points') }}
                    </small>
                </span>
                <span class="trasee-item__actions">
                    <a
                        href="{{ route('app.trasee.export-kml', $route) }}"
                        class="trasee-item__export"
                        title="{{ __('Google Maps Export (KML)') }}"
                        aria-label="{{ __('Google Maps export for') }} {{ $route->name ?: __('unnamed') }}"
                        wire:click.stop
                    ><x-trasee-icon name="download" /></a>
                    @if ($selectedRouteId === $route->id)
                        <span class="trasee-item__selected" title="{{ __('Selected route') }}"><x-trasee-icon name="check" /></span>
                    @endif
                    <button
                        type="button"
                        class="trasee-item__delete btn btn-sm btn-outline-danger"
                        x-on:click.stop="Swal.fire({ title: '{{ __('Delete route?') }}', text: '{{ __('This action cannot be undone.') }}', icon: 'warning', showCancelButton: true, confirmButtonText: '{{ __('Delete') }}', cancelButtonText: '{{ __('Cancel') }}', confirmButtonColor: '#dc3545', reverseButtons: true }).then((result) => { if (result.isConfirmed) { $wire.deleteRoute({{ $route->id }}) } })"
                        wire:loading.attr="disabled"
                        wire:target="deleteRoute({{ $route->id }})"
                        title="{{ __('Delete route') }}"
                        aria-label="{{ __('Delete route') }} {{ $route->name ?: __('unnamed') }}"
                    >
                        <span wire:loading.remove wire:target="deleteRoute({{ $route->id }})"><x-trasee-icon name="trash" /></span>
                        <span wire:loading wire:target="deleteRoute({{ $route->id }})" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    </button>
                </span>
            </div>
        @empty
            <div class="trasee-empty">
                <x-trasee-icon name="map" />
                <p class="mb-1">{{ __('You have no routes yet.') }}</p>
                <small class="text-muted">{{ __('Import a GPX, KML or KMZ to get started.') }}</small>
            </div>
        @endforelse
    </div>
</section>