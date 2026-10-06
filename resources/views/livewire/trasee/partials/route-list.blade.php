<section class="trasee-panel trasee-route-list-panel h-100">
    <div class="trasee-panel__header">
        <div>
            <h2 class="h5 mb-1">Traseele mele</h2>
            <p class="text-muted small mb-0">Alege traseul pe care vrei să îl urmezi.</p>
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
                data-route-geometry='{{ json_encode($route->geometry, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}'
                aria-label="Selectează traseul {{ $route->name ?: 'fără nume' }}"
            >
                <span class="trasee-item__icon"><x-trasee-icon name="turn" /></span>
                <span class="trasee-item__content">
                    <strong>{{ $route->name ?: 'Traseu fără nume' }}</strong>
                    <small>
                        {{ strtoupper($route->format ?: 'geojson') }}
                        · {{ number_format($route->distance / 1000, 2, ',', '.') }} km
                        · {{ $route->points_count }} puncte
                    </small>
                </span>
                <span class="trasee-item__actions">
                    <a
                        href="{{ route('app.trasee.export-kml', $route) }}"
                        class="trasee-item__export"
                        title="Export Google Maps (KML)"
                        aria-label="Export Google Maps pentru {{ $route->name ?: 'fără nume' }}"
                        wire:click.stop
                    ><x-trasee-icon name="download" /></a>
                    @if ($selectedRouteId === $route->id)
                        <span class="trasee-item__selected" title="Traseu selectat"><x-trasee-icon name="check" /></span>
                    @endif
                    <button
                        type="button"
                        class="trasee-item__delete btn btn-sm btn-outline-danger"
                        x-on:click.stop="Swal.fire({ title: 'Ștergi traseul?', text: 'Această acțiune nu poate fi anulată.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Șterge', cancelButtonText: 'Anulează', confirmButtonColor: '#dc3545', reverseButtons: true }).then((result) => { if (result.isConfirmed) { $wire.deleteRoute({{ $route->id }}) } })"
                        wire:loading.attr="disabled"
                        wire:target="deleteRoute({{ $route->id }})"
                        title="Șterge traseul"
                        aria-label="Șterge traseul {{ $route->name ?: 'fără nume' }}"
                    >
                        <span wire:loading.remove wire:target="deleteRoute({{ $route->id }})"><x-trasee-icon name="trash" /></span>
                        <span wire:loading wire:target="deleteRoute({{ $route->id }})" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    </button>
                </span>
            </div>
        @empty
            <div class="trasee-empty">
                <x-trasee-icon name="map" />
                <p class="mb-1">Nu ai încă trasee.</p>
                <small class="text-muted">Importă un GPX, KML sau KMZ pentru a începe.</small>
            </div>
        @endforelse
    </div>
</section>
