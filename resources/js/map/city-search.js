/**
 * "Add City": searching a locality, picking one of the results, its search radius, and the "whole
 * city" mode with the city boundary. Sets the location the POIs, weather and transit work with.
 */
import { circular } from 'ol/geom/Polygon.js';
import { fromLonLat } from 'ol/proj.js';
import { fetchJson } from '../http.js';
import { formatNumber, intlLocale, t } from '../i18n.js';
import { createInputLocationElement } from './location-element.js';
import { hidePoiTooltip } from './poi-tooltip.js';
import { clearAvailabilityChecking, markAvailabilityChecking } from './poi-loading.js';
import { mapState } from './state.js';
import { watchTransitCoverage } from './transit-coverage.js';
import { clearVisibleTransitRoutes } from './transit-routes.js';

/** Binds the city search controls; page: the map, its sources and the page functions it needs. */
export function bindCitySearch(page) {
    const { map, poiSource, cityBoundarySource, transitStops, dashboardCitywide, syncDashboardRadius, showBestGasPriceButton, resetPOIs, scheduleMapSizeUpdate, initLocalFeatures, loadNearby, selectedPoiFilterTokens } = page;

    document.getElementById('city-location-mode')?.addEventListener('change', async event => {
        if (!event.target.checked) return;

        document.getElementById('dashboard-share-location-wrap')?.classList.add('d-none');
        window.dispatchEvent(new CustomEvent('symbiot:location-changing', { detail: { mode: 'city' } }));
        resetPOIs();

        if (mapState.watchId !== null) {
            navigator.geolocation.clearWatch(mapState.watchId);
            mapState.watchId = null;
        }
        mapState.tracking = false;
        mapState.currentUserLocation = null;
        document.getElementById('toggleLocation').checked = false;
        document.getElementById('city-location-form')?.classList.remove('d-none');
        mapState.poiRequestId++;
        poiSource.clear();
        clearVisibleTransitRoutes();
        hidePoiTooltip();
        // The map stays visible (already cleared above) while a city is searched; the POI
        // filters are hidden because they need a location.
        document.getElementById('mobility_card')?.classList.add('d-none');

        try {
            await fetchJson('/location/toggle', {
                method: 'POST',
                body: JSON.stringify({ location: false }),
            });
        } catch (error) {
            console.error('Turning off device location failed:', error);
            const status = document.getElementById('city-location-status');
            if (status) {
                status.textContent = t('Could not turn off the device location: :error', { error: error.message });
                status.className = 'col-12 small text-warning';
            }
        }
    });

    document.getElementById('city-location-form')?.addEventListener('submit', async event => {
        event.preventDefault();

        let mbc = document.getElementById('mobility_card');
        if(!mbc?.classList.contains('d-none')) {
            mbc?.classList.add('d-none');
        }

        const input = document.getElementById('city-location-input');
        const radiusInput = document.getElementById('city-location-radius');
        const button = document.getElementById('city-location-submit');
        const status = document.getElementById('city-location-status');
        const resultsContainer = document.getElementById('city-location-results-container');
        const resultsSelect = document.getElementById('city-location-results');
        const city = input.value.trim();
        const radiusMeters = Number(radiusInput.value);
        if (city.length < 2) {
            status.textContent = t('Enter the name of a city or locality.');
            status.className = 'col-12 small text-danger';
            return;
        }
        if (!Number.isInteger(radiusMeters) || radiusMeters < 100 || radiusMeters > 35000) {
            status.textContent = t('Radius must be between :min and :max metres.', { min: formatNumber(100), max: formatNumber(35000) });
            status.className = 'col-12 small text-danger';
            radiusInput.focus();
            return;
        }

        let result = null;
        if (mapState.pendingCityResults.length > 0) {
            const selectedIndex = Number(resultsSelect.value);
            if (resultsSelect.value === '' || !mapState.pendingCityResults[selectedIndex]) {
                status.textContent = t('Select the correct city from the list.');
                status.className = 'col-12 small text-danger';
                resultsSelect.focus();
                return;
            }

            result = mapState.pendingCityResults[selectedIndex];
        }

        window.dispatchEvent(new CustomEvent('symbiot:location-changing', { detail: { mode: 'city' } }));
        // The categories are dimmed until the new area is checked (or the search ends without a location).
        markAvailabilityChecking();
        resetPOIs();
        button.disabled = true;
        status.textContent = result ? t('Setting location...') : t('Searching for the city...');
        status.className = 'col-12 small text-muted';

        try {
            if (!result) {
                const params = new URLSearchParams({ city });
                const payload = await fetchJson(`/api/location/city?${params.toString()}`);

                const candidates = Array.isArray(payload.results)
                    ? payload.results.filter(candidate => Number.isFinite(Number(candidate.lat))
                        && Number.isFinite(Number(candidate.lon))
                        && Number(candidate.lat) >= -90
                        && Number(candidate.lat) <= 90
                        && Number(candidate.lon) >= -180
                        && Number(candidate.lon) <= 180)
                    : [];
                if (candidates.length === 0) {
                    throw new Error(t('City not found. Try adding the country as well.'));
                }
                if (candidates.length > 1) {
                    mapState.pendingCityResults = candidates;
                    resultsSelect.replaceChildren(new Option(t('Select a city'), ''));
                    candidates.forEach((candidate, index) => {
                        resultsSelect.add(new Option(candidate.label || candidate.name || city, String(index)));
                    });
                    resultsSelect.value = '';
                    resultsSelect.disabled = false;
                    resultsContainer.classList.remove('d-none');
                    status.textContent = t('Found :count results. Select the city and country, then set the location.', { count: candidates.length });
                    status.className = 'col-12 small text-primary';
                    window.dispatchEvent(new CustomEvent('symbiot:location-candidates', {
                        detail: { candidates: candidates.map((candidate, index) => ({
                            label: candidate.label || candidate.name || city,
                            index,
                        })) },
                    }));
                    // Waiting for the choice: nothing is being checked meanwhile.
                    clearAvailabilityChecking();
                    return;
                }

                result = candidates[0];
            }

            resetPOIs();
            showBestGasPriceButton(result.country_code);

            mapState.pendingCityResults = [];
            resultsSelect.disabled = true;
            resultsContainer.classList.add('d-none');
            result.lat = Number(result.lat);
            result.lon = Number(result.lon);
            createInputLocationElement(true);
            const currentLocation = document.getElementById('current_location');
            currentLocation.dataset.lat = result.lat;
            currentLocation.dataset.lon = result.lon;
            mapState.cityRadiusMeters = radiusMeters;
            mapState.app_radius = mapState.cityRadiusMeters;
            mapState.citywideSearch = false;
            mapState.cityBoundaryFeature = null;
            mapState.cityBoundaryRadiusMeters = null;
            dashboardCitywide && (dashboardCitywide.checked = false);
            cityBoundarySource.clear();
            mapState.fixedCityLocation = { lat: result.lat, lon: result.lon, placeId: result.place_id || null, name: result.name || null };
            mapState.currentUserLocation = { lat: result.lat, lon: result.lon };
            initLocalFeatures();

            const center = fromLonLat([result.lon, result.lat]);
            mapState.userFeature.getGeometry().setCoordinates(center);
            mapState.trackFeature.getGeometry().setCoordinates([]);
            mapState.radiusFeature.setGeometry(
                circular([result.lon, result.lat], Number(mapState.app_radius), 64)
                    .transform('EPSG:4326', 'EPSG:3857')
            );
            map.getView().setCenter(center);
            map.getView().setZoom(13);
            document.getElementById('map_card')?.classList.remove('d-none');

            status.textContent = t('Location has been set to :place, with a radius of :radius m.', {
                place: result.label || result.name,
                radius: mapState.cityRadiusMeters.toLocaleString(intlLocale),
            });
            status.className = 'col-12 small text-success';
            window.dispatchEvent(new CustomEvent('symbiot:location-set', {
                detail: {
                    label: result.label || result.name || city,
                    lat: result.lat,
                    lon: result.lon,
                    deferPoiCategories: true,
                },
            }));
            syncDashboardRadius(mapState.app_radius, true);
            setTimeout(scheduleMapSizeUpdate, 100);

            mbc?.classList.add('d-none');
            watchTransitCoverage(result.lat, result.lon, status, async () => {
                const selectedFilters = selectedPoiFilterTokens(
                    document.getElementById('mobility_card')
                );

                syncDashboardRadius(mapState.app_radius, true);
                if (selectedFilters.includes('bus')) {
                    try {
                        await transitStops.load(
                            result.lat,
                            result.lon,
                            Math.min(Number(mapState.app_radius) || 1500, 35000)
                        );
                        transitStops.watchVehicles(result.lat, result.lon);
                    } catch (error) {
                        console.error('Transit stops load failed:', error);
                    }
                }

                mbc?.classList.remove('d-none');
                window.dispatchEvent(new CustomEvent('symbiot:poi-categories-ready'));
                if (selectedFilters.length > 0) {
                    await loadNearby({ lat: result.lat, lon: result.lon });
                }
            }, Math.min(Number(mapState.app_radius) || 1500, 35000));
        } catch (error) {
            clearAvailabilityChecking();
            status.textContent = error.message;
            status.className = 'col-12 small text-danger';
            window.dispatchEvent(new CustomEvent('symbiot:location-error', {
                detail: { message: error.message },
            }));
        } finally {
            button.disabled = false;
        }
    });

    document.getElementById('city-location-input')?.addEventListener('input', () => {
        mapState.pendingCityResults = [];
        const resultsContainer = document.getElementById('city-location-results-container');
        const resultsSelect = document.getElementById('city-location-results');
        resultsSelect.disabled = true;
        resultsSelect.replaceChildren(new Option(t('Choose locality'), ''));
        resultsContainer.classList.add('d-none');
    });

    document.getElementById('city-location-results')?.addEventListener('change', event => {
        const status = document.getElementById('city-location-status');
        if (event.target.value !== '') {
            status.textContent = t('Press "Set Location" to confirm your choice.');
            status.className = 'col-12 small text-muted';
        }
    });

    document.getElementById('city-location-radius')?.addEventListener('change', event => {
        const radiusMeters = Number(event.target.value);
        const status = document.getElementById('city-location-status');
        if (!Number.isInteger(radiusMeters) || radiusMeters < 100 || radiusMeters > 35000) {
            status.textContent = t('Radius must be between :min and :max metres.', { min: formatNumber(100), max: formatNumber(35000) });
            status.className = 'col-12 small text-danger';
            return;
        }

        mapState.cityRadiusMeters = radiusMeters;
        mapState.app_radius = mapState.cityRadiusMeters;
        syncDashboardRadius(radiusMeters, true);
        if (mapState.fixedCityLocation && mapState.radiusFeature) {
            mapState.radiusFeature.setGeometry(
                circular(
                    [mapState.fixedCityLocation.lon, mapState.fixedCityLocation.lat],
                    mapState.cityRadiusMeters,
                    64
                ).transform('EPSG:4326', 'EPSG:3857')
            );
            status.textContent = t('Search radius: :radius m.', { radius: mapState.cityRadiusMeters.toLocaleString(intlLocale) });
            status.className = 'col-12 small text-success';
            if (selectedPoiFilterTokens(document.getElementById('mobility_card')).length > 0) {
                loadNearby(mapState.fixedCityLocation);
            }
        }
    });
}
