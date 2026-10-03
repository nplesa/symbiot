import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const page = document.getElementById('navigation-page');
const status = document.getElementById('navigation-status');
const stopButton = document.getElementById('navigation-stop');
const simulateButton = document.getElementById('navigation-simulate');
const toastContainer = document.getElementById('navigation-toast-container');
const alertList = document.getElementById('navigation-alert-list');
const simulationSpeed = document.getElementById('navigation-simulation-speed');
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
let map = null;
let routeBounds = null;
let mapZooming = false;
let followSimulation = true;
let sessionId = null;
let watchId = null;
let currentMarker = null;
let routeLayer = null;
let simulationTimer = null;
let simulationStep = 0;
let simulatedMarker = null;
let poiLayers = {};
const poiCategories = JSON.parse(page?.dataset.poiCategories || '{}');
const amenityIcons = Object.fromEntries(Object.entries(poiCategories).filter(([, category]) => category.icon).map(([type, category]) => [type, category.icon]));
let poiFilters = new Set(Object.keys(poiCategories).filter(type => poiCategories[type].default));
let routePois = [];
const announcedPoiIds = new Set();
const announcedPoiKeys = new Set();
let latestReachedPoiId = null;
const SIMULATION_TICK_MS = 800;
const SIMULATION_SPEED_KMH = 50;

function keepRouteInFront() {
    routeLayer?.bringToFront();
}

function distanceMeters(first, second) {
    const latitudeScale = 111320;
    const longitudeScale = 111320 * Math.cos(first[0] * Math.PI / 180);
    return Math.hypot(
        (second[1] - first[1]) * longitudeScale,
        (second[0] - first[0]) * latitudeScale,
    );
}

function distanceToSegmentMeters(point, start, end) {
    const latitudeScale = 111320;
    const longitudeScale = 111320 * Math.cos(point[0] * Math.PI / 180);
    const pointX = (point[1] - start[1]) * longitudeScale;
    const pointY = (point[0] - start[0]) * latitudeScale;
    const endX = (end[1] - start[1]) * longitudeScale;
    const endY = (end[0] - start[0]) * latitudeScale;
    const lengthSquared = endX * endX + endY * endY;
    const position = lengthSquared > 0
        ? Math.max(0, Math.min(1, (pointX * endX + pointY * endY) / lengthSquared))
        : 0;

    return Math.hypot(pointX - position * endX, pointY - position * endY);
}

function setArrowHeading(start, end) {
    if (!simulatedMarker) return;

    const longitudeDelta = (end[1] - start[1]) * Math.cos((start[0] + end[0]) * Math.PI / 360);
    const latitudeDelta = end[0] - start[0];
    if (longitudeDelta === 0 && latitudeDelta === 0) return;

    const bearing = Math.atan2(longitudeDelta, latitudeDelta) * 180 / Math.PI;
    const icon = simulatedMarker.getElement()?.querySelector('span');
    if (icon) {
        icon.style.transform = `rotate(${bearing}deg)`;
    }
}

function poiLabel(type) {
    return poiCategories[type]?.label || 'POI';
}

function renderAlertPanel() {
    if (!alertList) return;

    alertList.replaceChildren();
    const visiblePois = routePois.filter(poi => (
        poi.type !== 'locality'
        && poiFilters.has(poi.type)
        && poi.id === latestReachedPoiId
    ));
    if (!visiblePois.length) {
        const empty = document.createElement('div');
        empty.className = 'navigation-alert-empty';
        empty.textContent = 'Niciun indicator întâlnit încă.';
        alertList.appendChild(empty);
        return;
    }

    visiblePois.forEach(poi => {
        const item = document.createElement('div');
        item.className = 'navigation-alert-item is-nearby';
        item.dataset.poiId = String(poi.id || '');

        const icon = document.createElement('span');
        icon.className = 'navigation-alert-icon';
        icon.textContent = poi.type === 'speed_limit'
            ? (String(poi.name || '').match(/\d+/)?.[0] || '!')
            : '!';

        const label = document.createElement('span');
        label.textContent = poi.name || poiLabel(poi.type);
        item.append(icon, label);
        alertList.appendChild(item);
    });
}

function poiAlertKey(poi) {
    if (poi.type === 'speed_limit') {
        return `${poi.type}:${poi.name || 'unknown'}`;
    }

    return String(poi.id);
}

