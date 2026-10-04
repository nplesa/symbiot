<div class="modal fade lodging-details-modal" id="lodgingDetailsModal" tabindex="-1" aria-labelledby="lodgingDetailsModalTitle" aria-describedby="lodgingDetailsEmpty">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lodgingDetailsModalTitle">Detalii cazare</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
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
                    Deschide pe hartă
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Închide</button>
            </div>
        </div>
    </div>
</div>
