<div class="modal fade" id="fuelBestModal" tabindex="-1" aria-labelledby="fuelBestModalTitle">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="fuelBestModalTitle">{{ __('Best fuel price') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="fuelBestSelect">{{ __('Fuel') }}</label>
                <select class="form-select mb-3" id="fuelBestSelect">
                    @foreach (\App\Services\FuelPriceService::categories() as $id => $label)
                        <option value="{{ $id }}" @selected($id === '11')>{{ $label }}</option>
                    @endforeach
                </select>

                <section class="mb-4">
                    <h6 class="text-uppercase text-muted small mb-2">{{ __('In your area') }}</h6>
                    <p class="text-muted small" id="fuelBestStatus" role="status"></p>
                    <div id="fuelBestResult" hidden>
                        <h5 class="mb-1" id="fuelBestChain"></h5>
                        <p class="mb-1"><span class="fs-4 fw-semibold" id="fuelBestPrice"></span> <span class="text-muted small" id="fuelBestMeta"></span></p>
                        <p class="mb-2 text-muted small" id="fuelBestStation"></p>
                        <a class="btn btn-primary btn-sm mb-3" id="fuelBestNavigate" href="#" target="_blank" rel="noopener noreferrer" hidden>{{ __('Navigate to the nearest one') }}</a>
                        <h6 class="small">{{ __('Ranking in the area') }}</h6>
                        <ol class="small mb-0" id="fuelBestRanking"></ol>
                    </div>
                </section>

                <section id="fuelBestCountrySection" hidden>
                    <h6 class="text-uppercase text-muted small mb-2">{{ __('Best in the country') }}</h6>
                    <p class="text-muted small" id="fuelBestCountryStatus" role="status"></p>
                    <div id="fuelBestCountryResult" hidden>
                        <h5 class="mb-1" id="fuelBestCountryChain"></h5>
                        <p class="mb-1"><span class="fs-4 fw-semibold" id="fuelBestCountryPrice"></span> <span class="text-muted small" id="fuelBestCountryMeta"></span></p>
                        <p class="mb-2 text-muted small" id="fuelBestCountryStation"></p>
                        <a class="btn btn-outline-primary btn-sm" id="fuelBestCountryNavigate" href="#" target="_blank" rel="noopener noreferrer" hidden>{{ __('Navigate to the cheapest station') }}</a>
                    </div>
                </section>

                <small class="d-block text-muted mt-3" id="fuelBestSource" hidden></small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>
