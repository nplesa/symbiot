<section class="trasee-panel">
    <div class="trasee-panel__header">
        <div>
            <h2 class="h5 mb-1">{{ __('Import a route') }}</h2>
            <p class="text-muted small mb-0">{{ __('You can upload a GPX/KML/KMZ file or paste a link copied directly from Google Maps.') }}</p>
        </div>
    </div>

    @include('livewire.trasee.partials.google-import')

    <div class="trasee-import-divider"><span>{{ __('OR IMPORT FILE') }}</span></div>

    @include('livewire.trasee.partials.file-import')
</section>