function announcePoisOnSegment(start, end) {
    const segmentLength = distanceMeters(start, end);

    routePois.forEach(poi => {
        const alertKey = poiAlertKey(poi);
        if (amenityIcons[poi.type] || poi.type === 'locality' || announcedPoiIds.has(poi.id) || announcedPoiKeys.has(alertKey) || !poiFilters.has(poi.type)) return;

        const point = [Number(poi.coordinates.lat), Number(poi.coordinates.lon)];
        const alertDistance = Math.max(80, Math.min(180, segmentLength / 2));
        if (distanceToSegmentMeters(point, start, end) > alertDistance) return;

        announcedPoiIds.add(poi.id);
        announcedPoiKeys.add(alertKey);
        latestReachedPoiId = poi.id;
        renderAlertPanel();
        const label = poiLabel(poi.type);
        const name = poi.name && poi.name !== label ? `: ${poi.name}` : '';
        showToast(`Atenție, urmează ${label}${name}.`, poi.type === 'police' ? 'error' : 'info');
        setStatus(`În apropiere: ${label}${name}`, poi.type === 'police' ? 'text-danger' : 'text-warning');
    });
}

function setupPoiFilterButtons() {
    const inputs = [...document.querySelectorAll('[data-poi-filter]')];
    const menu = document.getElementById('navigation-poi-menu');
    const storageKey = 'navigation-poi-filters-v2';
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey));
        const legacy = JSON.parse(localStorage.getItem('navigation-poi-filters-v1'));
        const legacyTypes = ['fuel', 'parking', 'restaurant', 'cafe', 'lodging', 'supermarket', 'pharmacy', 'charging_station', 'police', 'speed_camera', 'speed_limit', 'traffic_sign', 'vignette_control', 'control', 'locality'];
        inputs.forEach(input => {
            const type = input.dataset.poiFilter;
            const enabled = saved && typeof saved[type] === 'boolean' ? saved[type]
                : Array.isArray(legacy) && legacyTypes.includes(type) ? legacy.includes(type)
                : poiCategories[type]?.default;
            if (enabled) poiFilters.add(type);
            else poiFilters.delete(type);
        });
    } catch { /* Storage may be unavailable in private browsing. */ }

    const applyState = () => {
        inputs.forEach(input => {
            const type = input.dataset.poiFilter;
            input.checked = poiFilters.has(type);
            if (!poiLayers[type]) return;
            if (input.checked) map.addLayer(poiLayers[type]);
            else map.removeLayer(poiLayers[type]);
        });
        document.getElementById('navigation-poi-count').textContent = `(${poiFilters.size})`;
        renderAlertPanel();
    };
    const saveState = () => {
        try { localStorage.setItem(storageKey, JSON.stringify(Object.fromEntries(inputs.map(input => [input.dataset.poiFilter, poiFilters.has(input.dataset.poiFilter)])))); } catch { /* Optional preference. */ }
        applyState();
    };
    inputs.forEach(input => input.addEventListener('change', () => {
        if (input.checked) poiFilters.add(input.dataset.poiFilter);
        else poiFilters.delete(input.dataset.poiFilter);
        saveState();
    }));
    document.querySelectorAll('[data-poi-select]').forEach(button => button.addEventListener('click', () => {
        poiFilters = new Set(button.dataset.poiSelect === 'all' ? inputs.map(input => input.dataset.poiFilter) : []);
        saveState();
    }));
    document.getElementById('navigation-poi-close')?.addEventListener('click', () => { menu.open = false; });
    menu?.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            menu.open = false;
            menu.querySelector('summary').focus();
        }
    });
    map.on('click', () => { if (menu) menu.open = false; });
    applyState();
}

function setStatus(message, className = 'text-muted') {
    status.textContent = message;
    status.className = className;
}

function showToast(message, tone = 'info') {
    if (!toastContainer) return;

    toastContainer.replaceChildren();
    const toast = document.createElement('div');
    toast.className = `navigation-toast navigation-toast-${tone}`;
    toast.textContent = message;
    toastContainer.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('show'));

    window.setTimeout(() => {
        toast.classList.remove('show');
        window.setTimeout(() => toast.remove(), 220);
    }, 2200);
}

