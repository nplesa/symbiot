import Map from 'ol/Map.js';
import View from 'ol/View.js';
import Overlay from 'ol/Overlay.js';
import { defaults as defaultControls } from 'ol/control/defaults.js';
import TileLayer from 'ol/layer/Tile.js';
import VectorLayer from 'ol/layer/Vector.js';
import XYZ from 'ol/source/XYZ.js';
import VectorSource from 'ol/source/Vector.js';
import Feature from 'ol/Feature.js';
import Point from 'ol/geom/Point.js';
import LineString from 'ol/geom/LineString.js';
import { fromLonLat, toLonLat } from 'ol/proj.js';
import Style from 'ol/style/Style.js';
import Fill from 'ol/style/Fill.js';
import Stroke from 'ol/style/Stroke.js';
import CircleStyle from 'ol/style/Circle.js';
import Text from 'ol/style/Text.js';
import 'ol/ol.css';
import { shouldSendPoint } from '../navigation-tracking.js';
import { MAP_ATTRIBUTION } from '../map-attribution.js';
import { t, intlLocale } from '../i18n.js';

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
let mapZoomingTimer = null;
let followSimulation = true;
let sessionId = null;
let watchId = null;
// Last point stored on the server and whether one is being sent (fixes arrive ~1/s).
let lastSentPoint = null;
let pointRequestBusy = false;
// One toast per streak of GPS errors; reset by the next good fix.
let gpsErrorNotified = false;
// Set when pagehide ended the session; a page restored from the back/forward cache is then reloaded.
let sessionStoppedByUnload = false;
let currentMarker = null;
let routeLayer = null;
let simulationTimer = null;
let simulationStep = 0;
let simulatedMarker = null;
let poiLayers = {};
let poiSources = {};
let routeSource = null;
let currentPositionSource = null;
let simulationSource = null;
let poiPopup = null;
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
    routeLayer?.setZIndex(10);
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
    simulatedMarker.set('heading', bearing);
    simulatedMarker.changed();
}

function poiLabel(type) {
    return poiCategories[type]?.label || t('POI');
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
        empty.textContent = t('No signs passed yet.');
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
        showToast(t('Watch out, :sign ahead.', { sign: `${label}${name}` }), poi.type === 'police' ? 'error' : 'info');
        setStatus(t('Nearby: :sign', { sign: `${label}${name}` }), poi.type === 'police' ? 'text-danger' : 'text-warning');
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
            poiLayers[type].setVisible(input.checked);
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

const poiColors = {
    police: '#1d4ed8', speed_camera: '#f59e0b', vignette_control: '#7c3aed',
    control: '#111827', locality: '#172554',
};

function pointStyle(color, radius = 7, text = '') {
    return new Style({
        image: new CircleStyle({ radius, fill: new Fill({ color }), stroke: new Stroke({ color: '#fff', width: 2 }) }),
        text: text ? new Text({ text, font: '700 13px sans-serif', fill: new Fill({ color: '#172554' }), stroke: new Stroke({ color: '#fff', width: 3 }), offsetY: -14 }) : undefined,
    });
}

function getPoiLayer(type) {
    if (!poiLayers[type]) {
        poiSources[type] = new VectorSource();
        poiLayers[type] = new VectorLayer({ source: poiSources[type], visible: poiFilters.has(type), style: feature => {
            const featureType = feature.get('poiType');
            if (featureType === 'locality') {
                return new Style({ text: new Text({ text: feature.get('name') || t('Locality'), font: '700 14px sans-serif', fill: new Fill({ color: '#172554' }), stroke: new Stroke({ color: '#fff', width: 4 }) }) });
            }
            const icon = feature.get('icon');
            if (icon) return new Style({
                image: new CircleStyle({ radius: 13, fill: new Fill({ color: '#fff' }), stroke: new Stroke({ color: '#2563eb', width: 2 }) }),
                text: new Text({ text: icon, font: '700 17px sans-serif', fill: new Fill({ color: '#1d4ed8' }) }),
            });
            return pointStyle(poiColors[featureType] || '#2563eb', 7);
        }});
        map.addLayer(poiLayers[type]);
    }
    return poiSources[type];
}

function addPoi(poi) {
    const lat = Number(poi.coordinates?.lat);
    const lon = Number(poi.coordinates?.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

    const type = poi.type || 'control';
    const feature = new Feature({ geometry: new Point(fromLonLat([lon, lat])), poiType: type, poi, name: poi.name || poiLabel(type), icon: amenityIcons[type] || '' });
    getPoiLayer(type).addFeature(feature);
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
    const coordinate = fromLonLat([position.coords.longitude, position.coords.latitude]);
    if (!currentMarker) {
        currentMarker = new Feature({ geometry: new Point(coordinate) });
        currentPositionSource.addFeature(currentMarker);
    } else {
        currentMarker.getGeometry().setCoordinates(coordinate);
    }
}

function post(url, body = {}) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        body: JSON.stringify(body),
    }).then(async response => {
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || t('The request could not be processed.'));
        return payload;
    });
}

