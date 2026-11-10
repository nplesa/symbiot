<div class="trasee-google-import" data-google-link-import="geoapify-client-v15-list-refresh">
    <div class="trasee-google-import__title">
        <div class="trasee-google-import__icon"><x-trasee-icon name="google" /></div>
        <div>
            <h3 class="h6 mb-1">{{ __('Import route from Google Maps link') }} <span class="badge text-bg-primary ms-1">Geoapify</span></h3>
            <p class="text-muted small mb-0">{{ __('Copy the route link from Google Maps and paste it below. The route is calculated and optimized with Geoapify.') }}</p>
        </div>
    </div>
    <form id="google-maps-import-form" class="trasee-import-form p-0 mt-3">
        <div class="input-group input-group-lg">
            <input
                id="google-maps-url"
                type="text"
                class="form-control @error('googleMapsUrl') is-invalid @enderror"
                maxlength="2048"
                placeholder="{{ __('Paste here the link copied from Google Maps (Ctrl+V)') }}"
                autocomplete="off"
                spellcheck="false"
            >
            <button type="button" class="btn btn-outline-secondary" id="paste-google-maps-link" title="{{ __('Paste link from clipboard') }}">
                <x-trasee-icon name="clipboard" class="me-1" /> {{ __('Paste') }}
            </button>
            <button type="submit" class="btn btn-primary" id="generate-google-maps-kml-submit">
                <x-trasee-icon name="download" class="me-1" /> {{ __('Generate and download KML') }}
            </button>
        </div>
        @error('googleMapsUrl') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        <div class="form-text mt-2"><strong>{{ __('Step 1:') }}</strong> {{ __('copy the link from Google Maps.') }} <strong>{{ __('Step 2:') }}</strong> {{ __('paste it here.') }} <strong>{{ __('Step 3:') }}</strong> {{ __('click "Generate and download KML". Route calculation is done with Geoapify; no Google key is used.') }}</div>
        <div id="google-maps-import-status" class="form-text mt-2 text-muted"></div>
    </form>
</div>