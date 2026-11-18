import { notify } from '../notify.js';
import { formatNumber, intlLocale, t } from '../i18n.js';
import { haversineMeters } from '../geo.js';
import { paintLineBadge } from '../route-colors.js';
import { flightCard, gtfsMode, gtfsTickets, osmRouteCard } from '../trip-card.js';
import { createRailBoardView } from '../dashboard/rail-board-view.js';
import { createTripCardView } from '../dashboard/trip-card-view.js';
import { scrollPanelTo } from '../dashboard/panel-scroll.js';

/* =========================================================
   SYMBIOT GPS TRACKING
   ========================================================= */

const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.content;

const trackingSwitch = document.getElementById('trackingmyself');

let watchId = null;
let lastPosition = null;
let lastSavedTime = null;
let trackingStarted = false;

// In-flight POST /tracking/start, shared so stopTracking can wait for it.
let startPromise = null;

// GPS callbacks are async; handle one fix at a time so slow requests cannot duplicate points.
let positionBusy = false;

const options = {
    enableHighAccuracy: true,
    maximumAge: 5000,
    timeout: 10000
};


/* =========================================================
   GPS ERRORS
   ========================================================= */

function showError(error) {

    switch (error.code) {

        case error.PERMISSION_DENIED:
            notify(t('Location permission was denied.'));
            break;

        case error.POSITION_UNAVAILABLE:
            notify(t('The position is not available.'));
            break;

        case error.TIMEOUT:
            notify(t('Getting the position timed out.'));
            break;

        default:
            notify(t('Unknown error.'));
    }

}


/* =========================================================
   HTTP POST
   ========================================================= */

async function post(url, body = {}) {

    const response = await fetch(url, {

        method: 'POST',

        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json'
        },

        body: JSON.stringify(body)

    });

    if (!response.ok) {
        throw new Error(`HTTP error: ${response.status}`);
    }

    return response;

}


/* =========================================================
   SAVE POSITION
   ========================================================= */

async function savePosition(coords) {

    await post('/tracking/point', {

        latitude: coords.latitude,
        longitude: coords.longitude,
        accuracy: coords.accuracy,
        speed: coords.speed,
        heading: coords.heading,
        altitude: coords.altitude,
        tracked_at: new Date().toISOString()

    });

}


/* =========================================================
   POSITION UPDATE
   ========================================================= */

async function onPosition(position) {

    const coords = position.coords;

    if (coords.accuracy > 30) {
        return;
    }

    const now = Date.now();

    if (!lastPosition) {

        await savePosition(coords);

        lastPosition = coords;
        lastSavedTime = now;

        return;
    }

    const meters = haversineMeters(

        lastPosition.latitude,
        lastPosition.longitude,

        coords.latitude,
        coords.longitude

    );

    const seconds = (now - lastSavedTime) / 1000;

    if (meters < 15 && seconds < 15) {
        return;
    }

    await savePosition(coords);

    lastPosition = coords;
    lastSavedTime = now;

}


/* =========================================================
   TRACKING INDICATOR
   ========================================================= */

function setTrackingIndicator(active) {

    const icon = document.getElementById('i_tracking');

    if (!icon) return;

    icon.classList.toggle('rotate3d-y', active);

}


/* =========================================================
   START TRACKING
   ========================================================= */

async function startTracking() {

    if (watchId !== null) {
        return;
    }

    if (!navigator.geolocation) {

        notify(t('Geolocation is not supported.'));

        if (trackingSwitch) {
            trackingSwitch.checked = false;
        }

        return;
    }

    watchId = navigator.geolocation.watchPosition(

        async position => {

            if (positionBusy) {
                return;
            }

            positionBusy = true;

            try {

                if (!trackingStarted) {

                    startPromise ??= post('/tracking/start')
                        .then(() => {
                            trackingStarted = true;
                            setTrackingIndicator(true);
                        })
                        .finally(() => {
                            startPromise = null;
                        });

                    await startPromise;

                    // Tracking was switched off while the session was starting.
                    if (watchId === null) {
                        return;
                    }

                    await savePosition(position.coords);

                    lastPosition = position.coords;
                    lastSavedTime = Date.now();

                    return;
                }

                await onPosition(position);

            } catch (error) {

                console.error('Tracking error:', error);

            } finally {

                positionBusy = false;

            }

        },

        error => {

            // TIMEOUT / POSITION_UNAVAILABLE are transient (tunnels, indoors):
            // keep watching so tracking resumes when the signal returns.
            if (error.code !== error.PERMISSION_DENIED) {
                console.warn('GPS temporarily unavailable:', error.message);
                return;
            }

            showError(error);
            disableTracking();

        },

        options

    );

}


/* =========================================================
   DISABLE TRACKING (permission revoked)
   ========================================================= */

async function disableTracking() {

    if (trackingSwitch) {
        trackingSwitch.checked = false;
    }

    try {

        await post('/tracking/toggle', {
            trackingmyself: false
        });

    } catch (error) {

        console.error('Tracking toggle error:', error);

    }

    await stopTracking();

}


/* =========================================================
   STOP TRACKING
   ========================================================= */

