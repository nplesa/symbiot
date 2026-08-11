<section class="trasee-panel">
    <div class="trasee-panel__header">
        <div>
            <h2 class="h5 mb-1">Importă un traseu</h2>
            <p class="text-muted small mb-0">Poți încărca un fișier GPX/KML/KMZ sau poți lipi direct un link copiat din Google Maps.</p>
        </div>
    </div>

    @include('livewire.trasee.partials.google-import')

    <div class="trasee-import-divider"><span>SAU IMPORTĂ FIȘIER</span></div>

    @include('livewire.trasee.partials.file-import')
</section>
