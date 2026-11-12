<div class="modal fade lodging-details-modal" id="lodgingDetailsModal" tabindex="-1" aria-labelledby="lodgingDetailsModalTitle" aria-describedby="lodgingDetailsEmpty">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lodgingDetailsModalTitle">{{ __('Lodging details') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <dl class="lodging-details-list" id="lodgingDetailsList"></dl>
                <p class="text-muted mb-0" id="lodgingDetailsEmpty" hidden></p>
            </div>
            <div class="modal-footer">
                <a
                    class="btn btn-outline-primary"
                    id="lodgingDetailsMapLink"
                    href="#"
                    target="_blank"
                    rel="noopener noreferrer"
                    hidden
                >
                    {{ __('Open on the map') }}
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>