async function stopTracking() {

    if (watchId !== null) {

        navigator.geolocation.clearWatch(watchId);

        watchId = null;

    }

    // Let a pending start finish first, otherwise its session would never be stopped.
    if (startPromise) {

        try {

            await startPromise;

        } catch {

            // The session was never started, so there is nothing to stop.

        }

    }

    if (trackingStarted) {

        try {

            await post('/tracking/stop');

        } catch (error) {

            console.error('Stop tracking error:', error);

        }

        trackingStarted = false;

        setTrackingIndicator(false);

    }

    lastPosition = null;
    lastSavedTime = null;

}


/* =========================================================
   TRACKING SWITCH
   ========================================================= */

trackingSwitch?.addEventListener('change', async function () {

    if (!navigator.geolocation) {

        notify(t('Geolocation is not supported.'));

        this.checked = false;

        return;
    }

    try {

        await post('/tracking/toggle', {
            trackingmyself: this.checked
        });

        if (this.checked) {

            await startTracking();

        } else {

            await stopTracking();

        }

    } catch (error) {

        console.error('Tracking toggle error:', error);

        this.checked = !this.checked;

        notify(t('Could not change the tracking state.'));

    }

});


/* =========================================================
   INITIAL TRACKING
   ========================================================= */

document.addEventListener('DOMContentLoaded', async () => {

    if (trackingSwitch?.checked) {
        await startTracking();
    }

});



/* =========================================================
   MAP-FIRST DASHBOARD
   ========================================================= */