function getLines(geometry) {
    if (!geometry) return [];
    if (geometry.type === 'Feature') return getLines(geometry.geometry);
    if (geometry.type === 'FeatureCollection') return geometry.features.flatMap(getLines);
    if (geometry.type === 'LineString') return [geometry.coordinates];
    if (geometry.type === 'MultiLineString') return geometry.coordinates;
    return [];
}

function getRoutePoints(lines) {
    return lines.flat().filter(point => Array.isArray(point) && point.length >= 2)
        .map(point => [Number(point[1]), Number(point[0])]);
}

function markerStyle(type) {
    return {
        radius: type === 'locality' ? 5 : 7,
        color: '#fff',
        weight: 2,
        fillColor: {
            police: '#1d4ed8',
            speed_camera: '#f59e0b',
            vignette_control: '#7c3aed',
            control: '#111827',
            locality: '#172554',
        }[type] || '#2563eb',
        fillOpacity: 0.95,
    };
}

function addPoi(poi) {
    const lat = Number(poi.coordinates?.lat);
    const lon = Number(poi.coordinates?.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

    const type = poi.type || 'control';
    if (!poiLayers[type]) {
        poiLayers[type] = L.layerGroup();
        if (poiFilters.has(type)) poiLayers[type].addTo(map);
    }


    if (poi.type === 'locality') {
        L.marker([lat, lon], {
            icon: L.divIcon({
                className: 'navigation-locality-label',
                html: `<span>${escapeHtml(poi.name || 'Localitate')}</span>`,
                iconSize: [0, 0],
                iconAnchor: [0, 0],
            }),
            interactive: false,
        }).addTo(poiLayers[type]);
        return;
    }

    if (amenityIcons[type]) {
        L.marker([lat, lon], {
            icon: L.divIcon({
                className: 'navigation-poi-marker',
                html: `<span>${amenityIcons[type]}</span>`,
                iconSize: [30, 30],
                iconAnchor: [15, 15],
            }),
        }).bindTooltip(escapeHtml(poi.name || poiLabel(type)))
            .bindPopup(`<strong>${escapeHtml(poi.name || poiLabel(type))}</strong><br>${escapeHtml(poiLabel(type))}`)
            .addTo(poiLayers[type]);
        return;
    }

    L.circleMarker([lat, lon], markerStyle(poi.type))
        .bindTooltip(escapeHtml(poi.name || 'POI'))
        .addTo(poiLayers[type]);
}

function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = value;
    return element.innerHTML;
}

async function loadPois() {
    try {
        const response = await fetch(page.dataset.routePoisUrl);
        if (!response.ok) return;
        const body = await response.json();
        routePois = Array.isArray(body.pois) ? body.pois.filter(poi => poi?.coordinates) : [];
        renderAlertPanel();
        routePois.forEach(addPoi);
    } catch (error) {
        console.warn('POI navigation unavailable', error);
    }
}

function updateCurrentPosition(position) {
    const point = [position.coords.latitude, position.coords.longitude];
    if (!currentMarker) {
        currentMarker = L.circleMarker(point, {
            radius: 9,
            color: '#fff',
            weight: 3,
            fillColor: '#f97316',
            fillOpacity: 1,
        }).addTo(map).bindTooltip('Poziția mea');
    } else {
        currentMarker.setLatLng(point);
    }
}

function post(url, body = {}) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        body: JSON.stringify(body),
    }).then(async response => {
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || 'Cererea nu a putut fi procesată.');
        return payload;
    });
}

function positionError(error) {
    const messages = { 1: 'Permisiunea pentru localizare a fost refuzată.', 2: 'Poziția nu este disponibilă.', 3: 'Obținerea poziției a expirat.' };
    const message = messages[error.code] || 'Nu am putut obține poziția GPS.';
    setStatus(message, 'text-danger');
    showToast(message, 'error');
}

async function savePosition(position) {
    updateCurrentPosition(position);
    if (!sessionId) return;
    await post('/tracking/point', {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: position.coords.accuracy,
        speed: position.coords.speed,
        heading: position.coords.heading,
        altitude: position.coords.altitude,
        tracked_at: new Date(position.timestamp).toISOString(),
    });
}