function positionError(error) {
    // Timeouts are routine with a weak signal (tunnels): the watch keeps running, so only update the status.
    if (error.code === 3) {
        setStatus(t('Weak GPS signal, waiting for position…'), 'text-warning');
        return;
    }

    const messages = { 1: t('Location permission was denied.'), 2: t('Position is not available.') };
    const message = messages[error.code] || t('Could not get the GPS position.');
    setStatus(message, 'text-danger');
    if (!gpsErrorNotified) {
        gpsErrorNotified = true;
        showToast(message, 'error');
    }
}

async function savePosition(position) {
    updateCurrentPosition(position);
    gpsErrorNotified = false;
    if (!sessionId || pointRequestBusy) return;

    const now = Date.now();
    if (!shouldSendPoint(lastSentPoint, position.coords, now)) return;

    pointRequestBusy = true;
    try {
        await post('/tracking/point', {
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
            speed: position.coords.speed,
            heading: position.coords.heading,
            altitude: position.coords.altitude,
            tracked_at: new Date(position.timestamp).toISOString(),
        });
        lastSentPoint = { lat: position.coords.latitude, lon: position.coords.longitude, at: now };
    } finally {
        pointRequestBusy = false;
    }
}

/** Closing the tab must still end the server session; sendBeacon survives page unload. */
function stopSessionOnUnload() {
    if (!sessionId || !navigator.sendBeacon) return;

    const data = new FormData();
    data.append('_token', csrfToken || '');
    if (navigator.sendBeacon('/tracking/stop', data)) {
        sessionId = null;
        sessionStoppedByUnload = true;
    }
}

