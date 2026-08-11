<form id="route-file-import-form" class="trasee-import-form">
    <div class="row g-3">
        <div class="col-md-8">
            <label for="route-file" class="form-label">Fișier traseu</label>
            <input id="route-file" type="file" class="form-control @error('file') is-invalid @enderror" accept=".gpx,.kml,.kmz,.geojson,.json,.csv">
            @error('file') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col-md-4">
            <label for="route-name" class="form-label">Nume traseu <span class="text-danger">*</span></label>
            <input id="route-name" type="text" class="form-control @error('name') is-invalid @enderror" wire:model="name" maxlength="255" placeholder="Ex. Brașov - Poiana" required>
            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="d-flex align-items-center gap-3 mt-3">
        <button id="route-file-import-submit" type="submit" class="btn btn-primary">
            <span class="route-file-import-label"><x-trasee-icon name="upload" class="me-1" /> Importă traseul</span>
        </button>
        <span id="route-file-import-status" class="text-muted small">Completează numele traseului și selectează fișierul, apoi apasă „Importă traseul”.</span>
    </div>
</form>