document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('.dashboard-map-page');
    if (!page) return;

    const searchForm = document.getElementById('dashboard-search-form');
    const searchInput = document.getElementById('dashboard-search');
    const searchSelect = document.getElementById('dashboard-search-select');
    const searchResults = document.getElementById('dashboard-search-results');
    const searchStatus = document.getElementById('dashboard-search-status');
    const cityInput = document.getElementById('city-location-input');
    const cityForm = document.getElementById('city-location-form');
    const cityMode = document.getElementById('city-location-mode');
    const cityResults = document.getElementById('city-location-results');
    const routeEmpty = document.getElementById('dashboard-route-empty');
    const routeDetails = document.getElementById('dashboard-route-details');
    const routePanel = document.getElementById('dashboard-route-panel');
    const routePanelToggle = document.getElementById('dashboard-panel-toggle');
    const mobilePanelBackdrop = document.getElementById('dashboard-mobile-panel-backdrop');
    const mobilePanelOpen = document.getElementById('dashboard-mobile-panel-open');
    const stopRoutesPanel = document.getElementById('dashboard-stop-routes');
    const stopRoutesList = document.getElementById('dashboard-stop-routes-list');
    const trackButton = document.getElementById('dashboard-track-me');
    let selectedRoute = null;
    // Ticket links of the selected GTFS stop, shown in the card of the line picked there.
    let stopTickets = null;
    const { renderModeChip, renderItinerary, renderTickets, openTripCard, closeTripCard, tripRow, tripRowArrow } = createTripCardView({ stopRoutesPanel, routeDetails });

    let nearbyPois = [];
    let nearbyStops = [];

    const byId = (id) => document.getElementById(id);
    const setText = (id, value) => {
        const element = byId(id);
        if (element) element.textContent = value ?? '';
    };

    const routePanelMedia = window.matchMedia('(max-width: 767.98px)');
    const focusWithoutScroll = (element) => element?.focus({ preventScroll: true });
    const syncRoutePanelCollapse = (collapsed) => {
        if (!routePanelToggle) return;
        page.classList.toggle('right-panel-collapsed', collapsed);
        routePanelToggle.setAttribute('aria-expanded', String(!collapsed));
        const label = collapsed ? t('Expand right panel') : t('Collapse right panel');
        routePanelToggle.setAttribute('aria-label', label);
        routePanelToggle.title = label;
        routePanel?.setAttribute('aria-modal', String(routePanelMedia.matches && !collapsed));
        if (mobilePanelBackdrop) mobilePanelBackdrop.hidden = !routePanelMedia.matches || collapsed;
        if (mobilePanelOpen) mobilePanelOpen.setAttribute('aria-expanded', String(routePanelMedia.matches && !collapsed));
        requestAnimationFrame(() => window.dispatchEvent(new CustomEvent('symbiot:dashboard-panel-toggled', { detail: { collapsed } })));
    };

    const storedPanelState = localStorage.getItem('symbiot-route-panel-collapsed');
    syncRoutePanelCollapse(routePanelMedia.matches && storedPanelState === 'true');
    const setRoutePanelCollapsed = (collapsed) => {
        localStorage.setItem('symbiot-route-panel-collapsed', String(collapsed));
        syncRoutePanelCollapse(collapsed);
    };
    routePanelToggle?.addEventListener('click', () => {
        const collapsed = !page.classList.contains('right-panel-collapsed');
        setRoutePanelCollapsed(collapsed);
        if (collapsed && routePanelMedia.matches) focusWithoutScroll(mobilePanelOpen);
    });
    mobilePanelOpen?.addEventListener('click', () => {
        setRoutePanelCollapsed(false);
        focusWithoutScroll(routePanelToggle);
    });
    mobilePanelBackdrop?.addEventListener('click', () => {
        setRoutePanelCollapsed(true);
        focusWithoutScroll(mobilePanelOpen);
    });
    routePanelMedia.addEventListener('change', () => {
        syncRoutePanelCollapse(routePanelMedia.matches && localStorage.getItem('symbiot-route-panel-collapsed') === 'true');
    });

    const resetDashboardLocationState = (changing = false) => {
        searchResults.hidden = true;
        selectedRoute = null;
        stopRoutesPanel.hidden = true;
        stopRoutesList.replaceChildren();
        setText('dashboard-stop-routes-count', t('0 lines'));
        routeDetails.hidden = true;
        // A new location: nothing of the previous one stays (the details card, the POI counters).
        closeTripCard();
        setText('dashboard-panel-poi-count', '0 POI');
        setText('dashboard-poi-count', '0');
        routePanel.classList.remove('is-open', 'has-stop-selection');
        poiFiltersCard?.classList.add('d-none');
        routeEmpty.hidden = !changing;
        nearbyPois = [];
        nearbyStops = [];
        setText('dashboard-nearby-status', t('Choose the POI categories you want to see on the map.'));
        byId('dashboard-nearby-list')?.replaceChildren();
        setText('dashboard-nearby-count', '0');
    };

    const syncTrackButton = () => {
        const active = Boolean(trackingSwitch?.checked);
        trackButton?.classList.toggle('is-active', active);
        trackButton?.setAttribute('aria-pressed', String(active));
        trackButton?.setAttribute('title', active ? t('Stop GPS tracking') : t('Start GPS tracking'));
    };
    trackButton?.addEventListener('click', () => {
        if (!trackingSwitch) return;
        trackingSwitch.checked = !trackingSwitch.checked;
        trackingSwitch.dispatchEvent(new Event('change', { bubbles: true }));
        syncTrackButton();
    });
    trackingSwitch?.addEventListener('change', syncTrackButton);
    syncTrackButton();

    // POI categories are available once transit coverage (GTFS) lookup finishes.
    const poiFiltersCard = byId('mobility_card');
    const poiFiltersCollapse = byId('dashboard-poi-collapse');
    const setPoiAccordionOpen = (open) => {
        if (!poiFiltersCollapse || !window.bootstrap?.Collapse) return;
        window.bootstrap.Collapse.getOrCreateInstance(poiFiltersCollapse, { toggle: false })[open ? 'show' : 'hide']();
    };
    const closePoiAccordion = () => setPoiAccordionOpen(false);

    const revealPanelSection = (section) => {
        if (!section) return;
        const wasHidden = section.hidden;
        // On phones the panel may be closed: it opens on this selection and shows the section, not its top.
        const panelWasClosed = page.classList.contains('right-panel-collapsed');
        section.hidden = false;
        // Scroll only when the section appears, not on its loading -> loaded refresh.
        if (wasHidden || panelWasClosed) scrollPanelTo(section);
    };

    const formatDistance = (meters) => meters < 1000
        ? `${Math.round(meters)} m`
        : `${formatNumber(meters / 1000, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} km`;

    const renderNearby = () => {
        const list = byId('dashboard-nearby-list');
        const count = byId('dashboard-nearby-count');
        const status = byId('dashboard-nearby-status');
        const currentLocation = byId('current_location');
        const lat = Number(currentLocation?.dataset.lat);
        const lon = Number(currentLocation?.dataset.lon);
        const stops = nearbyStops.map((stop) => {
            const distance = Number.isFinite(lat) && Number.isFinite(lon)
                ? haversineMeters(lat, lon, Number(stop.lat), Number(stop.lon))
                : null;
            return { ...stop, type: 'Stație de transport', typeLabel: stop.typeLabel || t('Transit stop'), distance, distanceLabel: distance === null ? '' : formatDistance(distance) };
        });
        const unique = new Map();
        [...stops, ...nearbyPois].forEach((item) => {
            const key = `${item.type}:${item.id ?? ''}:${item.lat ?? ''}:${item.lon ?? ''}:${item.name}`;
            if (!unique.has(key)) unique.set(key, item);
        });
        const items = [...unique.values()].sort((a, b) => (a.distance ?? Infinity) - (b.distance ?? Infinity));
        list.replaceChildren();
        count.textContent = String(items.length);
        setText('dashboard-panel-poi-count', `${items.length} POI`);
        setText('dashboard-poi-count', String(items.length));
        if (items.length === 0) {
            status.textContent = currentLocation?.dataset.lat
                ? t('No stops or points of interest found within the selected radius.')
                : t('Choose a locality to load stops and points of interest in the area.');
            return;
        }
        status.textContent = t('The closest places in the selected area.');
        items.slice(0, 12).forEach((item) => {
            const row = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dashboard-nearby-item';
            button.title = t('Center the map on this place');
            const icon = document.createElement('span');
            icon.className = 'dashboard-nearby-icon';
            icon.innerHTML = item.type === 'Stație de transport'
                ? '<i class="fa-solid fa-bus-simple" aria-hidden="true"></i>'
                : '<i class="fa-solid fa-location-dot" aria-hidden="true"></i>';
            const details = document.createElement('span');
            details.className = 'dashboard-nearby-item-copy';
            const name = document.createElement('strong');
            name.textContent = item.name || t('Unnamed point');
            const type = document.createElement('small');
            const typeLabels = {
                airport: t('Airport'), bus: t('Bus stop'), train: t('Train station'), subway: t('Metro'), taxi: t('Taxi'),
                fuel: t('Fuel station'), parking: t('Car park'), restaurant: t('Restaurant'), cafe: t('Café'), lodging: t('Lodging'),
                supermarket: t('Supermarket'), hospital: t('Hospital'), pharmacy: t('Pharmacy'), tourism: t('Tourist attraction'),
                charging_station: t('EV charging'),
            };
            type.textContent = item.typeLabel || typeLabels[item.type] || item.type || t('Point of interest');
            details.append(name, type);
            const distance = document.createElement('span');
            distance.className = 'dashboard-nearby-distance';
            distance.textContent = item.distanceLabel || '';
            button.append(icon, details, distance);
            if (Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lon))) {
                button.addEventListener('click', () => window.dispatchEvent(new CustomEvent('symbiot:focus-map-point', {
                    detail: { lat: Number(item.lat), lon: Number(item.lon) },
                })));
            }
            row.append(button);
            list.append(row);
        });
    };

    document.getElementById('dashboard-use-location')?.addEventListener('click', () => {
        const deviceMode = byId('toggleLocation');
        if (!deviceMode) return;
        deviceMode.checked = true;
        deviceMode.dispatchEvent(new Event('change', { bubbles: true }));
        
        if(searchStatus.parentNode.classList.contains('d-none')) {
            searchStatus.parentNode.classList.remove('d-none');
        }
    });

    document.getElementById('dashboard-search-location')?.addEventListener('click', () => {
        cityMode.checked = true;
        cityMode.dispatchEvent(new Event('change', { bubbles: true }));
        cityInput?.focus();
    });

    searchForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const query = searchInput?.value.trim() ?? '';
        if (query.length < 2) {
            searchStatus.textContent = t('Enter at least two characters.');
            return;
        }
        if (!cityInput || !cityForm) return;

        if(searchStatus.parentNode.classList.contains('d-none')) {
            searchStatus.parentNode.classList.remove('d-none');
        }

        searchStatus.textContent = t('Searching for the locality...');
        searchResults.hidden = true;
        cityMode.checked = true;
        cityMode.dispatchEvent(new Event('change', { bubbles: true }));
        cityInput.value = query;
        cityInput.dispatchEvent(new Event('input', { bubbles: true }));
        cityForm.requestSubmit();
    });

    window.addEventListener('symbiot:location-candidates', (event) => {
        const candidates = event.detail?.candidates ?? [];
        searchSelect.replaceChildren(new Option(t('Choose locality'), ''));
        candidates.forEach((candidate) => searchSelect.add(new Option(candidate.label, String(candidate.index))));
        searchResults.hidden = false;

        if(searchStatus.parentNode.classList.contains('d-none')) {
            searchStatus.parentNode.classList.remove('d-none');
        }

        searchStatus.textContent = t('Several results found. Choose the locality.');
    });

    searchSelect?.addEventListener('change', () => {
        if (!cityResults || searchSelect.value === '') return;
        cityResults.value = searchSelect.value;
        cityForm.requestSubmit();
    });

    // "Explore Brașov" once a city is set; just "Explore" while changing and in GPS mode (no city name).
    const setPanelTitle = (place = null, label = null) => {
        const title = byId('dashboard-panel-title');
        if (!title) return;
        title.textContent = place ? t('Explore :place', { place }) : t('Explore');
        if (label) title.title = label;
        else title.removeAttribute('title');
    };

    window.addEventListener('symbiot:location-set', (event) => {
        // Phones: the panel opens on the new area (weather, radius, POI categories), city or GPS alike.
        if (routePanelMedia.matches) setRoutePanelCollapsed(false);
        setPanelTitle(event.detail?.place ?? null, event.detail?.label ?? null);
        searchStatus.textContent = t('Map centered on :place.', { place: event.detail?.label ?? t('the selected locality') });
        resetDashboardLocationState();
    });
    window.addEventListener('symbiot:poi-categories-ready', () => {
        poiFiltersCard?.classList.remove('d-none');
        setPoiAccordionOpen(true);
    });
    window.addEventListener('symbiot:location-changing', (event) => {
        setPanelTitle();
        resetDashboardLocationState(true);
        if (searchInput) searchInput.value = '';
        searchSelect?.replaceChildren(new Option(t('Choose locality'), ''));

        if(searchStatus.parentNode.classList.contains('d-none')) {
            searchStatus.parentNode.classList.remove('d-none');
        }

        searchStatus.textContent = event.detail?.mode === 'gps'
            ? t('Determining the GPS location...')
            : t('Changing the locality...');
    });
    window.addEventListener('symbiot:location-error', (event) => {
        // Phones: the status is in the panel's heading; the panel opens so the message is seen.
        if (routePanelMedia.matches) setRoutePanelCollapsed(false);
        if(searchStatus.parentNode.classList.contains('d-none')) {
            searchStatus.parentNode.classList.remove('d-none');
        }

        searchStatus.textContent = event.detail?.message ?? t('Could not find the locality.');
    });
    window.addEventListener('symbiot:nearby-pois', (event) => {
        nearbyPois = event.detail?.pois ?? [];
        renderNearby();
    });
    window.addEventListener('symbiot:nearby-transit-stops', (event) => {
        nearbyStops = event.detail?.stops ?? [];
        renderNearby();
    });
    window.addEventListener('symbiot:nearby-error', (event) => {
        setText('dashboard-nearby-status', event.detail?.message || t('Could not load the nearby points.'));
    });
    window.addEventListener('symbiot:nearby-loading', () => {
        setText('dashboard-nearby-status', t('Loading results for the selected categories...'));
        setText('dashboard-nearby-count', '…');
        setText('dashboard-panel-poi-count', '… POI');
        setText('dashboard-poi-count', '…');
    });
    window.addEventListener('symbiot:nearby-cleared', () => {
        nearbyPois = [];
        nearbyStops = [];
        byId('dashboard-nearby-list')?.replaceChildren();
        setText('dashboard-nearby-count', '0');
        setText('dashboard-panel-poi-count', '0 POI');
        setText('dashboard-poi-count', '0');
        setText('dashboard-nearby-status', t('Choose the POI categories you want to see on the map.'));
    });

    const routeModeLabel = (type) => ({
        0: t('Tram'), 1: t('Metro'), 2: t('Train'), 3: t('Bus'), 11: t('Trolleybus'),
    }[type] ?? (type >= 100 && type < 200 ? t('Train') : t('Public transport')));
    const routePresentation = (route) => {
        const shortName = String(route.short_name ?? '').trim();
        const longName = String(route.long_name ?? '').trim();
        const longNameIsLineCode = /^[a-z]{1,3}\s*-?\s*\d{1,3}$/i.test(longName);
        const shortNameIsNumeric = /^\d+$/.test(shortName);
        const publicName = shortNameIsNumeric && longNameIsLineCode
            ? longName
            : shortName || longName || route.route_id || t('Line');
        const secondaryName = shortNameIsNumeric && longNameIsLineCode
            ? t('Code :code', { code: shortName })
            : longName && longName !== publicName
                ? longName
                : '';

        return { publicName, secondaryName };
    };

    window.addEventListener('symbiot:transit-stop-routes', (event) => {
        const { feed, stopId, stopName, routes = [], tickets = null, error = false, loading = false } = event.detail ?? {};
        if (!stopRoutesPanel || !stopRoutesList) return;
        closeTripCard();
        stopTickets = tickets;
        closePoiAccordion();
        routeEmpty.hidden = true;
        revealPanelSection(stopRoutesPanel);
        routePanel.classList.add('has-stop-selection');
        routePanel.classList.add('is-open');
        if (routePanelMedia.matches) setRoutePanelCollapsed(false);
        setText('dashboard-stop-routes-title', t('Lines at stop'));
        setText('dashboard-stop-routes-stop', stopName || t('Stop selected on the map'));
        setText('dashboard-stop-routes-count', loading ? t(':count lines', { count: '…' }) : (routes.length === 1 ? t('1 line') : t(':count lines', { count: routes.length })));
        stopRoutesList.replaceChildren();

        if (loading) {
            const pending = document.createElement('p');
            pending.className = 'dashboard-stop-routes-empty';
            pending.textContent = t('Loading the stop lines...');
            stopRoutesList.append(pending);
            return;
        }

        if (routes.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'dashboard-stop-routes-empty';
            empty.textContent = error
                ? t('Could not load the lines for this stop. Try again.')
                : t('There are no lines with a timetable for this stop.');
            stopRoutesList.append(empty);
            return;
        }

        routes.forEach((route) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dashboard-stop-route-option';
            button.dataset.routeId = route.route_id;
            const badge = document.createElement('span');
            badge.className = 'dashboard-stop-route-badge';
            const presentation = routePresentation(route);
            badge.textContent = presentation.publicName;
            badge.title = presentation.publicName;
            paintLineBadge(badge, route.color, route.text_color, '#1769e0');
            const copy = document.createElement('span');
            copy.className = 'dashboard-stop-route-copy';
            const name = document.createElement('strong');
            name.textContent = presentation.secondaryName
                ? t('Line :line', { line: presentation.publicName })
                : route.long_name || `${routeModeLabel(Number(route.route_type))} · ${t('Line :line', { line: badge.textContent })}`;
            const mode = document.createElement('small');
            mode.textContent = [presentation.secondaryName, routeModeLabel(Number(route.route_type))].filter(Boolean).join(' · ');
            copy.append(name, mode);
            const arrow = document.createElement('i');
            arrow.className = 'fa-solid fa-chevron-right';
            arrow.setAttribute('aria-hidden', 'true');
            button.append(badge, copy, arrow);
            button.addEventListener('click', () => window.dispatchEvent(new CustomEvent('symbiot:transit-route-toggle', {
                detail: { feed, stopId, stopName, route },
            })));
            stopRoutesList.append(button);
        });
    });

    const { renderRailBoard } = createRailBoardView({ list: stopRoutesList, tripRow, tripRowArrow });

    window.addEventListener('symbiot:osm-stop-routes', (event) => {
        const {
            stopName,
            routes = [],
            trainServices = [],
            trainServicesAvailable = true,
            isTrainStation = false,
            timetableSource = 'data.gov.ro',
            timetableValidUntil = null,
            feature,
            routesAvailable = true,
            loading = false,
            isAirport = false,
            airportFlights = null,
            coordinates = null,
        } = event.detail ?? {};
        closePoiAccordion();
        closeTripCard();
        routeDetails.hidden = true;
        routeEmpty.hidden = true;
        revealPanelSection(stopRoutesPanel);
        routePanel.classList.add('has-stop-selection', 'is-open');
        if (routePanelMedia.matches) setRoutePanelCollapsed(false);
        setText('dashboard-stop-routes-title', isAirport ? t('Flights at the airport') : t('Lines at stop'));
        setText('dashboard-stop-routes-stop', stopName || (isAirport ? t('Airport selected on the map') : t('Stop selected on the map')));
        const flightCount = (airportFlights?.arrivals?.length ?? 0) + (airportFlights?.departures?.length ?? 0);
        setText('dashboard-stop-routes-count', loading
            ? t('Loading...')
            : isAirport
                ? (flightCount === 1 ? t('1 flight') : t(':count flights', { count: flightCount }))
                : `${routes.length === 1 ? t('1 line') : t(':count lines', { count: routes.length })}${trainServices.length ? ` · ${t(':count trains', { count: trainServices.length })}` : ''}`);
        stopRoutesList.replaceChildren();

        if (loading) {
            const pending = document.createElement('p');
            pending.className = 'dashboard-stop-routes-empty';
            pending.textContent = isAirport ? t('Loading arrivals and departures...') : t('Searching for the stop lines...');
            stopRoutesList.append(pending);
            return;
        }

        if (isAirport) {
            if (!airportFlights?.available) {
                const unavailable = document.createElement('p');
                unavailable.className = 'dashboard-stop-routes-empty';
                unavailable.textContent = airportFlights?.message
                    || t('Flight data is not available for this airport.');
                stopRoutesList.append(unavailable);
            } else {
                const formatFlightTime = (value, timeZone = undefined) => {
                    if (!value) return '—';
                    const date = new Date(value);
                    if (Number.isNaN(date.getTime())) return '—';
                    try {
                        return new Intl.DateTimeFormat(intlLocale, {
                            hour: '2-digit', minute: '2-digit',
                            hour12: false, // <-- Forțează formatul de 24 de ore
                            ...(timeZone ? { timeZone } : {}),
                        }).format(date);
                    } catch {
                        return new Intl.DateTimeFormat(intlLocale, { 
                            hour: '2-digit', 
                            minute: '2-digit',
                            hour12: false // <-- Și aici pentru siguranță
                        }).format(date);
                    }
                };
                const renderFlightGroup = (title, flights, direction) => {
                    const heading = document.createElement('p');
                    heading.className = 'dashboard-stop-routes-stop dashboard-train-services-heading';
                    heading.textContent = title;
                    stopRoutesList.append(heading);
                    const upcomingFlights = (Array.isArray(flights) ? flights : [])
                        .filter((flight) => {
                            const timestamp = Date.parse(flight.estimated_time || flight.scheduled_time || flight.actual_time || '');
                            return Number.isFinite(timestamp) && timestamp >= Date.now();
                        })
                        .sort((a, b) => Date.parse(a.estimated_time || a.scheduled_time || a.actual_time)
                            - Date.parse(b.estimated_time || b.scheduled_time || b.actual_time));
                    if (upcomingFlights.length === 0) {
                        const empty = document.createElement('p');
                        empty.className = 'dashboard-stop-routes-empty';
                        empty.textContent = t('No flights scheduled after the current time in the next 24 hours.');
                        stopRoutesList.append(empty);
                        return;
                    }
                    upcomingFlights.forEach((flight) => {
                        const item = tripRow('dashboard-stop-route-option dashboard-train-service', () => flightCard(
                            flight, direction, stopName || t('Airport'), (iso) => formatFlightTime(iso, flight.airport_timezone || undefined),
                        ));
                        
                        const badge = document.createElement('span');
                        badge.className = 'dashboard-stop-route-badge';
                        badge.textContent = formatFlightTime(
                            flight.estimated_time || flight.scheduled_time || flight.actual_time,
                            flight.airport_timezone || undefined,
                        );
                        const copy = document.createElement('span');
                        copy.className = 'dashboard-stop-route-copy';
                        const name = document.createElement('strong');
                        name.textContent = flight.number || t('Flight');
                        const endpoint = direction === 'arrival'
                            ? (flight.origin_name || flight.origin || t('Origin unavailable'))
                            : (flight.destination_name || flight.destination || t('Destination unavailable'));
                        const details = [
                            endpoint,
                            flight.operator_name || flight.operator,
                            flight.terminal ? t('Terminal :terminal', { terminal: flight.terminal }) : null,
                            flight.gate ? t('Gate :gate', { gate: flight.gate }) : null,
                            direction === 'arrival' && flight.baggage_claim ? t('Baggage belt :belt', { belt: flight.baggage_claim }) : null,
                        ].filter(Boolean);
                        const description = document.createElement('small');
                        description.textContent = details.join(' · ');
                        copy.append(name, description);
                        item.append(badge, copy);
                        const statusText = flight.cancelled ? t('Cancelled')
                            : flight.diverted ? t('Diverted')
                                : flight.status || (flight.estimated_time ? t('Estimated') : t('Scheduled'));
                        const status = document.createElement('small');
                        status.className = 'dashboard-train-services-source';
                        status.textContent = statusText;
                        item.append(status);
                        item.append(tripRowArrow());
                        stopRoutesList.append(item);
                    });
                };
                renderFlightGroup(t('Arrivals from now on'), airportFlights.arrivals || [], 'arrival');
                renderFlightGroup(t('Departures from now on'), airportFlights.departures || [], 'departure');
                const source = document.createElement('small');
                source.className = 'dashboard-train-services-source';
                source.textContent = t('Source: :source · updated :time', {
                    source: airportFlights.source || t('Flight data'),
                    time: airportFlights.updated_at ? formatFlightTime(airportFlights.updated_at) : t('recently'),
                });
                stopRoutesList.append(source);
            }
            return;
        }

        if (routes.length === 0 && trainServices.length === 0 && !isTrainStation) {
            const empty = document.createElement('p');
            empty.className = 'dashboard-stop-routes-empty';
            empty.textContent = routesAvailable
                ? t('There are no lines associated with this stop.')
                : t('The stop lines could not be loaded.');
            stopRoutesList.append(empty);
            return;
        }

        routes.forEach((group) => {
            const item = document.createElement('div');
            item.className = 'dashboard-stop-route-item';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dashboard-stop-route-option';
            button.dataset.routeKey = group.key;
            const badge = document.createElement('span');
            badge.className = 'dashboard-stop-route-badge';
            badge.textContent = group.label.replace(/^(Autobuz|Troleibuz)\s+/i, '');
            const copy = document.createElement('span');
            copy.className = 'dashboard-stop-route-copy';
            const name = document.createElement('strong');
            name.textContent = group.directionLabel ? `${group.label} — ${group.directionLabel}` : group.label;
            const mode = document.createElement('small');
            const directions = [...new Set(group.routes.map((route) => route.direction).filter(Boolean))];
            mode.textContent = directions.length > 0
                ? `${directions.join(' · ')} · OpenStreetMap`
                : t('OpenStreetMap · select to draw');
            copy.append(name, mode);
            const arrow = document.createElement('i');
            arrow.className = 'fa-solid fa-chevron-right';
            arrow.setAttribute('aria-hidden', 'true');
            button.append(badge, copy, arrow);

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.hidden = true;
            checkbox.className = 'poi-route-checkbox';
            checkbox.dataset.routeKey = group.key;
            checkbox._dashboardRouteButton = button;
            const status = document.createElement('small');
            status.className = 'dashboard-stop-route-status';
            status.hidden = true;
            button.addEventListener('click', () => {
                // Selecting draws the line on the map and opens its card; a second click only removes it.
                const opening = !button.classList.contains('is-selected');
                window.dispatchEvent(new CustomEvent('symbiot:osm-route-toggle', {
                    detail: { group, feature, checkbox, button, status },
                }));
                if (opening) openTripCard(osmRouteCard(group, stopName), button);
            });
            item.append(button, checkbox, status);
            stopRoutesList.append(item);
        });

        if (isTrainStation) {
            // Live departures/arrivals (Transitous), loaded on demand; the annual CFR timetable is the fallback.
            renderRailBoard(stopName, coordinates);
        }
    });

    window.addEventListener('symbiot:poi-selected', (event) => {
        if (['bus', 'train', 'subway', 'airport'].includes(event.detail?.type)) return;
        closeTripCard();
        stopRoutesPanel.hidden = true;
        routeDetails.hidden = true;
        routePanel.classList.remove('has-stop-selection');
    });

    window.addEventListener('symbiot:osm-route-state', (event) => {
        if (event.detail?.clear) {
            stopRoutesList?.querySelectorAll('.dashboard-stop-route-option').forEach((button) => {
                button.classList.remove('is-selected');
            });
        }
        if (event.detail?.routeKey) {
            const button = stopRoutesList?.querySelector(`[data-route-key="${CSS.escape(event.detail.routeKey)}"]`);
            button?.classList.toggle('is-selected', Boolean(event.detail.selected));
            if (event.detail.color) paintLineBadge(button?.querySelector('.dashboard-stop-route-badge'), event.detail.color, null);
        }
    });

    const showRoute = (detail) => {
        if (!detail?.selected) {
            selectedRoute = null;
            routeDetails.hidden = true;
            routeEmpty.hidden = Boolean(byId('current_location')?.dataset.lat);
            routePanel.classList.remove('is-open');
            stopRoutesList?.querySelectorAll('.dashboard-stop-route-option').forEach((button) => button.classList.remove('is-selected'));
            return;
        }
        selectedRoute = detail;
        routeEmpty.hidden = true;
        revealPanelSection(routeDetails);
        routePanel.classList.add('is-open');
        if (routePanelMedia.matches) setRoutePanelCollapsed(false);
        stopRoutesList?.querySelectorAll('.dashboard-stop-route-option').forEach((button) => {
            button.classList.toggle('is-selected', button.dataset.routeId === String(detail.route?.route_id));
        });

        const route = detail.route ?? {};
        const presentation = routePresentation(route);
        const label = presentation.publicName;
        closeTripCard();
        renderModeChip(byId('dashboard-route-mode'), gtfsMode(route.route_type));
        renderItinerary(byId('dashboard-route-itinerary'), null, null);
        const routeTickets = gtfsTickets(stopTickets, route.agency_id ?? null);
        renderTickets(byId('dashboard-route-tickets'), routeTickets.tickets, routeTickets.notes, t('No ticket link is published for this line.'));
        setText('dashboard-route-title', t('Route :route', { route: label }));
        renderRouteStop(detail);
        setText('dashboard-route-distance', t('Calculating...'));
        setText('dashboard-route-shape-status', t('Loading the route geometry...'));
    };

    /** The stop part of the line's card: its name and, until the answer comes, a pending next departure. */
    const renderRouteStop = (detail) => {
        const route = detail.route ?? {};
        const presentation = routePresentation(route);
        setText('dashboard-route-subtitle', [presentation.secondaryName, detail.stopName ? t('Stop :stop', { stop: detail.stopName }) : null].filter(Boolean).join(' · ')
            || route.long_name || t('Stop :stop', { stop: detail.stopName || '' }));
        setText('dashboard-route-stop', detail.stopName ? t('Stop :stop', { stop: detail.stopName }) : t('Stop selected on the map'));
        setText('dashboard-route-departure', t('Searching...'));
        setText('dashboard-route-countdown', t('Searching timetable...'));
        setText('dashboard-route-headsign', '');
    };

    /** "12 min", or "6 h 9 min" from an hour on. */
    const waitText = (minutes) => {
        const total = Math.max(0, Math.round(Number(minutes) || 0));
        if (total < 60) return t(':minutes min', { minutes: total });
        const hours = Math.floor(total / 60);
        const rest = total % 60;
        return rest === 0 ? t(':hours h', { hours }) : t(':hours h :minutes min', { hours, minutes: rest });
    };

    /** The departure time with its day when it is not today: "tomorrow, 05:30" or "Fri 21 Nov, 05:30". */
    const departureDayTime = (departure) => {
        const days = Number(departure.days_ahead) || 0;
        if (days === 0) return departure.time;
        if (days === 1) return t('tomorrow, :time', { time: departure.time });
        const date = departure.date ? new Date(`${departure.date}T12:00:00`) : null;
        const day = date && !Number.isNaN(date.getTime())
            ? new Intl.DateTimeFormat(intlLocale, { weekday: 'short', day: 'numeric', month: 'short' }).format(date)
            : null;
        return day ? `${day}, ${departure.time}` : departure.time;
    };

    window.addEventListener('symbiot:transit-route-selected', (event) => showRoute(event.detail));
    // Another stop of the selected line: only the card changes, the list and the line stay.
    window.addEventListener('symbiot:transit-route-stop', (event) => {
        const detail = event.detail;
        if (!selectedRoute || detail?.feed !== selectedRoute.feed || detail?.route?.route_id !== selectedRoute.route?.route_id) return;
        selectedRoute = { ...selectedRoute, stopId: detail.stopId, stopName: detail.stopName };
        renderRouteStop(detail);
    });
    window.addEventListener('symbiot:transit-route-schedule', (event) => {
        if (!selectedRoute || event.detail?.feed !== selectedRoute.feed || event.detail?.route?.route_id !== selectedRoute.route?.route_id) return;
        // An answer for a stop of the line that is no longer the chosen one.
        if (event.detail?.stopId && selectedRoute.stopId && event.detail.stopId !== selectedRoute.stopId) return;
        const departure = event.detail.departure;
        if (!departure) {
            setText('dashboard-route-departure', t('Unavailable'));
            setText('dashboard-route-countdown', event.detail.reason === 'schedule_unavailable'
                ? t('The timetable is not available.')
                : t('There are no departures scheduled in the coming period.'));
            return;
        }
        setText('dashboard-route-departure', departureDayTime(departure));
        renderItinerary(byId('dashboard-route-itinerary'), departure.origin || null, departure.destination || departure.headsign || null);
        setText('dashboard-route-countdown', departure.minutes_until === 0 ? t('Leaving now') : waitText(departure.minutes_until));
        setText('dashboard-route-headsign', departure.headsign ? t('Direction: :headsign', { headsign: departure.headsign }) : t('Scheduled timetable'));
    });
    window.addEventListener('symbiot:transit-route-shape', (event) => {
        if (!selectedRoute || event.detail?.feed !== selectedRoute.feed || event.detail?.route?.route_id !== selectedRoute.route?.route_id) return;
        const meters = (event.detail.shapes ?? []).reduce((total, shape) => {
            for (let index = 1; index < shape.length; index++) total += haversineMeters(shape[index - 1][1], shape[index - 1][0], shape[index][1], shape[index][0]);
            return total;
        }, 0);
        setText('dashboard-route-distance', meters > 0 ? `${formatNumber(meters / 1000, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} km` : '—');
        setText('dashboard-route-shape-status', meters > 0 ? t('The route is highlighted on the map.') : t('The route geometry is not available.'));
    });

    byId('dashboard-fit-route')?.addEventListener('click', () => {
        window.dispatchEvent(new Event('symbiot:fit-transit-route'));
    });
    byId('dashboard-panel-close')?.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 767.98px)').matches) {
            showRoute({ selected: false });
        } else {
            page.classList.add('panel-collapsed');
            routePanel.classList.remove('is-open');
        }
    });
});