async function startNavigation() {
    if (!navigator.geolocation) {
        setStatus('Acest dispozitiv nu suportă localizarea.', 'text-danger');
        showToast('Localizarea nu este disponibilă pe acest dispozitiv.', 'error');
        return;
    }

    try {
        const response = await post('/tracking/start');
        sessionId = response.session.id;
        setStatus('Navigația este activă. Aștept poziția GPS...', 'text-success');
        showToast('Navigația a fost pornită.', 'success');
        watchId = navigator.geolocation.watchPosition(async position => {
            try {
                await savePosition(position);
                setStatus('Navigația este activă.', 'text-success');
            } catch (error) {
                setStatus(error.message, 'text-danger');
            }
        }, positionError, { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 });
    } catch (error) {
        setStatus(error.message, 'text-danger');
        showToast(error.message, 'error');
    }
}

async function stopNavigation() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    watchId = null;
    if (simulationTimer) {
        clearInterval(simulationTimer);
        simulationTimer = null;
    }
    if (sessionId) {
        await post('/tracking/stop').catch(error => {
            setStatus(error.message, 'text-danger');
            showToast(error.message, 'error');
        });
    }
    sessionId = null;
    showToast('Navigația s-a oprit.', 'info');
    window.location.assign(page.dataset.routesUrl);
}

function startSimulation(points) {
    if (!points.length) return;

    if (simulateButton) {
        simulateButton.textContent = 'Oprește Simularea';
        simulateButton.disabled = false;
    }

    if (simulationTimer) {
        clearInterval(simulationTimer);
    }

    simulationStep = 0;
    followSimulation = true;
    const routePoints = [...points];
    let segmentIndex = 0;
    let segmentProgress = 0;
    const metersPerTick = SIMULATION_SPEED_KMH * 1000 / 3600 * (SIMULATION_TICK_MS / 1000);

    if (!simulatedMarker) {
        simulatedMarker = L.marker(routePoints[0], {
            icon: L.divIcon({
                className: 'navigation-direction-marker',
                html: '<span class="navigation-direction-arrow" role="img" aria-label="Direcția de mers"><svg viewBox="0 0 34 34" aria-hidden="true"><path d="M17 3 L29 29 L17 23 L5 29 Z" fill="#2563eb" stroke="#fff" stroke-width="2.5" stroke-linejoin="round"/></svg></span>',
                iconSize: [34, 34],
                iconAnchor: [17, 17],
            }),
            zIndexOffset: 1000,
        }).addTo(map).bindTooltip('Direcția de mers - navigare simulată');
    }

    setArrowHeading(routePoints[0], routePoints[1]);
    simulatedMarker.setLatLng(routePoints[0]);
    map.setView(routePoints[0], 14, { animate: true });
    if (simulationSpeed) {
        simulationSpeed.hidden = false;
        simulationSpeed.textContent = `Viteză: ${SIMULATION_SPEED_KMH} km/h`;
    }
    setStatus('Navigație simulată activă.', 'text-success');
    showToast('Navigația simulată a pornit.', 'success');

    simulationTimer = setInterval(() => {
        let remainingMeters = metersPerTick;
        let reachedSegmentEnd = false;
        const previousPoint = simulatedMarker.getLatLng();

        while (remainingMeters > 0 && segmentIndex < routePoints.length - 1) {
            const segmentStart = routePoints[segmentIndex];
            const segmentEnd = routePoints[segmentIndex + 1];
            const segmentLength = distanceMeters(segmentStart, segmentEnd);

            if (segmentLength === 0) {
                segmentIndex += 1;
                segmentProgress = 0;
                continue;
            }

            const remainingSegmentMeters = segmentLength * (1 - segmentProgress);
            if (remainingMeters < remainingSegmentMeters) {
                segmentProgress += remainingMeters / segmentLength;
                remainingMeters = 0;
                break;
            }

            remainingMeters -= remainingSegmentMeters;
            segmentIndex += 1;
            segmentProgress = 0;
            reachedSegmentEnd = true;
        }

        if (segmentIndex >= routePoints.length - 1) {
            clearInterval(simulationTimer);
            simulationTimer = null;
            if (simulateButton) simulateButton.textContent = 'Simulare Traseu';
            if (simulationSpeed) simulationSpeed.hidden = true;
            setStatus('Simularea s-a încheiat.', 'text-muted');
            simulatedMarker.setLatLng(routePoints[routePoints.length - 1]);
            showToast('Simularea a ajuns la finalul traseului.', 'info');
            return;
        }

        const segmentStart = routePoints[segmentIndex];
        const segmentEnd = routePoints[segmentIndex + 1];
        setArrowHeading(segmentStart, segmentEnd);
        const point = [
            segmentStart[0] + (segmentEnd[0] - segmentStart[0]) * segmentProgress,
            segmentStart[1] + (segmentEnd[1] - segmentStart[1]) * segmentProgress,
        ];
        simulatedMarker.setLatLng(point);
        if (followSimulation && !mapZooming) {
            map.panTo(point, { animate: false });
        }
        if (simulationSpeed) {
            simulationSpeed.textContent = `Viteză: ${SIMULATION_SPEED_KMH} km/h`;
        }
        if (reachedSegmentEnd) {
            announcePoisOnSegment(previousPoint, routePoints[segmentIndex]);
        }
        keepRouteInFront();
    }, SIMULATION_TICK_MS);
}

