<div class="modal fade transit-lines-modal" id="trainStatusModal" tabindex="-1" aria-labelledby="trainStatusModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header transit-modal-drag-handle" title="{{ __('Drag here to move the window') }}">
                <h5 class="modal-title" id="trainStatusModalTitle">{{ __('Transit lines') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body transit-lines-modal-body">
                <div id="transitLinesList" class="d-grid gap-2"></div>
                <p class="poi-route-info mt-3 mb-0" id="metroArrivalEstimate" aria-live="polite" hidden></p>
                <p class="poi-route-info mt-3 mb-0" id="transitLinesEmpty" hidden></p>
                <div class="border-top mt-3 pt-3" id="trainStatusLiveInfo" hidden>
                    <p class="mb-2">
                        {{ __('For train departures, arrivals and live status, see the official Infofer board.') }}
                    </p>
                    <p class="text-muted small mb-2" id="trainStatusCheckedAt"></p>
                    <a
                        class="btn btn-primary"
                        id="trainStatusOfficialLink"
                        href="https://mersultrenurilor.infofer.ro/ro-RO/Stations"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {{ __('Open live departures and arrivals') }}
                    </a>
                </div>
            </div>
            <div class="modal-footer">
                <span class="text-muted small me-auto" id="transitLinesSource">{{ __('Lines from OpenStreetMap.') }}</span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                <button
                    type="button"
                    class="transit-modal-resize-handle"
                    id="transitModalResizeHandle"
                    role="slider"
                    aria-orientation="vertical"
                    aria-valuemin="180"
                    aria-label="{{ __('Resize the window height') }}"
                    title="{{ __('Drag to change the window height') }}"
                >
                    <span aria-hidden="true">↕</span>
                </button>
            </div>
        </div>
    </div>
</div>