async function startNavigation() {
    if (!navigator.geolocation) {
        setStatus(t('This device does not support location.'), 'text-danger');
        showToast(t('Location is not available on this device.'), 'error');
        return;
    }

    try {
        const response = await post('/tracking/start');
        sessionId = response.session.id;
        setStatus(t('Navigation is active. Waiting for GPS position...'), 'text-success');
        showToast(t('Navigation started.'), 'success');
        watchId = navigator.geolocation.watchPosition(async position => {
            try {
                await savePosition(position);
                setStatus(t('Navigation is active.'), 'text-success');
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
    showToast(t('Navigation stopped.'), 'info');
    window.location.assign(page.dataset.routesUrl);
}

function startSimulation(points) {
    if (!points.length) return;

    if (simulateButton) {
        simulateButton.textContent = t('Stop simulation');
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
        simulatedMarker = new Feature({ geometry: new Point(fromLonLat([routePoints[0][1], routePoints[0][0]])), heading: 0 });
        simulationSource.addFeature(simulatedMarker);
    }

    setArrowHeading(routePoints[0], routePoints[1]);
    simulatedMarker.getGeometry().setCoordinates(fromLonLat([routePoints[0][1], routePoints[0][0]]));
    map.getView().animate({ center: fromLonLat([routePoints[0][1], routePoints[0][0]]), zoom: 14, duration: 500 });
    if (simulationSpeed) {
        simulationSpeed.hidden = false;
        simulationSpeed.textContent = t('Speed: :speed km/h', { speed: SIMULATION_SPEED_KMH });
    }
    setStatus(t('Simulated navigation active.'), 'text-success');
    showToast(t('Simulated navigation started.'), 'success');

    simulationTimer = setInterval(() => {
        let remainingMeters = metersPerTick;
        let reachedSegmentEnd = false;
        const previousLonLat = toLonLat(simulatedMarker.getGeometry().getCoordinates());
        const previousPoint = [previousLonLat[1], previousLonLat[0]];

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
            if (simulateButton) simulateButton.textContent = t('Simulate route');
            if (simulationSpeed) simulationSpeed.hidden = true;
            setStatus(t('Simulation finished.'), 'text-muted');
            simulatedMarker.getGeometry().setCoordinates(fromLonLat([routePoints.at(-1)[1], routePoints.at(-1)[0]]));
            showToast(t('The simulation reached the end of the route.'), 'info');
            return;
        }

        const segmentStart = routePoints[segmentIndex];
        const segmentEnd = routePoints[segmentIndex + 1];
        setArrowHeading(segmentStart, segmentEnd);
        const point = [
            segmentStart[0] + (segmentEnd[0] - segmentStart[0]) * segmentProgress,
            segmentStart[1] + (segmentEnd[1] - segmentStart[1]) * segmentProgress,
        ];
        simulatedMarker.getGeometry().setCoordinates(fromLonLat([point[1], point[0]]));
        if (followSimulation && !mapZooming) {
            map.getView().setCenter(fromLonLat([point[1], point[0]]));
        }
        if (simulationSpeed) {
            simulationSpeed.textContent = t('Speed: :speed km/h', { speed: SIMULATION_SPEED_KMH });
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
                    simulatedMarker.getGeometry().setCoordinates(fromLonLat([points[0][1], points[0][0]]));
                    setArrowHeading(points[0], points[1]);
                    map.getView().cancelAnimations();
                    map.getView().setCenter(fromLonLat([points[0][1], points[0][0]]));
                }
                announcedPoiIds.clear();
                announcedPoiKeys.clear();
                latestReachedPoiId = null;
                renderAlertPanel();
                simulateButton.textContent = t('Simulate route');
                if (simulationSpeed) simulationSpeed.hidden = true;
                setStatus(t('Simulation is stopped.'), 'text-muted');
                showToast(t('Simulation stopped. You are back at the start of the route.'), 'info');
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
        const view = map.getView();
        view.cancelAnimations();
        const nextZoom = Math.max(view.getMinZoom(), Math.min(view.getMaxZoom(), view.getZoom() + delta));
        view.setZoom(nextZoom);
    };

    document.getElementById('navigation-zoom-in')?.addEventListener('click', () => changeZoom(1));
    document.getElementById('navigation-zoom-out')?.addEventListener('click', () => changeZoom(-1));

    document.getElementById('navigation-fit')?.addEventListener('click', () => {
        if (routeBounds) {
            followSimulation = false;
            map.getView().cancelAnimations();
            map.getView().fit(routeBounds, { padding: [40, 40], duration: 500 });
            keepRouteInFront();
            showToast(t('The route is centred on the map.'), 'info');
        }
    });

    document.getElementById('navigation-locate')?.addEventListener('click', () => {
        if (page.dataset.simulate === '1' && simulatedMarker) {
            followSimulation = true;
            map.getView().cancelAnimations();
            map.getView().setCenter(simulatedMarker.getGeometry().getCoordinates());
            return;
        }
        if (!navigator.geolocation) {
            setStatus(t('This device does not support location.'), 'text-danger');
            showToast(t('Location is not available on this device.'), 'error');
            return;
        }

        navigator.geolocation.getCurrentPosition(position => {
            updateCurrentPosition(position);
            map.getView().animate({ center: fromLonLat([position.coords.longitude, position.coords.latitude]), zoom: 17, duration: 500 });
            keepRouteInFront();
            setStatus(t('Current position updated.'), 'text-success');
            showToast(t('Current position updated.'), 'success');
        }, error => {
            positionError(error);
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 5000 });
    });

    stopButton?.addEventListener('click', () => {
        showToast(t('Stopping navigation.'), 'info');
        stopNavigation();
    });
}

function initialize() {
    const geometry = JSON.parse(page.dataset.routeGeometry);
    const lines = getLines(geometry);
    const points = getRoutePoints(lines);
    if (points.length < 2) return;

    const routeCoordinates = points.map(([lat, lon]) => fromLonLat([lon, lat]));
    routeSource = new VectorSource();
    currentPositionSource = new VectorSource();
    simulationSource = new VectorSource();
    const routeLineFeature = new Feature({ geometry: new LineString(routeCoordinates), featureType: 'route' });
    const startFeature = new Feature({ geometry: new Point(routeCoordinates[0]), featureType: 'start' });
    const endFeature = new Feature({ geometry: new Point(routeCoordinates.at(-1)), featureType: 'end' });
    routeSource.addFeatures([routeLineFeature, startFeature, endFeature]);
    routeLayer = new VectorLayer({
        source: routeSource,
        style: feature => {
            const type = feature.get('featureType');
            if (type === 'route') return new Style({ stroke: new Stroke({ color: '#2563eb', width: 7, lineCap: 'round', lineJoin: 'round' }) });
            return pointStyle(type === 'start' ? '#198754' : '#2563eb', 9);
        },
        zIndex: 10,
    });
    const currentLayer = new VectorLayer({ source: currentPositionSource, style: pointStyle('#f97316', 9), zIndex: 15 });
    const simulationLayer = new VectorLayer({
        source: simulationSource,
        style: feature => new Style({
            text: new Text({ text: '▲', font: '700 30px sans-serif', fill: new Fill({ color: '#2563eb' }), stroke: new Stroke({ color: '#fff', width: 3 }), rotateWithView: true, rotation: (Number(feature.get('heading')) || 0) * Math.PI / 180 }),
        }),
        zIndex: 20,
    });
    map = new Map({
        target: 'navigation-map',
        controls: defaultControls({ zoom: false, rotate: false }),
        layers: [new TileLayer({ source: new XYZ({ url: '/map/tiles/{z}/{x}/{y}', maxZoom: 20, attributions: MAP_ATTRIBUTION }) }), routeLayer, currentLayer, simulationLayer],
        view: new View({ center: routeCoordinates[0], zoom: 10, minZoom: 2, maxZoom: 19 }),
    });
    document.querySelectorAll('.navigation-map-tools, .navigation-map-filters, .navigation-alert-panel').forEach(element => {
        ['pointerdown', 'click', 'dblclick', 'wheel'].forEach(type => element.addEventListener(type, event => event.stopPropagation()));
    });
    map.on('pointerdrag', () => { followSimulation = false; });
    map.getView().on('change:resolution', () => {
        mapZooming = true;
        window.clearTimeout(mapZoomingTimer);
        mapZoomingTimer = window.setTimeout(() => { mapZooming = false; }, 200);
    });
    const popupElement = document.createElement('div');
    popupElement.className = 'navigation-poi-popup';
    poiPopup = new Overlay({ element: popupElement, offset: [0, -14], positioning: 'bottom-center', stopEvent: true, autoPan: { animation: { duration: 200 } } });
    map.addOverlay(poiPopup);
    map.on('click', event => {
        if (document.getElementById('navigation-poi-menu')) document.getElementById('navigation-poi-menu').open = false;
        const feature = map.forEachFeatureAtPixel(event.pixel, candidate => candidate, {
            hitTolerance: 5,
            layerFilter: layer => Object.values(poiLayers).includes(layer),
        });
        const poi = feature?.get('poi');
        if (!poi) {
            poiPopup.setPosition(undefined);
            return;
        }
        popupElement.replaceChildren();
        const title = document.createElement('strong');
        title.textContent = poi.name || poiLabel(poi.type);
        const category = document.createElement('div');
        category.textContent = poiLabel(poi.type);
        popupElement.append(title, category);
        poiPopup.setPosition(event.coordinate);
    });

    poiLayers = {};
    poiSources = {};

    setupPoiFilterButtons();

    routeBounds = routeLineFeature.getGeometry().getExtent();

    if (page.dataset.simulate === '1') {
        showToast(t('The simulation shows signs and obstacles along the route.'), 'info');
    }

    map.getView().fit(routeBounds, { padding: [40, 40] });

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
    const removeLayer = () => {
        if (layer) {
            layer.getSource().un('tileloaderror', onTileError);
            layer.getSource().un('tileloadend', onTileLoad);
            map.removeLayer(layer);
            layer = null;
        }
    };
    const onTileError = () => {
        failed = true;
        message.textContent = t('Traffic unavailable or incomplete. Retrying automatically in one minute.');
    };
    const onTileLoad = () => {
        if (!failed) message.textContent = t('Traffic loaded at :time. Refreshes every 60 s.', { time: new Date().toLocaleTimeString(intlLocale) });
    };
    const refresh = () => {
        if (!toggle.checked || document.hidden) return;
        removeLayer();
        failed = false;
        message.textContent = t('Loading TomTom traffic...');
        layer = new TileLayer({ source: new XYZ({ url: `${page.dataset.trafficUrl}?refresh=${Date.now()}`, maxZoom: 19, attributions: t('Traffic © TomTom') }), zIndex: 5, preload: 0 });
        layer.getSource().on('tileloaderror', onTileError);
        layer.getSource().on('tileloadend', onTileLoad);
        map.addLayer(layer);
    };
    const apply = () => {
        window.clearInterval(timer);
        timer = null;
        removeLayer();
        if (toggle.checked && page.dataset.trafficConfigured !== '1') {
            toggle.checked = false;
            message.textContent = t('Traffic unavailable: the TomTom key is not configured on the server.');
        } else if (toggle.checked) {
            refresh();
            timer = window.setInterval(refresh, 60000);
        } else {
            message.textContent = t('TomTom traffic is off.');
        }
        state.textContent = toggle.checked ? t('On') : t('Off');
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

if (page) {
    initialize();
    window.addEventListener('pagehide', stopSessionOnUnload);
    // Back/forward cache restored a page whose session was already stopped: start from a clean state.
    window.addEventListener('pageshow', event => {
        if (event.persisted && sessionStoppedByUnload) window.location.reload();
    });
}