function bindControls() {
    simulateButton?.addEventListener('click', async () => {
        if (page.dataset.simulate === '1') {
            if (simulationTimer !== null) {
                clearInterval(simulationTimer);
                simulationTimer = null;
                const points = getRoutePoints(getLines(JSON.parse(page.dataset.routeGeometry)));
                simulationStep = 0;
                if (points.length >= 2) {
                    simulatedMarker.setLatLng(points[0]);
                    setArrowHeading(points[0], points[1]);
                    map.stop();
                    map.setView(points[0], map.getZoom(), { animate: false });
                }
                announcedPoiIds.clear();
                announcedPoiKeys.clear();
                latestReachedPoiId = null;
                renderAlertPanel();
                simulateButton.textContent = 'Simulare Traseu';
                if (simulationSpeed) simulationSpeed.hidden = true;
                setStatus('Simularea este oprită.', 'text-muted');
                showToast('Simularea s-a oprit. Ai revenit la începutul traseului.', 'info');
            } else {
                startSimulation(getRoutePoints(getLines(JSON.parse(page.dataset.routeGeometry))));
            }
            return;
        }
        simulateButton.disabled = true;
        try {
            if (sessionId) await post('/tracking/stop');
            sessionId = null;
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            watchId = null;
            const url = new URL(window.location.href);
            url.searchParams.set('simulate', '1');
            window.location.assign(url.href);
        } catch (error) {
            showToast(error.message, 'error');
            simulateButton.disabled = false;
        }
    });

    const changeZoom = delta => {
        if (!map) return;
        map.stop();
        const nextZoom = Math.max(map.getMinZoom(), Math.min(map.getMaxZoom(), map.getZoom() + delta));
        map.setZoom(nextZoom, { animate: false });
    };

    document.getElementById('navigation-zoom-in')?.addEventListener('click', () => changeZoom(1));
    document.getElementById('navigation-zoom-out')?.addEventListener('click', () => changeZoom(-1));

    document.getElementById('navigation-fit')?.addEventListener('click', () => {
        if (routeBounds) {
            followSimulation = false;
            map.stop();
            map.fitBounds(routeBounds, { padding: [40, 40], animate: true });
            keepRouteInFront();
            showToast('Traseul este centrat pe hartă.', 'info');
        }
    });

    document.getElementById('navigation-locate')?.addEventListener('click', () => {
        if (page.dataset.simulate === '1' && simulatedMarker) {
            followSimulation = true;
            map.stop();
            map.setView(simulatedMarker.getLatLng(), map.getZoom(), { animate: false });
            return;
        }
        if (!navigator.geolocation) {
            setStatus('Acest dispozitiv nu suportă localizarea.', 'text-danger');
            showToast('Localizarea nu este disponibilă pe acest dispozitiv.', 'error');
            return;
        }

        navigator.geolocation.getCurrentPosition(position => {
            updateCurrentPosition(position);
            map.setView([position.coords.latitude, position.coords.longitude], 17, { animate: true });
            keepRouteInFront();
            setStatus('Poziția curentă a fost actualizată.', 'text-success');
            showToast('Poziția curentă a fost actualizată.', 'success');
        }, error => {
            positionError(error);
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 5000 });
    });

    stopButton?.addEventListener('click', () => {
        showToast('Se oprește navigația.', 'info');
        stopNavigation();
    });
}

