<div class="modal fade transit-lines-modal" id="trainStatusModal" tabindex="-1" aria-labelledby="trainStatusModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header transit-modal-drag-handle" title="Trage de aici pentru a muta modalul">
                <h5 class="modal-title" id="trainStatusModalTitle">Linii de transport</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body transit-lines-modal-body">
                <div id="transitLinesList" class="d-grid gap-2"></div>
                <p class="poi-route-info mt-3 mb-0" id="metroArrivalEstimate" aria-live="polite" hidden></p>
                <p class="poi-route-info mt-3 mb-0" id="transitLinesEmpty" hidden></p>
                <div class="border-top mt-3 pt-3" id="trainStatusLiveInfo" hidden>
                    <p class="mb-2">
                        Pentru plecările/sosirile și situația operativă a trenurilor, consultă tabla oficială Infofer.
                    </p>
                    <p class="text-muted small mb-2" id="trainStatusCheckedAt"></p>
                    <a
                        class="btn btn-primary"
                        id="trainStatusOfficialLink"
                        href="https://mersultrenurilor.infofer.ro/ro-RO/Stations"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Deschide plecările și sosirile live
                    </a>
                </div>
            </div>
            <div class="modal-footer">
                <span class="text-muted small me-auto" id="transitLinesSource">Linii din OpenStreetMap.</span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Închide</button>
                <button
                    type="button"
                    class="transit-modal-resize-handle"
                    id="transitModalResizeHandle"
                    role="slider"
                    aria-orientation="vertical"
                    aria-valuemin="180"
                    aria-label="Redimensionează modalul pe înălțime"
                    title="Trage pentru a modifica înălțimea modalului"
                >
                    <span aria-hidden="true">↕</span>
                </button>
            </div>
        </div>
    </div>
</div>
