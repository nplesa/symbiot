/**
 * "Activate my location" (#toggleLocation): the device position as the location, followed with
 * watchPosition: the user marker and its direction of travel, the search circle, and the position
 * sent to the server while tracking.
 */
import { circular } from 'ol/geom/Polygon.js';
import { fromLonLat } from 'ol/proj.js';
import { fetchJson } from '../http.js';
import { intlLocale, t } from '../i18n.js';
import { createInputLocationElement } from './location-element.js';
import { approximateDistanceMeters, bearingBetween } from './poi-helpers.js';
import { mapState } from './state.js';
import { watchTransitCoverage } from './transit-coverage.js';

const WATCH_OPTIONS = {
    enableHighAccuracy: true,
    timeout: 15000,
    maximumAge: 0
};

/** Binds the device location switch; page: the map, its styles and the page functions it needs. */
export function bindDeviceLocation(page) {
    const { map, transitStops, userStyle, userArrowStyle, csrfToken, deviceRadiusMeters, HEADING_MIN_MOVE_METERS, syncDashboardRadius, resetPOIs, scheduleMapSizeUpdate, initLocalFeatures, loadNearby, selectedPoiFilterTokens } = page;

    const clearDeviceWatch = () => {
        if (mapState.watchId !== null) {
            navigator.geolocation.clearWatch(mapState.watchId);
            mapState.watchId = null;
        }
    };

    /** The switch was turned off: stop following the device. */
    const stopDeviceLocation = () => {
        clearDeviceWatch();

        mapState.tracking = false;
        mapState.lastPosition = null;
        mapState.heading = null;
        mapState.currentUserLocation = null;
        document.getElementById('dashboard-share-location-wrap')?.classList.add('d-none');
    };

    /**
     * GPS mode before the first position: clean POIs, the device radius, the panels and the hidden
     * #current_location input. Returns the elements the next steps update.
     */
    const enterGpsMode = () => {
        window.dispatchEvent(new CustomEvent('symbiot:location-changing', { detail: { mode: 'gps' } }));
        document.getElementById('dashboard-share-location-wrap')?.classList.add('d-none');

        // Switching from a city to GPS starts with a clean set of map filters and POIs.
        resetPOIs();

        // Oprește orice urmărire GPS precedentă.
        clearDeviceWatch();

        mapState.tracking = true;
        mapState.lastPosition = null;
        mapState.heading = null;
        mapState.fixedCityLocation = null;

        mapState.app_radius = deviceRadiusMeters;

        const mapCard = document.getElementById('map_card');
        const mobilityCard = document.getElementById('mobility_card');
        const status = document.getElementById('city-location-status');

        document.getElementById('city-location-mode').checked = false;
        document.getElementById('city-location-form')?.classList.add('d-none');

        mapCard?.classList.remove('d-none');
        mobilityCard?.classList.remove('d-none');

        mobilityCard?.querySelector('.row')?.classList.remove('mt-3');

        document.getElementById('i_location')?.classList.add('rotate3d-y');

        createInputLocationElement(true);

        const currentLocation = document.getElementById('current_location');

        if (status) {
            status.textContent = t('Determining the GPS position...');
            status.className = 'col-12 small text-muted';
        }

        setTimeout(scheduleMapSizeUpdate, 100);

        return { mapCard, mobilityCard, status, currentLocation };
    };

    /** Înregistrează activarea locației pe server. */
    const registerLocationOn = async () => {
        try {
            const response = await fetch('/location/toggle', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ location: true })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
        } catch (error) {
            console.error('Location activation failed:', error);
        }
    };

    /** The first position sets up the whole area: location, marker, search circle, transit and POIs. */
    const initializeGpsArea = async ({ mapCard, mobilityCard, status, currentLocation }, lat, lon) => {
        const radiusMeters = Number(deviceRadiusMeters);

        if (!Number.isFinite(radiusMeters) || radiusMeters <= 0) {
            throw new Error(t('The configured GPS radius is not valid.'));
        }

        // Salvează coordonatele GPS reale.
        currentLocation.dataset.lat = String(lat);
        currentLocation.dataset.lon = String(lon);

        mapState.currentUserLocation = { lat, lon };
        mapState.fixedCityLocation = null;
        mapState.app_radius = radiusMeters;
        mapState.cityRadiusMeters = radiusMeters;

        // Inițializează markerul și geometria locală.
        initLocalFeatures();

        const center = fromLonLat([lon, lat]);

        mapState.userFeature.getGeometry().setCoordinates(center);
        mapState.trackFeature.getGeometry().setCoordinates([]);

        // Desenează cercul exact la raza configurată.
        mapState.radiusFeature.setGeometry(
            circular([lon, lat], radiusMeters, 96)
                .transform('EPSG:4326', 'EPSG:3857')
        );

        map.getView().animate({
            center: center,
            zoom: 16,
            duration: 500
        });

        mapCard?.classList.remove('d-none');
        window.dispatchEvent(new CustomEvent('symbiot:location-set', {
            detail: { label: t('GPS location'), lat, lon },
        }));
        syncDashboardRadius(mapState.app_radius, true);

        setTimeout(scheduleMapSizeUpdate, 100);

        document.getElementById('dashboard-share-location-wrap')?.classList.remove('d-none');
        document.getElementById('turismLocations')?.classList.remove('d-none');

        if (status) {
            status.textContent =
                t('GPS location enabled. Radius: :radius m.', { radius: radiusMeters.toLocaleString(intlLocale) });
            status.className = 'col-12 small text-success';
        }

        // Inițializează acoperirea de transport și folosește
        // fluxul GTFS deja implementat în aplicație.
        watchTransitCoverage(
            lat,
            lon,
            status,
            async () => {
                const selectedFilters = selectedPoiFilterTokens(mobilityCard);

                if (!mobilityCard?.classList.contains('d-none')) {
                    mobilityCard?.classList.remove('d-none');
                }
                window.dispatchEvent(new CustomEvent('symbiot:poi-categories-ready'));

                if (selectedFilters.includes('bus')) {
                    try {
                        await transitStops.load(
                            lat,
                            lon,
                            Math.min(radiusMeters, 35000)
                        );

                        transitStops.watchVehicles(lat, lon);
                    } catch (error) {
                        console.error(
                            'GTFS transit stops could not be loaded:',
                            error
                        );
                    }
                }

                syncDashboardRadius(mapState.app_radius, true);
            },
            Math.min(radiusMeters, 35000)
        );

        // Încarcă POI-urile pentru toate filtrele selectate.
        if (selectedPoiFilterTokens(mobilityCard).length > 0) {
            await loadNearby({ lat, lon });
        }
    };

    /** Direction of travel: the GPS heading while moving, else the bearing from the last position. */
    const updateHeading = (position, lat, lon) => {
        const gpsHeading = position.coords.heading;
        const gpsSpeed = position.coords.speed;
        if (Number.isFinite(gpsHeading) && Number.isFinite(gpsSpeed) && gpsSpeed > 0) {
            mapState.heading = gpsHeading * Math.PI / 180;
            mapState.lastPosition = { lat, lon };
        } else if (!mapState.lastPosition) {
            mapState.lastPosition = { lat, lon };
        } else if (approximateDistanceMeters(mapState.lastPosition, { lat, lon }) >= HEADING_MIN_MOVE_METERS) {
            // Keep the previous heading for GPS jitter below the threshold.
            mapState.heading = bearingBetween(mapState.lastPosition, { lat, lon });
            mapState.lastPosition = { lat, lon };
        }
    };

    /** Moves the user marker (arrow once a direction is known), the search circle and the travelled track. */
    const drawPosition = (lat, lon) => {
        const currentCenter = fromLonLat([lon, lat]);

        requestAnimationFrame(() => {
            mapState.userFeature.getGeometry().setCoordinates(currentCenter);

            if (mapState.radiusFeature) {
                mapState.radiusFeature.setGeometry(
                    circular(
                        [lon, lat],
                        Number(mapState.app_radius),
                        96
                    ).transform('EPSG:4326', 'EPSG:3857')
                );
            }

            if (mapState.heading === null) {
                mapState.userFeature.setStyle(userStyle);
            } else {
                userArrowStyle.getImage().setRotation(mapState.heading);
                mapState.userFeature.setStyle(userArrowStyle);
                mapState.userFeature.changed();
            }

            const geometry = mapState.trackFeature.getGeometry();
            geometry.appendCoordinate(currentCenter);

            const coordinates = geometry.getCoordinates();

            if (coordinates.length > 1000) {
                coordinates.shift();
                geometry.setCoordinates(coordinates);
            }
        });
    };

    /** Sends the position to the server, at most every 5 seconds. */
    const sendPosition = (lat, lon) => {
        const now = Date.now();

        if (now - mapState.lastSent > 5000) {
            mapState.lastSent = now;

            fetchJson('/location/update', {
                method: 'POST',
                body: JSON.stringify({
                    lat,
                    lon,
                    active: true,
                    // The API expects degrees in 0..360.
                    heading: mapState.heading === null
                        ? null
                        : ((mapState.heading * 180 / Math.PI) + 360) % 360
                })
            }).catch(error => {
                console.error('Location update failed:', error);
            });
        }
    };

    const showGpsError = (status, error) => {
        console.error('GPS error:', error);

        if (status) {
            status.textContent =
                error.code === 1
                    ? t('Location access was denied.')
                    : error.code === 2
                        ? t('The GPS position is not available.')
                        : t('Determining the GPS position timed out.');
            status.className = 'col-12 small text-danger';
        }
    };

    document
        .getElementById('toggleLocation')
        ?.addEventListener('change', async function () {
            const toggle = this;

            if (!toggle.checked) {
                stopDeviceLocation();
                return;
            }

            if (!navigator.geolocation) {
                toggle.checked = false;

                await Swal.fire({
                    icon: 'error',
                    title: t('GPS unavailable'),
                    text: t('The browser does not support geolocation.')
                });

                return;
            }

            const ui = enterGpsMode();
            await registerLocationOn();

            // Set before the first position's setup starts, so a second position does not set it up again.
            let initialized = false;

            mapState.watchId = navigator.geolocation.watchPosition(
                async position => {
                    if (!mapState.tracking || !toggle.checked) return;

                    const lon = position.coords.longitude;
                    const lat = position.coords.latitude;

                    if (
                        !Number.isFinite(lat) ||
                        !Number.isFinite(lon) ||
                        lat < -90 || lat > 90 ||
                        lon < -180 || lon > 180
                    ) {
                        return;
                    }

                    mapState.currentUserLocation = { lat, lon };
                    // Live vehicles and transit stops follow the user (GPS mode only; this watch is cleared in city mode).
                    transitStops.moveVehicleCenter(lat, lon);
                    transitStops.followUser(lat, lon);

                    updateHeading(position, lat, lon);

                    ui.currentLocation.dataset.lat = String(lat);
                    ui.currentLocation.dataset.lon = String(lon);

                    drawPosition(lat, lon);

                    // Prima poziție inițializează întreaga zonă.
                    if (!initialized) {
                        initialized = true;
                        try {
                            await initializeGpsArea(ui, lat, lon);
                        } catch (error) {
                            initialized = false;
                            console.error('GPS area initialization failed:', error);

                            if (ui.status) {
                                ui.status.textContent = error.message;
                                ui.status.className = 'col-12 small text-danger';
                            }
                        }
                    }

                    sendPosition(lat, lon);
                },
                error => {
                    showGpsError(ui.status, error);

                    if (!initialized) {
                        mapState.tracking = false;
                        toggle.checked = false;
                        clearDeviceWatch();
                    }
                },
                WATCH_OPTIONS
            );
        });
}