function initialize() {
    const geometry = JSON.parse(page.dataset.routeGeometry);
    const lines = getLines(geometry);
    const points = getRoutePoints(lines);
    if (points.length < 2) return;

    map = L.map('navigation-map', { zoomControl: false, attributionControl: false, minZoom: 2, maxZoom: 19 });
    map.on('zoomstart', () => { mapZooming = true; });
    map.on('zoomend', () => { mapZooming = false; });
    map.on('dragstart', () => { followSimulation = false; });
    document.querySelectorAll('.navigation-map-tools, .navigation-map-filters, .navigation-alert-panel').forEach(element => {
        L.DomEvent.disableClickPropagation(element);
        L.DomEvent.disableScrollPropagation(element);
    });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors',
    }).addTo(map);

    poiLayers = {
        police: L.layerGroup().addTo(map),
        speed_camera: L.layerGroup().addTo(map),
        vignette_control: L.layerGroup().addTo(map),
        control: L.layerGroup().addTo(map),
        locality: L.layerGroup().addTo(map),
    };

    setupPoiFilterButtons();

    const routeLine = L.polyline(points, { color: '#2563eb', weight: 7, opacity: 1, interactive: false }).addTo(map);
    routeLayer = routeLine;
    routeBounds = routeLine.getBounds();
    L.circleMarker(points[0], { radius: 9, color: '#fff', weight: 3, fillColor: '#198754', fillOpacity: 1 }).addTo(map);
    L.circleMarker(points[points.length - 1], { radius: 9, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 }).addTo(map);

    if (page.dataset.simulate === '1') {
        showToast('Simularea afișează indicatoare și obstacole de pe traseu.', 'info');
    }

    map.fitBounds(routeBounds, { padding: [40, 40] });
    routeLayer.bringToFront();
    L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap contributors').addTo(map);

    bindControls();
    setupTraffic();
    loadPois().finally(async () => {
        if (page.dataset.simulate === '1') {
            startSimulation(points);
            return;
        }

        await startNavigation();
        if (simulateButton) simulateButton.disabled = false;
    });
}

function setupTraffic() {
    const toggle = document.getElementById('navigation-realtime');
    const state = document.getElementById('navigation-realtime-state');
    const message = document.getElementById('navigation-traffic-status');
    const legend = document.getElementById('navigation-traffic-legend');
    let layer = null;
    let timer = null;
    let failed = false;
    map.createPane('traffic');
    map.getPane('traffic').style.zIndex = '450';
    map.getPane('traffic').style.pointerEvents = 'none';

    const removeLayer = () => {
        if (layer) {
            layer.off();
            map.removeLayer(layer);
            layer = null;
        }
    };
    const refresh = () => {
        if (!toggle.checked || document.hidden) return;
        removeLayer();
        failed = false;
        message.textContent = 'Se încarcă traficul TomTom...';
        layer = L.tileLayer(`${page.dataset.trafficUrl}?refresh=${Date.now()}`, {
            pane: 'traffic', maxZoom: 19, updateWhenIdle: true, keepBuffer: 0,
            attribution: 'Trafic © TomTom',
        });
        layer.on('tileerror', () => {
            failed = true;
            message.textContent = 'Trafic indisponibil sau incomplet. Reîncercare automată într-un minut.';
        });
        layer.on('load', () => {
            if (!failed) message.textContent = `Trafic încărcat la ${new Date().toLocaleTimeString('ro-RO')}. Actualizare la 60 s.`;
        });
        layer.addTo(map);
    };
    const apply = () => {
        window.clearInterval(timer);
        timer = null;
        removeLayer();
        if (toggle.checked && page.dataset.trafficConfigured !== '1') {
            toggle.checked = false;
            message.textContent = 'Trafic indisponibil: cheia TomTom nu este configurată pe server.';
        } else if (toggle.checked) {
            refresh();
            timer = window.setInterval(refresh, 60000);
        } else {
            message.textContent = 'Trafic TomTom oprit.';
        }
        state.textContent = toggle.checked ? 'On' : 'Off';
        legend.hidden = !toggle.checked;
    };
    toggle.addEventListener('change', apply);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) removeLayer();
        else refresh();
    });
    window.addEventListener('pagehide', () => {
        window.clearInterval(timer);
        removeLayer();
    });
    window.addEventListener('pageshow', event => { if (event.persisted) apply(); });
}

if (page) initialize();
