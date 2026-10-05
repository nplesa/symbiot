<div class="modal fade" id="fuelPricesModal" tabindex="-1" aria-labelledby="fuelPricesModalTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="fuelPricesModalTitle">Prețuri carburanți</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <dl class="mb-3">
                    <dt>Rețea</dt>
                    <dd id="fuelPricesBrand" class="mb-2"></dd>
                    <dt>Adresă</dt>
                    <dd id="fuelPricesStation" class="mb-0"></dd>
                </dl>
                <p class="text-muted" id="fuelPricesStatus" role="status"></p>
                <h6 id="fuelPricesFuelsHeading" hidden>Carburanți și prețuri</h6>
                <table class="table table-sm align-middle mb-3" id="fuelPricesTable" hidden>
                    <thead>
                        <tr><th>Carburant</th><th class="text-end">Preț / litru</th></tr>
                    </thead>
                    <tbody id="fuelPricesBody"></tbody>
                </table>
                <h6 id="fuelPricesServicesHeading" hidden>Servicii oferite</h6>
                <ul class="list-inline mb-2" id="fuelPricesServices" hidden></ul>
                <small class="d-block text-muted mb-2" id="fuelPricesServicesNote" hidden>Monitorul Prețurilor nu publică prețuri pentru servicii, doar lista lor.</small>
                <small class="text-muted" id="fuelPricesSource" hidden></small>
            </div>
            <div class="modal-footer">
                <a class="btn btn-primary" id="fuelPricesNavigate" href="#" target="_blank" rel="noopener noreferrer" hidden>Navighează</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Închide</button>
            </div>
        </div>
    </div>
</div>