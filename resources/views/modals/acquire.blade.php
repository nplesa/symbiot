<div class="modal fade" id="poiLoadingModal" tabindex="-1" aria-labelledby="poiLoadingModalTitle" aria-describedby="poiLoadingModalDescription" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4">

            <div class="modal-body">

                <h5 class="fw-bold" id="poiLoadingModalTitle">{{ __('ACQUIRING DATA') }}</h5>

                <p class="text-muted mb-0" id="poiLoadingModalDescription">
                    {{ __('Fetching nearby points of interest...') }}
                </p>
                <div class="progress mt-3" role="progressbar" aria-label="{{ __('Loading points of interest') }}">
                    <div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div>
                </div>
                <p class="text-muted small mt-2 mb-0" id="poi-loading-elapsed" aria-live="polite">{{ __('Elapsed time') }}: 00:00</p>
                {{-- The backdrop is static, so this is the way out of a slow or stuck load. --}}
                <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="poiLoadingCancel">{{ __('Cancel') }}</button>

            </div>

        </div>
    </div>
</div>
