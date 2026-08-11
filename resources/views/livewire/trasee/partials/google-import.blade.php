<div class="trasee-google-import" data-google-link-import="geoapify-client-v15-list-refresh">
    <div class="trasee-google-import__title">
        <div class="trasee-google-import__icon"><x-trasee-icon name="google" /></div>
        <div>
            <h3 class="h6 mb-1">Importă traseu din link Google Maps <span class="badge text-bg-primary ms-1">Geoapify</span></h3>
            <p class="text-muted small mb-0">Copiază din Google Maps linkul traseului și lipește-l mai jos. Traseul este calculat și optimizat cu Geoapify.</p>
        </div>
    </div>
    <form id="google-maps-import-form" class="trasee-import-form p-0 mt-3">
        <div class="input-group input-group-lg">
            <input
                id="google-maps-url"
                type="text"
                class="form-control @error('googleMapsUrl') is-invalid @enderror"
                maxlength="2048"
                placeholder="Lipește aici linkul copiat din Google Maps (Ctrl+V)"
                autocomplete="off"
                spellcheck="false"
            >
            <button type="button" class="btn btn-outline-secondary" id="paste-google-maps-link" title="Lipește linkul din clipboard">
                <x-trasee-icon name="clipboard" class="me-1" /> Lipește
            </button>
            <button type="submit" class="btn btn-primary" id="generate-google-maps-kml-submit">
                <x-trasee-icon name="download" class="me-1" /> Generează și descarcă KML
            </button>
        </div>
        @error('googleMapsUrl') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        <div class="form-text mt-2"><strong>Pasul 1:</strong> copiază linkul din Google Maps. <strong>Pasul 2:</strong> lipește-l aici. <strong>Pasul 3:</strong> apasă „Generează și descarcă KML”. Calcularea traseului se face cu <strong>Geoapify</strong>; nu este folosită nicio cheie Google.</div>
        <div id="google-maps-import-status" class="form-text mt-2 text-muted"></div>
    </form>
</div>
