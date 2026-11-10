
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
            alert("Permisiunea pentru localizare a fost refuzată.");
            break;

        case error.POSITION_UNAVAILABLE:
            alert("Poziția nu este disponibilă.");
            break;

        case error.TIMEOUT:
            alert("Obținerea poziției a expirat.");
            break;

        default:
            alert("Eroare necunoscută.");
    }

}


/* =========================================================
   DISTANCE
   ========================================================= */

function distanceInMeters(lat1, lon1, lat2, lon2) {

    const R = 6371000;

    const toRad = value => value * Math.PI / 180;

    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);

    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(lat1)) *
        Math.cos(toRad(lat2)) *
        Math.sin(dLon / 2) ** 2;

    return R * (
        2 * Math.atan2(
            Math.sqrt(a),
            Math.sqrt(1 - a)
        )
    );

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

    const meters = distanceInMeters(

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

        alert("Geolocation nu este suportată.");

        if (trackingSwitch) {
            trackingSwitch.checked = false;
        }

        return;
    }

    watchId = navigator.geolocation.watchPosition(

        async position => {

            try {

                if (!trackingStarted) {

                    await post('/tracking/start');

                    trackingStarted = true;

                    setTrackingIndicator(true);

                    await savePosition(position.coords);

                    lastPosition = position.coords;
                    lastSavedTime = Date.now();

                    return;
                }

                await onPosition(position);

            } catch (error) {

                console.error('Tracking error:', error);

            }

        },

        error => {

            showError(error);

            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }

        },

        options

    );

}


/* =========================================================
   STOP TRACKING
   ========================================================= */

async function stopTracking() {

    if (watchId !== null) {

        navigator.geolocation.clearWatch(watchId);

        watchId = null;

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

        alert("Geolocation nu este suportată.");

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

        alert('Nu s-a putut modifica starea trackingului.');

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
   SYMBIOT NAVIGATION & THEME
   ========================================================= */

document.addEventListener('DOMContentLoaded', () => {

    const shell = document.getElementById('symbiotShell');

    if (!shell) return;

    const sidebar = document.getElementById('symbiotSidebar');

    const mobileButton = document.getElementById(
        'sidebarMobileToggle'
    );

    const mobileClose = document.getElementById(
        'sidebarMobileClose'
    );

    const overlay = document.getElementById(
        'sidebarOverlay'
    );

    const collapseButton = document.getElementById(
        'sidebarCollapse'
    );

    const themeButton = document.getElementById(
        'themeToggle'
    );


    /* =====================================================
       THEME
       ===================================================== */

    function applyTheme(theme) {

        shell.dataset.theme = theme;

        const icon = document.getElementById('themeIcon');

        if (icon) {

            icon.className = theme === 'dark'
                ? 'fa-solid fa-sun'
                : 'fa-solid fa-moon';

        }

    }

    const savedTheme = localStorage.getItem('symbiot-theme');

    const systemDark = window.matchMedia(
        '(prefers-color-scheme: dark)'
    ).matches;

    applyTheme(
        savedTheme || (systemDark ? 'dark' : 'light')
    );

    themeButton?.addEventListener('click', () => {

        const nextTheme =
            shell.dataset.theme === 'dark'
                ? 'light'
                : 'dark';

        applyTheme(nextTheme);

        localStorage.setItem(
            'symbiot-theme',
            nextTheme
        );

    });


    /* =====================================================
       DEVICE DETECTION
       ===================================================== */

    const isMobile = () => window.innerWidth < 768;

    const isTablet = () =>
        window.innerWidth >= 768 &&
        window.innerWidth < 1200;

    /* =====================================================
       TRANSPORT ICONS
       ===================================================== */


        const getTransportIcon = (type) => {
            switch (type) {
                case 'airport': return '<i class="fas fa-plane text-primary"></i>';
                case 'train': return '<i class="fas fa-train text-success"></i>';
                case 'subway': return '<i class="fas fa-subway text-danger"></i>';
                case 'bus': return '<i class="fas fa-bus text-warning"></i>';
                default: return '<i class="fas fa-map-marker-alt text-secondary"></i>';
            }
        };


    /* =====================================================
       MOBILE MENU
       ===================================================== */

    function openMobileMenu() {

        if (!isMobile()) return;

        shell.classList.add('mobile-menu-open');

        document.body.classList.add('symbiot-menu-active');

        mobileButton?.setAttribute(
            'aria-expanded',
            'true'
        );

        overlay?.setAttribute(
            'aria-hidden',
            'false'
        );

    }

    function closeMobileMenu() {

        shell.classList.remove('mobile-menu-open');

        document.body.classList.remove('symbiot-menu-active');

        mobileButton?.setAttribute(
            'aria-expanded',
            'false'
        );

        overlay?.setAttribute(
            'aria-hidden',
            'true'
        );

    }


    /* =====================================================
       MOBILE EVENTS
       ===================================================== */

    mobileButton?.addEventListener(
        'click',
        openMobileMenu
    );

    mobileClose?.addEventListener(
        'click',
        closeMobileMenu
    );

    overlay?.addEventListener(
        'click',
        closeMobileMenu
    );


    /* Close menu after navigation */

    sidebar?.querySelectorAll('a').forEach(link => {

        link.addEventListener('click', () => {

            if (isMobile()) {
                closeMobileMenu();
            }

        });

    });


    /* =====================================================
       DESKTOP / TABLET COLLAPSE
       ===================================================== */

    collapseButton?.addEventListener('click', () => {

        if (isMobile()) return;

        if (isTablet()) {

            shell.classList.toggle('sidebar-expanded');

            return;
        }

        const collapsed = shell.classList.toggle(
            'sidebar-collapsed'
        );

        localStorage.setItem(
            'symbiot-sidebar-collapsed',
            String(collapsed)
        );

    });


    /* =====================================================
       RESTORE SIDEBAR STATE
       ===================================================== */

    function restoreSidebar() {

        if (isMobile()) {

            shell.classList.remove(
                'sidebar-collapsed',
                'sidebar-expanded',
                'mobile-menu-open'
            );

            document.body.classList.remove(
                'symbiot-menu-active'
            );

            mobileButton?.setAttribute(
                'aria-expanded',
                'false'
            );

            return;
        }

        closeMobileMenu();

        if (isTablet()) {

            shell.classList.remove(
                'sidebar-collapsed',
                'sidebar-expanded'
            );

            return;
        }

        const collapsed =
            localStorage.getItem(
                'symbiot-sidebar-collapsed'
            ) === 'true';

        shell.classList.toggle(
            'sidebar-collapsed',
            collapsed
        );

        shell.classList.remove('sidebar-expanded');

    }

    restoreSidebar();


    /* =====================================================
       WINDOW RESIZE
       ===================================================== */

    let resizeTimeout;

    window.addEventListener('resize', () => {

        clearTimeout(resizeTimeout);

        resizeTimeout = setTimeout(() => {

            restoreSidebar();

        }, 100);

    });


    /* =====================================================
       ESCAPE KEY
       ===================================================== */

    document.addEventListener('keydown', event => {

        if (event.key === 'Escape') {

            closeMobileMenu();

            shell.classList.remove('sidebar-expanded');

        }

    });

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
    const stopRoutesPanel = document.getElementById('dashboard-stop-routes');
    const stopRoutesList = document.getElementById('dashboard-stop-routes-list');
    const trackButton = document.getElementById('dashboard-track-me');
    let selectedRoute = null;
    let nearbyPois = [];
    let nearbyStops = [];

    const byId = (id) => document.getElementById(id);
    const setText = (id, value) => {
        const element = byId(id);
        if (element) element.textContent = value ?? '';
    };

    const resetDashboardLocationState = (changing = false) => {
        searchResults.hidden = true;
        selectedRoute = null;
        stopRoutesPanel.hidden = true;
        stopRoutesList.replaceChildren();
        setText('dashboard-stop-routes-count', '0 linii');
        routeDetails.hidden = true;
        routePanel.classList.remove('is-open', 'has-stop-selection');
        poiAccordion?.classList.add('d-none');
        poiFiltersCard?.classList.add('d-none');
        routeEmpty.hidden = !changing;
        byId('dashboard-poi-collapse')?.classList.add('show');
        const poiAccordionButton = poiAccordion?.querySelector('.accordion-button');
        poiAccordionButton?.classList.remove('collapsed');
        poiAccordionButton?.setAttribute('aria-expanded', 'true');
        nearbyPois = [];
        nearbyStops = [];
        setText('dashboard-nearby-status', 'Alege categoriile POI pe care vrei să le vezi pe hartă.');
        byId('dashboard-nearby-list')?.replaceChildren();
        setText('dashboard-nearby-count', '0');
        setText('dashboard-poi-count', '0 găsite');
    };

    const syncTrackButton = () => {
        const active = Boolean(trackingSwitch?.checked);
        trackButton?.classList.toggle('is-active', active);
        trackButton?.setAttribute('aria-pressed', String(active));
        trackButton?.setAttribute('title', active ? 'Oprește urmărirea GPS' : 'Pornește urmărirea GPS');
    };
    trackButton?.addEventListener('click', () => {
        if (!trackingSwitch) return;
        trackingSwitch.checked = !trackingSwitch.checked;
        trackingSwitch.dispatchEvent(new Event('change', { bubbles: true }));
        syncTrackButton();
    });
    trackingSwitch?.addEventListener('change', syncTrackButton);
    syncTrackButton();

    const poiFiltersHost = byId('dashboard-poi-filters');
    const poiFiltersCard = byId('mobility_card');
    const poiAccordion = byId('dashboard-poi-disclosure');
    if (poiFiltersHost && poiFiltersCard) poiFiltersHost.append(poiFiltersCard);

    const closePoiAccordion = () => {
        const collapse = byId('dashboard-poi-collapse');
        const button = poiAccordion?.querySelector('.accordion-button');
        collapse?.classList.remove('show');
        button?.classList.add('collapsed');
        button?.setAttribute('aria-expanded', 'false');
    };

    const formatDistance = (meters) => meters < 1000
        ? `${Math.round(meters)} m`
        : `${(meters / 1000).toFixed(1).replace('.', ',')} km`;

    const renderNearby = () => {
        const list = byId('dashboard-nearby-list');
        const count = byId('dashboard-nearby-count');
        const status = byId('dashboard-nearby-status');
        const currentLocation = byId('current_location');
        const lat = Number(currentLocation?.dataset.lat);
        const lon = Number(currentLocation?.dataset.lon);
        const stops = nearbyStops.map((stop) => {
            const distance = Number.isFinite(lat) && Number.isFinite(lon)
                ? (() => {
                    const radians = (degrees) => degrees * Math.PI / 180;
                    const dLat = radians(Number(stop.lat) - lat);
                    const dLon = radians(Number(stop.lon) - lon);
                    const value = Math.sin(dLat / 2) ** 2
                        + Math.cos(radians(lat)) * Math.cos(radians(Number(stop.lat))) * Math.sin(dLon / 2) ** 2;
                    return 6371000 * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
                })()
                : null;
            return { ...stop, type: 'Stație de transport', distance, distanceLabel: distance === null ? '' : formatDistance(distance) };
        });
        const unique = new Map();
        [...stops, ...nearbyPois].forEach((item) => {
            const key = `${item.type}:${item.id ?? ''}:${item.lat ?? ''}:${item.lon ?? ''}:${item.name}`;
            if (!unique.has(key)) unique.set(key, item);
        });
        const items = [...unique.values()].sort((a, b) => (a.distance ?? Infinity) - (b.distance ?? Infinity));
        list.replaceChildren();
        count.textContent = String(items.length);
        if (items.length === 0) {
            status.textContent = currentLocation?.dataset.lat
                ? 'Nu am găsit stații sau puncte de interes în raza selectată.'
                : 'Alege o localitate pentru a încărca stații și puncte de interes din zonă.';
            return;
        }
        status.textContent = 'Cele mai apropiate locuri din zona selectată.';
        items.slice(0, 12).forEach((item) => {
            const row = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dashboard-nearby-item';
            button.title = 'Centrează harta pe acest loc';
            const icon = document.createElement('span');
            icon.className = 'dashboard-nearby-icon';
            icon.innerHTML = item.type === 'Stație de transport'
                ? '<i class="fa-solid fa-bus-simple"></i>'
                : '<i class="fa-solid fa-location-dot"></i>';
            const details = document.createElement('span');
            details.className = 'dashboard-nearby-item-copy';
            const name = document.createElement('strong');
            name.textContent = item.name || 'Punct fără nume';
            const type = document.createElement('small');
            const typeLabels = {
                airport: 'Aeroport', bus: 'Stație de autobuz', train: 'Gară', subway: 'Metrou', taxi: 'Taxi',
                fuel: 'Benzinărie', parking: 'Parcare', restaurant: 'Restaurant', cafe: 'Cafenea', lodging: 'Cazare',
                supermarket: 'Supermarket', hospital: 'Spital', pharmacy: 'Farmacie', tourism: 'Obiectiv turistic',
                charging_station: 'Încărcare electrică',
            };
            type.textContent = item.typeLabel || typeLabels[item.type] || item.type || 'Punct de interes';
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
            searchStatus.textContent = 'Introdu cel puțin două caractere.';
            return;
        }
        if (!cityInput || !cityForm) return;

        if(searchStatus.parentNode.classList.contains('d-none')) {
            searchStatus.parentNode.classList.remove('d-none');
        }

        searchStatus.textContent = 'Se caută localitatea…';
        searchResults.hidden = true;
        cityMode.checked = true;
        cityMode.dispatchEvent(new Event('change', { bubbles: true }));
        cityInput.value = query;
        cityInput.dispatchEvent(new Event('input', { bubbles: true }));
        cityForm.requestSubmit();
    });

    window.addEventListener('symbiot:location-candidates', (event) => {
        const candidates = event.detail?.candidates ?? [];
        searchSelect.replaceChildren(new Option('Selectează localitatea', ''));
        candidates.forEach((candidate) => searchSelect.add(new Option(candidate.label, String(candidate.index))));
        searchResults.hidden = false;

        if(searchStatus.parentNode.classList.contains('d-none')) {
            searchStatus.parentNode.classList.remove('d-none');
        }

        searchStatus.textContent = 'Am găsit mai multe rezultate. Alege localitatea.';
    });

    searchSelect?.addEventListener('change', () => {
        if (!cityResults || searchSelect.value === '') return;
        cityResults.value = searchSelect.value;
        cityForm.requestSubmit();
    });

    window.addEventListener('symbiot:location-set', (event) => {
        searchStatus.textContent = `Hartă centrată pe ${event.detail?.label ?? 'localitatea selectată'}.`;
        resetDashboardLocationState();
        if (!event.detail?.deferPoiCategories) {
            poiFiltersCard?.classList.remove('d-none');
        }
    });
    window.addEventListener('symbiot:location-changing', (event) => {
        resetDashboardLocationState(true);
        if (searchInput) searchInput.value = '';
        searchSelect?.replaceChildren(new Option('Selectează localitatea', ''));

        if(searchStatus.parentNode.classList.contains('d-none')) {
            searchStatus.parentNode.classList.remove('d-none');
        }

        searchStatus.textContent = event.detail?.mode === 'gps'
            ? 'Se determină locația GPS…'
            : 'Se schimbă localitatea…';
    });
    window.addEventListener('symbiot:location-error', (event) => {
        if(searchStatus.parentNode.classList.contains('d-none')) {
            searchStatus.parentNode.classList.remove('d-none');
        }

        searchStatus.textContent = event.detail?.message ?? 'Nu am putut găsi localitatea.';
    });
    window.addEventListener('symbiot:nearby-pois', (event) => {
        nearbyPois = event.detail?.pois ?? [];
        setText('dashboard-poi-count', `${nearbyPois.length} găsite`);
        renderNearby();
    });
    window.addEventListener('symbiot:nearby-transit-stops', (event) => {
        nearbyStops = event.detail?.stops ?? [];
        renderNearby();
    });
    window.addEventListener('symbiot:nearby-error', (event) => {
        setText('dashboard-nearby-status', event.detail?.message || 'Nu s-au putut încărca punctele din apropiere.');
    });
    window.addEventListener('symbiot:nearby-loading', () => {
        setText('dashboard-nearby-status', 'Se încarcă rezultatele pentru categoriile selectate…');
        setText('dashboard-nearby-count', '…');
        setText('dashboard-poi-count', '…');
    });
    window.addEventListener('symbiot:nearby-cleared', () => {
        nearbyPois = [];
        nearbyStops = [];
        byId('dashboard-nearby-list')?.replaceChildren();
        setText('dashboard-nearby-count', '0');
        setText('dashboard-poi-count', '0 găsite');
        setText('dashboard-nearby-status', 'Alege categoriile POI pe care vrei să le vezi pe hartă.');
    });

    const routeModeLabel = (type) => ({
        0: 'Tramvai', 1: 'Metrou', 2: 'Tren', 3: 'Autobuz', 11: 'Troleibuz',
    }[type] ?? (type >= 100 && type < 200 ? 'Tren' : 'Transport public'));
    const gtfsColor = (value, fallback) => {
        const color = String(value ?? '').replace(/^#/, '');
        return /^[0-9a-f]{6}$/i.test(color) ? `#${color}` : fallback;
    };
    const routePresentation = (route) => {
        const shortName = String(route.short_name ?? '').trim();
        const longName = String(route.long_name ?? '').trim();
        const longNameIsLineCode = /^[a-z]{1,3}\s*-?\s*\d{1,3}$/i.test(longName);
        const shortNameIsNumeric = /^\d+$/.test(shortName);
        const publicName = shortNameIsNumeric && longNameIsLineCode
            ? longName
            : shortName || longName || route.route_id || 'Linie';
        const secondaryName = shortNameIsNumeric && longNameIsLineCode
            ? `Cod ${shortName}`
            : longName && longName !== publicName
                ? longName
                : '';

        return { publicName, secondaryName };
    };

    window.addEventListener('symbiot:transit-stop-routes', (event) => {
        const { feed, stopId, stopName, routes = [], error = false, loading = false } = event.detail ?? {};
        if (!stopRoutesPanel || !stopRoutesList) return;
        closePoiAccordion();
        routeEmpty.hidden = true;
        stopRoutesPanel.hidden = false;
        routePanel.classList.add('has-stop-selection');
        routePanel.classList.add('is-open');
        setText('dashboard-stop-routes-title', 'Linii în stație');
        setText('dashboard-stop-routes-stop', stopName || 'Stația selectată pe hartă');
        setText('dashboard-stop-routes-count', loading ? '… linii' : `${routes.length} ${routes.length === 1 ? 'linie' : 'linii'}`);
        stopRoutesList.replaceChildren();

        if (loading) {
            const pending = document.createElement('p');
            pending.className = 'dashboard-stop-routes-empty';
            pending.textContent = 'Se încarcă liniile stației…';
            stopRoutesList.append(pending);
            return;
        }

        if (routes.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'dashboard-stop-routes-empty';
            empty.textContent = error
                ? 'Nu am putut încărca liniile acestei stații. Încearcă din nou.'
                : 'Nu există linii cu orar pentru această stație.';
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
            badge.style.backgroundColor = gtfsColor(route.color, '#1769e0');
            badge.style.color = gtfsColor(route.text_color, '#ffffff');
            const copy = document.createElement('span');
            copy.className = 'dashboard-stop-route-copy';
            const name = document.createElement('strong');
            name.textContent = presentation.secondaryName
                ? `Linia ${presentation.publicName}`
                : route.long_name || `${routeModeLabel(Number(route.route_type))} · Linia ${badge.textContent}`;
            const mode = document.createElement('small');
            mode.textContent = [presentation.secondaryName, routeModeLabel(Number(route.route_type))].filter(Boolean).join(' · ');
            copy.append(name, mode);
            const arrow = document.createElement('i');
            arrow.className = 'fa-solid fa-chevron-right';
            button.append(badge, copy, arrow);
            button.addEventListener('click', () => window.dispatchEvent(new CustomEvent('symbiot:transit-route-toggle', {
                detail: { feed, stopId, stopName, route },
            })));
            stopRoutesList.append(button);
        });
    });

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
        } = event.detail ?? {};
        closePoiAccordion();
        routeDetails.hidden = true;
        routeEmpty.hidden = true;
        stopRoutesPanel.hidden = false;
        routePanel.classList.add('has-stop-selection', 'is-open');
        setText('dashboard-stop-routes-title', isAirport ? 'Zboruri în aeroport' : 'Linii în stație');
        setText('dashboard-stop-routes-stop', stopName || (isAirport ? 'Aeroportul selectat pe hartă' : 'Stația selectată pe hartă'));
        const flightCount = (airportFlights?.arrivals?.length ?? 0) + (airportFlights?.departures?.length ?? 0);
        setText('dashboard-stop-routes-count', loading
            ? 'Se încarcă…'
            : isAirport
                ? `${flightCount} ${flightCount === 1 ? 'zbor' : 'zboruri'}`
                : `${routes.length} ${routes.length === 1 ? 'linie' : 'linii'}${trainServices.length ? ` · ${trainServices.length} trenuri` : ''}`);
        stopRoutesList.replaceChildren();

        if (loading) {
            const pending = document.createElement('p');
            pending.className = 'dashboard-stop-routes-empty';
            pending.textContent = isAirport ? 'Se încarcă sosirile și plecările…' : 'Se caută liniile stației…';
            stopRoutesList.append(pending);
            return;
        }

        if (isAirport) {
            if (!airportFlights?.available) {
                const unavailable = document.createElement('p');
                unavailable.className = 'dashboard-stop-routes-empty';
                unavailable.textContent = airportFlights?.message
                    || 'Datele despre zboruri nu sunt disponibile pentru acest aeroport.';
                stopRoutesList.append(unavailable);
            } else {
                const formatFlightTime = (value, timeZone = undefined) => {
                    if (!value) return '—';
                    const date = new Date(value);
                    if (Number.isNaN(date.getTime())) return '—';
                    try {
                        return new Intl.DateTimeFormat(undefined, {
                            hour: '2-digit', minute: '2-digit',
                            hour12: false, // <-- Forțează formatul de 24 de ore
                            ...(timeZone ? { timeZone } : {}),
                        }).format(date);
                    } catch {
                        return new Intl.DateTimeFormat(undefined, { 
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
                        empty.textContent = 'Nu sunt zboruri programate după ora curentă în următoarele 24 de ore.';
                        stopRoutesList.append(empty);
                        return;
                    }
                    upcomingFlights.forEach((flight) => {
                        const item = document.createElement('div');
                        item.className = 'dashboard-stop-route-option dashboard-train-service';
                        
                        const badge = document.createElement('span');
                        badge.className = 'dashboard-stop-route-badge';
                        badge.textContent = formatFlightTime(
                            flight.estimated_time || flight.scheduled_time || flight.actual_time,
                            flight.airport_timezone || undefined,
                        );
                        const copy = document.createElement('span');
                        copy.className = 'dashboard-stop-route-copy';
                        const name = document.createElement('strong');
                        name.textContent = flight.number || 'Zbor';
                        const endpoint = direction === 'arrival'
                            ? (flight.origin_name || flight.origin || 'Origine indisponibilă')
                            : (flight.destination_name || flight.destination || 'Destinație indisponibilă');
                        const details = [
                            endpoint,
                            flight.operator,
                            flight.terminal ? `Terminal ${flight.terminal}` : null,
                            flight.gate ? `Poarta ${flight.gate}` : null,
                            direction === 'arrival' && flight.baggage_claim ? `Bandă ${flight.baggage_claim}` : null,
                        ].filter(Boolean);
                        const description = document.createElement('small');
                        description.textContent = details.join(' · ');
                        copy.append(name, description);
                        item.append(badge, copy);
                        const statusText = flight.cancelled ? 'Anulat'
                            : flight.diverted ? 'Redirecționat'
                                : flight.status || (flight.estimated_time ? 'Estimat' : 'Programat');
                        const status = document.createElement('small');
                        status.className = 'dashboard-train-services-source';
                        status.textContent = statusText;
                        item.append(status);
                        stopRoutesList.append(item);
                    });
                };
                renderFlightGroup('Sosiri începând de acum', airportFlights.arrivals || [], 'arrival');
                renderFlightGroup('Plecări începând de acum', airportFlights.departures || [], 'departure');
                const source = document.createElement('small');
                source.className = 'dashboard-train-services-source';
                source.textContent = `Sursă: ${airportFlights.source || 'Flight data'} · actualizat ${airportFlights.updated_at ? formatFlightTime(airportFlights.updated_at) : 'recent'}`;
                stopRoutesList.append(source);
            }
            return;
        }

        if (routes.length === 0 && trainServices.length === 0 && !isTrainStation) {
            const empty = document.createElement('p');
            empty.className = 'dashboard-stop-routes-empty';
            empty.textContent = routesAvailable
                ? 'Nu există linii asociate acestei stații.'
                : 'Liniile stației nu au putut fi încărcate.';
            stopRoutesList.append(empty);
            return;
        }

        if (isTrainStation && trainServices.length === 0) {
            const upcoming = document.createElement('p');
            upcoming.className = 'dashboard-stop-routes-empty';
            upcoming.textContent = 'Nu mai sunt plecări programate azi după ora curentă în mersul anual.';
            stopRoutesList.append(upcoming);
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
                : 'OpenStreetMap · selectează pentru a desena';
            copy.append(name, mode);
            const arrow = document.createElement('i');
            arrow.className = 'fa-solid fa-chevron-right';
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
            button.addEventListener('click', () => window.dispatchEvent(new CustomEvent('symbiot:osm-route-toggle', {
                detail: { group, feature, checkbox, button, status },
            })));
            item.append(button, checkbox, status);
            stopRoutesList.append(item);
        });

        if (trainServices.length > 0) {
            const heading = document.createElement('p');
            heading.className = 'dashboard-stop-routes-stop dashboard-train-services-heading';
            heading.textContent = 'Plecări rămase azi';
            stopRoutesList.append(heading);

            trainServices.forEach((service) => {
                const item = document.createElement('div');
                item.className = 'dashboard-stop-route-option dashboard-train-service';
                const badge = document.createElement('span');
                badge.className = 'dashboard-stop-route-badge';
                badge.textContent = service.category || 'Tren';
                const copy = document.createElement('span');
                copy.className = 'dashboard-stop-route-copy';
                const name = document.createElement('strong');
                name.textContent = `Linia ${service.category || 'Tren'} ${service.number || ''}`.trim();
                const details = [
                    service.origin ? `din ${service.origin}` : null,
                    service.destination ? `spre ${service.destination}` : service.direction ? `spre ${service.direction}` : null,
                    service.arrival ? `sosire ${service.arrival}` : null,
                    service.departure ? `plecare ${service.departure}` : null,
                    service.operator || null,
                    service.service_days || null,
                ].filter(Boolean);
                const description = document.createElement('small');
                description.textContent = details.join(' · ') || 'Serviciu feroviar programat';
                copy.append(name, description);
                item.append(badge, copy);
                if (service.ticket_url) {
                    const ticketLink = document.createElement('a');
                    ticketLink.className = 'dashboard-train-ticket-link';
                    ticketLink.href = service.ticket_url;
                    ticketLink.target = '_blank';
                    ticketLink.rel = 'noopener noreferrer';
                    ticketLink.textContent = 'Cumpără bilet';
                    ticketLink.setAttribute('aria-label', `Cumpără bilet pentru trenul ${service.category || ''} ${service.number || ''}`.trim());
                    item.append(ticketLink);
                }
                stopRoutesList.append(item);
            });

            const source = document.createElement('small');
            source.className = 'dashboard-train-services-source';
            source.textContent = `Sursă: ${timetableSource} · mers anual${timetableValidUntil ? `, valabil până la ${timetableValidUntil}` : ''}${trainServicesAvailable ? '' : ' · unele orare nu au putut fi încărcate'}`;
            stopRoutesList.append(source);
        }
    });

    window.addEventListener('symbiot:poi-selected', (event) => {
        closePoiAccordion();
        if (['bus', 'train', 'subway', 'airport'].includes(event.detail?.type)) return;
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
            if (event.detail.color) button?.querySelector('.dashboard-stop-route-badge')?.style.setProperty('background-color', event.detail.color);
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
        routeDetails.hidden = false;
        routePanel.classList.add('is-open');
        stopRoutesList?.querySelectorAll('.dashboard-stop-route-option').forEach((button) => {
            button.classList.toggle('is-selected', button.dataset.routeId === String(detail.route?.route_id));
        });

        const route = detail.route ?? {};
        const presentation = routePresentation(route);
        const label = presentation.publicName;
        const badge = byId('dashboard-route-badge');
        badge.textContent = label;
        badge.style.backgroundColor = detail.color || gtfsColor(route.color, '#1769e0');
        badge.style.color = gtfsColor(route.text_color, '#ffffff');
        setText('dashboard-route-mode', routeModeLabel(Number(route.route_type)));
        setText('dashboard-route-title', `Traseul ${label}`);
        setText('dashboard-route-subtitle', [presentation.secondaryName, detail.stopName ? `Stația ${detail.stopName}` : null].filter(Boolean).join(' · ')
            || route.long_name || `Stația ${detail.stopName || ''}`);
        setText('dashboard-route-stop', detail.stopName ? `Stația ${detail.stopName}` : 'Stația selectată pe hartă');
        setText('dashboard-route-distance', 'Se calculează…');
        setText('dashboard-route-departure', 'Se caută…');
        setText('dashboard-route-countdown', 'Se caută orarul…');
        setText('dashboard-route-headsign', '');
        setText('dashboard-route-shape-status', 'Se încarcă geometria traseului…');
    };

    window.addEventListener('symbiot:transit-route-selected', (event) => showRoute(event.detail));
    window.addEventListener('symbiot:transit-route-schedule', (event) => {
        if (!selectedRoute || event.detail?.feed !== selectedRoute.feed || event.detail?.route?.route_id !== selectedRoute.route?.route_id) return;
        const departure = event.detail.departure;
        if (!departure) {
            setText('dashboard-route-departure', 'Indisponibil');
            setText('dashboard-route-countdown', event.detail.reason === 'schedule_unavailable'
                ? 'Orarul nu este disponibil.'
                : 'Nu există plecări programate în perioada următoare.');
            return;
        }
        setText('dashboard-route-departure', departure.time);
        setText('dashboard-route-countdown', departure.minutes_until === 0 ? 'Pleacă acum' : `${departure.minutes_until} min`);
        setText('dashboard-route-headsign', departure.headsign ? `Direcția: ${departure.headsign}` : 'Orar programat');
    });
    window.addEventListener('symbiot:transit-route-shape', (event) => {
        if (!selectedRoute || event.detail?.feed !== selectedRoute.feed || event.detail?.route?.route_id !== selectedRoute.route?.route_id) return;
        const haversine = (a, b) => {
            const radians = (degrees) => degrees * Math.PI / 180;
            const dLat = radians(b[1] - a[1]);
            const dLon = radians(b[0] - a[0]);
            const value = Math.sin(dLat / 2) ** 2
                + Math.cos(radians(a[1])) * Math.cos(radians(b[1])) * Math.sin(dLon / 2) ** 2;
            return 6371000 * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
        };
        const meters = (event.detail.shapes ?? []).reduce((total, shape) => {
            for (let index = 1; index < shape.length; index++) total += haversine(shape[index - 1], shape[index]);
            return total;
        }, 0);
        setText('dashboard-route-distance', meters > 0 ? `${(meters / 1000).toFixed(1).replace('.', ',')} km` : '—');
        setText('dashboard-route-shape-status', meters > 0 ? 'Traseul este evidențiat pe hartă.' : 'Geometria traseului nu este disponibilă.');
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
