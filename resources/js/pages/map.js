import 'ol/ol.css';
import { watchTransitCoverage } from '../map/transit-coverage.js';
import { createTransitLayer } from '../map/transit-stops-layer.js';
import { safeExternalUrl } from '../safe-url.js';
import { fetchJson, HttpError } from '../http.js';
import { MAP_ATTRIBUTION } from '../map-attribution.js';
import { t, intlLocale, formatNumber } from '../i18n.js';

import Map from 'ol/Map.js';
import View from 'ol/View.js';
import Overlay from 'ol/Overlay.js';

import TileLayer from 'ol/layer/Tile.js';
import VectorLayer from 'ol/layer/Vector.js';

import XYZ from 'ol/source/XYZ.js';
import VectorSource from 'ol/source/Vector.js';
import Cluster from 'ol/source/Cluster.js';

import Feature from 'ol/Feature.js';

import Point from 'ol/geom/Point.js';
import LineString from 'ol/geom/LineString.js';

import { fromLonLat } from 'ol/proj.js';
import { containsExtent } from 'ol/extent.js';

import Style from 'ol/style/Style.js';
import Icon from 'ol/style/Icon.js';
import Fill from 'ol/style/Fill.js';
import Stroke from 'ol/style/Stroke.js';
import CircleStyle from 'ol/style/Circle.js';
import Text from 'ol/style/Text.js';

import Circle from 'ol/geom/Circle.js';

import Polygon from 'ol/geom/Polygon.js';
import { circular } from 'ol/geom/Polygon.js';
import GeoJSON from 'ol/format/GeoJSON.js';
import MultiLineString from 'ol/geom/MultiLineString.js';
import {
    assignTransitRouteColors,
    bindPoiMapClick,
    buildPoiCategoryRequests,
    fetchPoiJsonResponse,
    getPoiMarkerIcon,
    groupTransitRoutes,
    getGoogleMapsPlaceUrl,
    getInfoferStationUrl,
    getMetroArrivalWindow,
    getTransitRouteColor,
    poiFeatureAtPixel,
    splitTransitRouteDirections,
} from '../map/poi-map-interactions.js';


let tracking = false;
let watchId = null;

let userFeature = null;
let trackFeature = null;

let lastCoords = null;
let lastPosition = null;

// Direction of travel in radians, clockwise from north; null until the user actually moves.
let heading = null;
let hasCentered = false;

let lastSent = 0;

let currentUserLocation = null;

const radiusEl = document.getElementById('radius');

const app_unit =
    radiusEl?.dataset?.unit?.toLowerCase() ?? 'm';

let app_radius =
    Number(radiusEl?.value ?? 1000);

if(app_unit === 'km') {
    app_radius *= 1000;
}
const deviceRadiusMeters = app_radius;
let cityRadiusMeters = 5000;
let fixedCityLocation = null;
let cityBoundaryFeature = null;
let cityBoundaryRadiusMeters = null;
let citywideSearch = false;
let pendingCityResults = [];

let radiusFeature = null;

const dashboardRadiusInput = document.getElementById('dashboard-search-radius');
const dashboardRadiusControl = document.getElementById('dashboard-radius-control');
const dashboardRadiusOutput = document.getElementById('dashboard-radius-value');
const dashboardCitywide = document.getElementById('dashboard-citywide');
const dashboardCitywideWrap = document.getElementById('dashboard-citywide-wrap');

function formatSearchRadius(meters) {
    return meters >= 1000
        ? `${(meters / 1000).toLocaleString(intlLocale, { maximumFractionDigits: 1 })} km`
        : `${meters.toLocaleString(intlLocale)} m`;
}

function syncDashboardRadius(meters, visible = true) {
    if (dashboardRadiusInput) {
        dashboardRadiusInput.value = String(meters);
        dashboardRadiusInput.disabled = citywideSearch;
    }
    if (dashboardRadiusOutput) dashboardRadiusOutput.value = citywideSearch ? t('Whole city') : formatSearchRadius(meters);
    const legacyRadiusInput = document.getElementById('city-location-radius');
    if (legacyRadiusInput) {
        legacyRadiusInput.value = String(meters);
        legacyRadiusInput.disabled = citywideSearch;
    }
    dashboardRadiusControl?.classList.toggle('d-none', !visible);
    dashboardCitywideWrap?.classList.toggle('d-none', !fixedCityLocation);
}

window.addEventListener('symbiot:location-changing', () => {
    citywideSearch = false;
    cityBoundaryFeature = null;
    cityBoundaryRadiusMeters = null;
    dashboardCitywide && (dashboardCitywide.checked = false);
    if (dashboardRadiusInput) dashboardRadiusInput.disabled = false;
    cityBoundarySource?.clear();
    syncDashboardRadius(app_radius, false);
});

dashboardRadiusInput?.addEventListener('input', () => {
    const radiusMeters = Number(dashboardRadiusInput.value);
    if (!Number.isFinite(radiusMeters)) return;
    app_radius = radiusMeters;
    if (fixedCityLocation) cityRadiusMeters = radiusMeters;
    if (radiusFeature) {
        const center = fixedCityLocation ?? currentUserLocation;
        if (center && Number.isFinite(Number(center.lat)) && Number.isFinite(Number(center.lon))) {
            radiusFeature.setGeometry(
                circular([Number(center.lon), Number(center.lat)], radiusMeters, 64)
                    .transform('EPSG:4326', 'EPSG:3857')
            );
        }
    }
    if (dashboardRadiusOutput) dashboardRadiusOutput.value = citywideSearch ? t('Whole city') : formatSearchRadius(radiusMeters);
    const legacyRadiusInput = document.getElementById('city-location-radius');
    if (legacyRadiusInput) legacyRadiusInput.value = String(radiusMeters);
});

async function refreshLocationData(center) {
    const radiusMeters = Number(app_radius);
    const status = document.getElementById('city-location-status');
    const transitRadius = Math.min(radiusMeters || 1500, 35000);
    const selectedFilters = selectedPoiFilterTokens(document.getElementById('mobility_card'));
    watchTransitCoverage(center.lat, center.lon, status, async () => {
        if (selectedFilters.includes('bus')) {
            try {
                await transitStops.load(center.lat, center.lon, transitRadius);
                transitStops.watchVehicles(center.lat, center.lon);
            } catch (error) {
                console.error('Transit stops reload failed:', error);
            }
        }
        if (selectedFilters.length > 0) await loadNearby(center);
    }, transitRadius);
}

dashboardRadiusInput?.addEventListener('change', () => {
    const radiusMeters = Number(dashboardRadiusInput.value);
    const center = fixedCityLocation ?? currentUserLocation;
    if (!center || !Number.isInteger(radiusMeters) || radiusMeters < 100 || radiusMeters > 35000) return;
    cityRadiusMeters = radiusMeters;
    refreshLocationData(center);
});

dashboardCitywide?.addEventListener('change', async () => {
    if (!fixedCityLocation) return;
    const center = fixedCityLocation;
    const radiusStatus = document.getElementById('dashboard-radius-status');
    if (radiusStatus) radiusStatus.textContent = '';
    if (!dashboardCitywide.checked) {
        citywideSearch = false;
        cityBoundaryFeature = null;
        cityBoundaryRadiusMeters = null;
        cityBoundarySource.clear();
        app_radius = cityRadiusMeters;
        radiusFeature?.setGeometry(circular([center.lon, center.lat], app_radius, 64).transform('EPSG:4326', 'EPSG:3857'));
        syncDashboardRadius(app_radius, true);
        const radiusExtent = radiusFeature?.getGeometry()?.getExtent();
        const mapSize = map.getSize();
        if (radiusExtent && mapSize) {
            map.getView().fit(radiusExtent, {
                size: mapSize,
                padding: [36, 36, 36, 36],
                maxZoom: 16,
                duration: 350,
            });
        }
        refreshLocationData(center);
        return;
    }

    dashboardCitywide.disabled = true;
    try {
        const params = new URLSearchParams({ lat: String(center.lat), lon: String(center.lon) });
        if (center.placeId) params.set('id', center.placeId);
        const payload = await fetchJson(`/api/location/city-boundary?${params}`);
        if (!payload?.feature?.geometry) throw new Error(t('Could not load the city boundary.'));

        cityBoundaryFeature = payload.feature;
        cityBoundaryRadiusMeters = 0;
        const visitCoordinates = (coordinates) => {
            if (!Array.isArray(coordinates)) return;
            if (typeof coordinates[0] === 'number' && typeof coordinates[1] === 'number') {
                cityBoundaryRadiusMeters = Math.max(cityBoundaryRadiusMeters, getDistanceMeters(center.lat, center.lon, coordinates[1], coordinates[0]));
                return;
            }
            coordinates.forEach(visitCoordinates);
        };
        visitCoordinates(cityBoundaryFeature.geometry.coordinates);
        app_radius = Math.max(100, Math.ceil(cityBoundaryRadiusMeters / 100) * 100);
        const boundaryMapFeature = new GeoJSON().readFeature(cityBoundaryFeature, { featureProjection: 'EPSG:3857' });
        const mapSize = map.getSize();
        const currentViewport = mapSize ? map.getView().calculateExtent(mapSize) : null;
        if (currentViewport && containsExtent(currentViewport, boundaryMapFeature.getGeometry().getExtent())) {
            dashboardCitywide.checked = false;
            citywideSearch = false;
            cityBoundaryFeature = null;
            cityBoundaryRadiusMeters = null;
            app_radius = cityRadiusMeters;
            syncDashboardRadius(app_radius, true);
            if (radiusStatus) radiusStatus.textContent = '';
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'info',
                title: t('The whole city is already within the viewport.'),
                showConfirmButton: false,
                timer: 2800,
                timerProgressBar: true,
            });
            return;
        }
        citywideSearch = true;
        cityBoundarySource.clear();
        cityBoundarySource.addFeature(boundaryMapFeature);
        radiusFeature?.setGeometry(null);
        syncDashboardRadius(app_radius, true);
        if (radiusStatus) radiusStatus.textContent = '';
        const isMobileViewport = window.matchMedia('(max-width: 767.98px)').matches;
        map.getView().fit(boundaryMapFeature.getGeometry().getExtent(), {
            size: mapSize,
            padding: isMobileViewport ? [24, 24, 24, 24] : [36, 36, 36, 36],
            maxZoom: 14,
            duration: 350,
        });
        refreshLocationData(center);
    } catch (error) {
        dashboardCitywide.checked = false;
        citywideSearch = false;
        if (radiusStatus) radiusStatus.textContent = error.message || t('Could not load the city boundary.');
        const status = document.getElementById('city-location-status');
        if (status) {
            status.textContent = error.message;
            status.className = 'col-12 small text-danger';
        }
    } finally {
        dashboardCitywide.disabled = false;
    }
});


const state = {
    radiusFeature: null,
    userFeature: null,
    trackFeature: null
};

const users = new globalThis.Map();

const activePoiFilters = new Set();

let poiRequestId = 0;
// Aborts the HTTP requests of a superseded loadNearby() instead of only ignoring their results.
let poiLoadController = null;
// Rapid filter toggling waits for a short pause and then loads all changes at once.
let poiLoadDebounce = null;
const POI_FILTER_DEBOUNCE_MS = 400;
let busStopLookupRun = 0;
let poiLoadingTimer = null;
let poiLoadingStartedAt = null;
let poiLoadingModalEventsBound = false;
let poiLoadingModalShown = false;
let poiLoadingModalHidePending = false;
let poiLoadingModalShowing = false;
let poiLoadingModalHidePromise = null;

let allPois = null;

let allPoisRaw = [];
let poiResultCache = { key: null, tokens: new Set(), data: [] };

const POI_CATEGORIES = {    
    airport:  { color: '#0d6efd' },
    bus:      { color: '#198754' },
    train:    { color: '#cddc39' },
    subway:   { color: '#7c3aed' },
    taxi:     { color: '#f59e0b' },
    hospital: { color: '#dc3545' },
    pharmacy: { color: '#db9999' },
    police:   { color: '#2234a9' },
    fire:     { color: '#0a0a0a' },
    tourism:  { color: '#2196f3' },
    transport:{ color: '#6c757d' }
};

function showBestGasPriceButton(country) {
    let button = document.querySelector('.fuel-best-button');
    if(country === 'ro') {
        button.classList.remove('d-none');
    } else {
        button.classList.add('d-none');
    }
}

function resetPOIs() {
    const container = document.getElementById('mobility_card');

    if (container) {
        container.querySelectorAll(
            '.location-category, .location-subcategory'
        ).forEach(checkbox => {
            checkbox.checked = false;
            checkbox.indeterminate = false;
        });

        syncPoiFilterSelection(container);
    }

    // Golește filtrele active și rezultatele POI.
    activePoiFilters.clear();
    allPois = [];
    allPoisRaw = [];
    poiSource.clear();

    // Invalidează cererile POI în curs.
    poiRequestId++;

    // Elimină traseele și tooltip-ul asociat hărții.
    clearVisibleTransitRoutes();
    hidePoiTooltip();
    hidePOIModal();

    // Actualizează afișarea filtrelor pe hartă.
    poiLayer.changed();
    clusterLayer.changed();
}

function normalizePoiType(type) {
    const map = {
        fuel: 'fuel',
        parking: 'parking',
        restaurant: 'restaurant',
        cafe: 'cafe',
        lodging: 'lodging',
        supermarket: 'supermarket',
        bus: 'bus',
        bus_station: 'bus',
        train: 'train',
        train_station: 'train',
        subway: 'subway',
        taxi: 'taxi',
        charging_station: 'charging_station',
        airport: 'airport',
        aerodrom: 'airport',
        airfield: 'airport',
        aeroport: 'airport',
        hospital: 'hospital',
        pharmacy: 'pharmacy',
        police: 'police',
        fire: 'fire',
        tourism: 'tourism',
        speed_camera: 'speed_camera',
        speed_limit: 'speed_limit',
        traffic_sign: 'traffic_sign',
        vignette_control: 'vignette_control',
        control: 'control',
        locality: 'locality',
        transport: 'transport'
    };

    return map[type] || 'transport';
}

const CATEGORY_PALETTE = [
    '#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#008080',
    '#f032e6', '#9a6324', '#800000', '#808000', '#000075', '#e6a800',
    '#00a6d6', '#7cb518', '#b5179e', '#ff6f59',
];
const assignedCategoryColors = new globalThis.Map();

// Every category selected after another one gets the next unused color, kept for the rest of the session.
function assignCategoryColors(types) {
    types.forEach(type => {
        if (assignedCategoryColors.has(type)) return;

        const index = assignedCategoryColors.size;
        assignedCategoryColors.set(
            type,
            index < CATEGORY_PALETTE.length
                ? CATEGORY_PALETTE[index]
                : `hsl(${Math.round((index * 137.508) % 360)}, 70%, 42%)`
        );
    });
}

function getColorForType(type) {
    return assignedCategoryColors.get(type) || POI_CATEGORIES[type]?.color || '#666';
}

function getDistinctColor(index, total) {
    const goldenAngle = 137.508; // distribuție vizual optimă
    const hue = (index * goldenAngle) % 360;

    return `hsl(${hue}, 75%, 45%)`;
}

function hashColor(str) {
    return getColorForType(str);
}

function getTextColorForBackground(hexColor) {

    hexColor = hexColor.replace('#', '');

    if (hexColor.length === 3) {
        hexColor = hexColor.split('').map(c => c + c).join('');
    }

    const r = parseInt(hexColor.substring(0, 2), 16);
    const g = parseInt(hexColor.substring(2, 4), 16);
    const b = parseInt(hexColor.substring(4, 6), 16);

    const luminance =
        (0.299 * r + 0.587 * g + 0.114 * b);

    return luminance > 186 ? '#000000' : '#ffffff';
}

function getPoiCoordinates(item) {
    const rawLat = item?.coordinates?.lat ?? item?.lat;
    const rawLon = item?.coordinates?.lon ?? item?.lon;
    if ((typeof rawLat !== 'number' && typeof rawLat !== 'string')
        || (typeof rawLon !== 'number' && typeof rawLon !== 'string')
        || String(rawLat).trim() === '' || String(rawLon).trim() === '') return null;
    const poiLat = Number(rawLat);
    const poiLon = Number(rawLon);
    if (!Number.isFinite(poiLat) || !Number.isFinite(poiLon) || Math.abs(poiLat) > 90 || Math.abs(poiLon) > 180) return null;
    return { lat: poiLat, lon: poiLon };
}

function filterByRadius(data, lat, lon, radiusMeters) {

    return data.filter(item => {
        const coordinates = getPoiCoordinates(item);
        if (!coordinates) return false;
        const { lat: itemLat, lon: itemLon } = coordinates;

        if (citywideSearch && cityBoundaryFeature) {
            return pointInCityBoundary(itemLon, itemLat, cityBoundaryFeature.geometry);
        }

        const d = getDistanceMeters(lat, lon, itemLat, itemLon);

        return d <= radiusMeters;
    });
}

function pointInRing(lon, lat, ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        const intersects = ((yi > lat) !== (yj > lat))
            && (lon < ((xj - xi) * (lat - yi)) / ((yj - yi) || Number.EPSILON) + xi);
        if (intersects) inside = !inside;
    }
    return inside;
}

function pointInCityBoundary(lon, lat, geometry) {
    const polygons = geometry?.type === 'Polygon'
        ? [geometry.coordinates]
        : geometry?.type === 'MultiPolygon' ? geometry.coordinates : [];
    return polygons.some((rings) => pointInRing(lon, lat, rings[0])
        && !rings.slice(1).some((hole) => pointInRing(lon, lat, hole)));
}





const vectorSource = new VectorSource();
const cityBoundarySource = new VectorSource();

const userSource = new VectorSource();

const poiSource = new VectorSource();
const transitRouteSource = new VectorSource();
const unavailableTransitRouteGeometry = new Set();
const visibleTransitRoutes = new Set();
const activeTransitRoutes = new globalThis.Map();
const transitRouteColors = new globalThis.Map();
const manuallySelectedTransitRouteColors = new Set();
let transitRouteGeometryRun = 0;

const clusterSource = new Cluster({
    distance: 40,
    source: userSource,
});






const userStyle = new Style({
    image: new Icon({
        src: '/images/user.png',
        scale: 0.05,
        anchor: [0.5, 1]
    })
});

// Shown instead of the pin once a direction of travel is known; the arrow points north at rotation 0.
const userArrowStyle = new Style({
    image: new Icon({
        src: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48">'
            + '<circle cx="24" cy="24" r="21" fill="#0d6efd" stroke="#ffffff" stroke-width="3"/>'
            + '<path d="M24 9 L35 35 L24 29 L13 35 Z" fill="#ffffff"/>'
            + '</svg>'
        ),
        scale: 0.6,
        anchor: [0.5, 0.5],
        rotateWithView: true
    })
});

const HEADING_MIN_MOVE_METERS = 3;

/** Bearing in radians, clockwise from north, between two { lat, lon } points. */
function bearingBetween(from, to) {
    const longitudeDelta = (to.lon - from.lon) * Math.cos((from.lat + to.lat) * Math.PI / 360);
    const latitudeDelta = to.lat - from.lat;

    return Math.atan2(longitudeDelta, latitudeDelta);
}

function approximateDistanceMeters(from, to) {
    const longitudeDelta = (to.lon - from.lon) * Math.cos((from.lat + to.lat) * Math.PI / 360);

    return Math.hypot(longitudeDelta, to.lat - from.lat) * 111320;
}

const trackStyle = new Style({
    stroke: new Stroke({
        color: 'rgba(255,0,0,0.8)',
        width: 3
    })
});

const radiusStyle = new Style({

    fill: new Fill({
        color: 'rgba(13,110,253,0.15)'
    }),

    stroke: new Stroke({
        color: '#0d6efd',
        width: 2
    })
});




const baseLayer = new TileLayer({
    // The tile proxy serves up to zoom 20; OpenLayers upscales those tiles beyond it.
    source: new XYZ({ url: '/map/tiles/{z}/{x}/{y}', maxZoom: 20, attributions: MAP_ATTRIBUTION })
});

const clusterLayer = new VectorLayer({

    source: clusterSource,

    style: (feature) => {

        const size = feature.get('features').length;

        const type = feature.get('type');

        if (
            activePoiFilters.size > 0 &&
            !activePoiFilters.has(type)
        ) {
            return null;
        }

        const color = getColorForType(type);

        return new Style({

            image: new CircleStyle({

                radius: Math.min(10 + size, 25),

                fill: new Fill({
                    color: 'rgba(0,150,255,0.7)'
                }),

                stroke: new Stroke({
                    color: '#fff',
                    width: 2
                })
            }),

            text: new Text({

                text: size.toString(),

                fill: new Fill({
                    color: '#fff'
                })
            })
        });
    }
});

const poiLayer = new VectorLayer({
    source: poiSource,
    style: (feature) => {

        const type = feature.get('type');

        if (!activePoiFilters.has(type)) {
            return null;
        }

        const color = hashColor(type);
        const markerIcon = getPoiMarkerIcon(type, feature.get('details')?.provider);
        if (markerIcon) {
            return [
                new Style({
                    image: new CircleStyle({
                        radius: 10,
                        fill: new Fill({ color: '#fff' }),
                        stroke: new Stroke({
                            color: feature.get('details')?.provider === 'Waze' ? '#1d4ed8' : color,
                            width: 2
                        })
                    }),
                    text: new Text({
                        text: markerIcon,
                        font: '18px "Segoe UI Emoji", "Apple Color Emoji", sans-serif',
                        textAlign: 'center',
                        textBaseline: 'middle'
                    })
                }),
                new Style({
                    text: new Text({
                        text: feature.get('name') || '',
                        offsetY: -19,
                        fill: new Fill({ color: '#111' }),
                        stroke: new Stroke({ color: '#fff', width: 3 })
                    })
                })
            ];
        }

        return new Style({
            image: new CircleStyle({
                radius: 7,
                fill: new Fill({ color }),
                stroke: new Stroke({ color: '#fff', width: 2 })
            }),
            text: new Text({
                text: feature.get('name') || '',
                offsetY: -15,
                fill: new Fill({ color: '#111' }),
                stroke: new Stroke({ color: '#fff', width: 3 })
            })
        });
    }
});

const transitRouteLayer = new VectorLayer({
    source: transitRouteSource,
    style: feature => new Style({
        stroke: new Stroke({
            color: feature.get('routeColor') || '#7c3aed',
            width: 5,
            lineCap: 'round',
            lineJoin: 'round',
        }),
    }),
});

const vectorLayer = new VectorLayer({
    source: vectorSource
});

const cityBoundaryLayer = new VectorLayer({
    source: cityBoundarySource,
    style: new Style({
        fill: new Fill({ color: 'rgba(13, 110, 253, 0.12)' }),
        stroke: new Stroke({ color: '#0d6efd', width: 3 }),
    }),
});






const map = new Map({

    target: 'map',

    layers: [
        baseLayer,
        cityBoundaryLayer,
        clusterLayer,
        poiLayer,
        vectorLayer,
        transitRouteLayer,
    ],

    view: new View({
        center: fromLonLat([25.6, 45.65]),
        zoom: 12
    })
});

const transitStops = createTransitLayer(map);

window.addEventListener('symbiot:focus-map-point', event => {
    const { lat, lon } = event.detail ?? {};
    if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lon))) return;
    map.getView().animate({
        center: fromLonLat([Number(lon), Number(lat)]),
        zoom: Math.max(map.getView().getZoom() || 13, 15),
        duration: 350,
    });
});

const mapTileSource = baseLayer.getSource();
const mapTileProgress = document.getElementById('map-tile-progress');
const mapTileProgressLabel = document.getElementById('map-tile-progress-label');
const mapTileProgressCount = document.getElementById('map-tile-progress-count');
const mapTileProgressBar = document.getElementById('map-tile-progress-bar');
let requestedMapTiles = new Set();
let completedMapTiles = new Set();
let failedMapTiles = new Set();
let tileProgressHideTimer = null;

function renderMapTileProgress() {
    const total = requestedMapTiles.size;
    window.clearTimeout(tileProgressHideTimer);
    if (total === 0) {
        mapTileProgress.classList.add('d-none');
        return;
    }

    const completed = completedMapTiles.size;
    const failed = failedMapTiles.size;
    const percent = Math.min(100, Math.round((completed / total) * 100));
    const settled = completed + failed;
    const isLoading = settled < total;

    mapTileProgress.classList.remove('d-none');
    mapTileProgressLabel.textContent = isLoading
        ? t('Loading map...')
        : failed > 0
            ? t('Map loaded; :count tiles could not be fetched.', { count: failed })
            : t('Map loaded');
    mapTileProgressCount.textContent = `${completed} / ${total} (${percent}%)`;
    mapTileProgressBar.style.width = `${percent}%`;
    mapTileProgressBar.parentElement.setAttribute('aria-valuenow', String(percent));
    mapTileProgressBar.classList.toggle('bg-warning', failed > 0 && !isLoading);
    mapTileProgressBar.classList.toggle('progress-bar-animated', isLoading);

    if (!isLoading) {
        tileProgressHideTimer = window.setTimeout(() => {
            if (completedMapTiles.size + failedMapTiles.size >= requestedMapTiles.size) {
                mapTileProgress.classList.add('d-none');
            }
        }, failed > 0 ? 5000 : 1200);
    }
}

mapTileSource.on('tileloadstart', event => {
    const key = event.tile.getTileCoord().join('/');
    if (completedMapTiles.size + failedMapTiles.size >= requestedMapTiles.size) {
        requestedMapTiles = new Set();
        completedMapTiles = new Set();
        failedMapTiles = new Set();
    }
    requestedMapTiles.add(key);
    completedMapTiles.delete(key);
    failedMapTiles.delete(key);
    renderMapTileProgress();
});
mapTileSource.on('tileloadend', event => {
    const key = event.tile.getTileCoord().join('/');
    requestedMapTiles.add(key);
    completedMapTiles.add(key);
    failedMapTiles.delete(key);
    renderMapTileProgress();
});
mapTileSource.on('tileloaderror', event => {
    const key = event.tile.getTileCoord().join('/');
    requestedMapTiles.add(key);
    failedMapTiles.add(key);
    completedMapTiles.delete(key);
    renderMapTileProgress();
});

const poiTooltipElement = document.createElement('div');
poiTooltipElement.className = 'poi-map-tooltip';
poiTooltipElement.setAttribute('role', 'dialog');
poiTooltipElement.setAttribute('aria-live', 'polite');
poiTooltipElement.hidden = true;

const poiTooltipHeader = document.createElement('div');
poiTooltipHeader.className = 'poi-map-tooltip-header';
const poiTooltipName = document.createElement('div');
poiTooltipName.className = 'poi-map-tooltip-name';
const poiTooltipClose = document.createElement('button');
poiTooltipClose.type = 'button';
poiTooltipClose.className = 'poi-map-tooltip-close';
poiTooltipClose.setAttribute('aria-label', t('Close'));
poiTooltipClose.textContent = '×';
poiTooltipClose.addEventListener('click', hidePoiTooltip);
poiTooltipHeader.append(poiTooltipName, poiTooltipClose);
const poiTooltipType = document.createElement('div');
poiTooltipType.className = 'poi-map-tooltip-type';
const poiTooltipRoutes = document.createElement('div');
poiTooltipRoutes.className = 'poi-map-tooltip-routes';
const poiTooltipAddress = document.createElement('div');
poiTooltipAddress.className = 'poi-map-tooltip-address';
poiTooltipElement.append(poiTooltipHeader, poiTooltipType, poiTooltipRoutes, poiTooltipAddress);

const poiTooltip = new Overlay({
    element: poiTooltipElement,
    offset: [0, -12],
    positioning: 'bottom-center',
    stopEvent: true,
});
map.addOverlay(poiTooltip);

const poiTypeLabels = {
    fuel: t('Fuel stations'),
    parking: t('Parking'),
    restaurant: t('Restaurants'),
    cafe: t('Cafés'),
    lodging: t('Lodging'),
    supermarket: t('Supermarkets'),
    airport: t('Airports'),
    bus: t('Bus stops'),
    subway: t('Metro'),
    train: t('Train stations'),
    taxi: t('Taxi'),
    hospital: t('Hospitals'),
    pharmacy: t('Pharmacies'),
    fire: t('Fire stations'),
    tourism: t('Tourist attractions'),
    charging_station: t('EV charging'),
    police: t('Police'),
    speed_camera: t('Speed cameras'),
    speed_limit: t('Speed limits'),
    traffic_sign: t('Traffic signs'),
    vignette_control: t('Vignette check'),
    control: t('Checkpoints'),
    locality: t('Localities'),
    transport: t('Point of interest'),
};

async function loadBusStopRoutes(feature) {
    const coordinates = feature.get('coordinates') ?? {};
    const lat = Number(coordinates.lat);
    const lon = Number(coordinates.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        throw new Error('Coordonatele stației nu sunt disponibile.');
    }

    // Prefer the locally imported transit feed. OSM relation lookups can be
    // slow or unavailable in cities where Overpass is under load (for example Budapest).
    try {
        const nearbyResponse = await fetch(`/api/transit/stops?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}&radius=1000`, {
            headers: { Accept: 'application/json' },
        });
        if (nearbyResponse.ok) {
            const nearbyPayload = await nearbyResponse.json();
            const candidates = (Array.isArray(nearbyPayload?.stops) ? nearbyPayload.stops : [])
                .map((stop) => ({
                    stop,
                    distance: getDistanceMeters(lat, lon, Number(stop.lat), Number(stop.lon)),
                }))
                .filter(({ stop, distance }) => Number.isFinite(distance)
                    && distance <= 120
                    && Number(stop.feed_id) > 0
                    && stop.stop_id != null)
                .sort((a, b) => a.distance - b.distance);

            for (const { stop } of candidates) {
                try {
                    const routesResponse = await fetch(`/api/transit/stops/${encodeURIComponent(stop.feed_id)}/${encodeURIComponent(stop.stop_id)}/routes`, {
                        headers: { Accept: 'application/json' },
                    });
                    if (!routesResponse.ok) continue;
                    const routesPayload = await routesResponse.json();
                    if (Array.isArray(routesPayload?.routes) && routesPayload.routes.length > 0) {
                        return {
                            source: 'gtfs',
                            feed: Number(stop.feed_id),
                            stopId: String(stop.stop_id),
                            stopName: stop.name || feature.get('name') || t('Bus stop'),
                            routes: routesPayload.routes,
                        };
                    }
                } catch {
                    // A bad nearby match should not prevent trying the next stop or OSM.
                }
            }
        }
    } catch {
        // Keep the existing OSM lookup as a fallback if the local feed is unavailable.
    }

    const params = new URLSearchParams({
        lat: String(lat),
        lon: String(lon),
        radius: '100',
        types: 'bus',
        include_routes: 'true',
    });
    const payload = await fetchJson(`/api/transport/bus?${params}`);
    if (!Array.isArray(payload)) {
        throw new Error('Cererea pentru liniile stației a returnat un format neașteptat.');
    }

    const stopId = String(feature.get('id') ?? '');
    let stop = payload.find((item) => String(item.id ?? '') === stopId);
    if (!stop) {
        stop = payload
            .map((item) => ({
                item,
                distance: getDistanceMeters(lat, lon, Number(item.coordinates?.lat), Number(item.coordinates?.lon)),
            }))
            .filter((candidate) => Number.isFinite(candidate.distance) && candidate.distance <= 100)
            .sort((a, b) => a.distance - b.distance)[0]?.item;
    }

    return {
        routes: Array.isArray(stop?.routes) ? stop.routes : [],
        routesAvailable: stop?.routes_available !== false,
    };
}

function showPoiTooltip(feature, coordinate) {
    const transitTypes = new Set(['bus', 'subway', 'train', 'airport']);
    const featureType = feature.get('type');
    window.dispatchEvent(new CustomEvent('symbiot:poi-selected', { detail: { type: featureType } }));
    if (featureType === 'lodging') {
        showLodgingDetails(feature);
        return;
    }
    if (featureType === 'fuel') {
        showFuelPrices(feature);
        return;
    }

    const featureRoutes = feature.get('routes');
    const routes = Array.isArray(featureRoutes) ? featureRoutes : [];
    const groupedRoutes = groupTransitRoutes(routes);
    const routeGroups = featureType === 'bus'
        ? splitTransitRouteDirections(groupedRoutes)
        : groupedRoutes;

    if (featureType === 'bus') {
        hidePoiTooltip();
        const lookupRun = ++busStopLookupRun;
        const stopName = feature.get('name') || t('Bus stop');
        const showStopRoutes = (routes, routesAvailable) => {
            const busRouteGroups = groupTransitRoutes(routes);
            assignTransitRouteColors(busRouteGroups, transitRouteColors, manuallySelectedTransitRouteColors);
            window.dispatchEvent(new CustomEvent('symbiot:osm-stop-routes', {
                detail: { stopName, routes: busRouteGroups, feature, routesAvailable, loading: false },
            }));
        };

        window.dispatchEvent(new CustomEvent('symbiot:osm-stop-routes', {
            detail: { stopName, routes: [], feature, routesAvailable: true, loading: true },
        }));
        if (groupedRoutes.length > 0) {
            showStopRoutes(featureRoutes, feature.get('routes_available') !== false);
        } else {
            loadBusStopRoutes(feature)
                .then((result) => {
                    if (lookupRun !== busStopLookupRun) return;
                    if (result.source === 'gtfs') {
                        window.dispatchEvent(new CustomEvent('symbiot:transit-stop-routes', {
                            detail: {
                                feed: result.feed,
                                stopId: result.stopId,
                                stopName: result.stopName,
                                routes: result.routes,
                            },
                        }));
                        return;
                    }
                    showStopRoutes(result.routes, result.routesAvailable);
                })
                .catch((error) => {
                    if (lookupRun !== busStopLookupRun) return;
                    console.warn('Bus stop route lookup failed:', error);
                    showStopRoutes([], false);
                });
        }
        return;
    }

    if (featureType === 'train' || featureType === 'subway') {
        hidePoiTooltip();
        window.dispatchEvent(new CustomEvent('symbiot:osm-stop-routes', {
            detail: {
                stopName: feature.get('name') || poiTypeLabels[featureType],
                routes: routeGroups,
                trainServices: feature.get('train_services') || [],
                trainServicesAvailable: feature.get('train_services_available') !== false,
                timetableSource: feature.get('train_timetable_source') || 'data.gov.ro',
                timetableValidUntil: feature.get('train_timetable_valid_until') || null,
                isTrainStation: featureType === 'train',
                feature,
                routesAvailable: feature.get('routes_available') !== false,
                loading: false,
            },
        }));
        return;
    }

    if (featureType === 'airport') {
        hidePoiTooltip();
        const airportName = feature.get('name') || t('Selected airport');
        const details = feature.get('details') || {};
        const airportCode = String(details.icao || details.iata || '').trim().toUpperCase();
        const baseEvent = {
            stopName: airportName,
            routes: [],
            isAirport: true,
            loading: true,
            airportFlights: null,
        };
        window.dispatchEvent(new CustomEvent('symbiot:osm-stop-routes', { detail: baseEvent }));

        if (!airportCode) {
            window.dispatchEvent(new CustomEvent('symbiot:osm-stop-routes', {
                detail: {
                    ...baseEvent,
                    loading: false,
                    airportFlights: {
                        available: false,
                        reason: 'missing_airport_code',
                        message: t('The airport has no IATA/ICAO code in the map data.'),
                        arrivals: [],
                        departures: [],
                    },
                },
            }));
            return;
        }

        fetchJson(`/api/transport/airport/${encodeURIComponent(airportCode)}/flights`)
            .then((airportFlights) => {
                window.dispatchEvent(new CustomEvent('symbiot:osm-stop-routes', {
                    detail: { ...baseEvent, loading: false, airportFlights },
                }));
            })
            .catch((error) => {
                console.warn('Airport flights could not be loaded:', error);
                window.dispatchEvent(new CustomEvent('symbiot:osm-stop-routes', {
                    detail: {
                        ...baseEvent,
                        loading: false,
                        airportFlights: {
                            available: false,
                            reason: 'request_failed',
                            message: t('Could not load the flights for this airport.'),
                            arrivals: [],
                            departures: [],
                        },
                    },
                }));
            });
        return;
    }

    if (transitTypes.has(featureType)) {
        const modalElement = document.getElementById('trainStatusModal');
        const modalTitle = document.getElementById('trainStatusModalTitle');
        const modalLink = document.getElementById('trainStatusOfficialLink');
        const checkedAt = document.getElementById('trainStatusCheckedAt');
        const routeList = document.getElementById('transitLinesList');
        const emptyMessage = document.getElementById('transitLinesEmpty');
        const metroEstimate = document.getElementById('metroArrivalEstimate');
        const trainLiveInfo = document.getElementById('trainStatusLiveInfo');
        const sourceInfo = document.getElementById('transitLinesSource');
        const stationName = feature.get('name') || t('Transport point');
        const typeLabel = poiTypeLabels[featureType] || t('Transport');

        if (!modalElement || !modalTitle || !modalLink || !checkedAt
            || !routeList || !emptyMessage || !metroEstimate || !trainLiveInfo || !sourceInfo) {
            console.error('The transit lines modal is missing required elements.');
            return;
        }

        enableTransitModalDragging(modalElement);
        hidePoiTooltip();
        modalTitle.textContent = `${typeLabel} — ${stationName}`;
        routeList.replaceChildren();
        activeTransitRoutes.clear();
        emptyMessage.hidden = routeGroups.length > 0;
        emptyMessage.textContent = t('There are no lines linked to this station in OpenStreetMap.');
        sourceInfo.textContent = t('Lines and routes from OpenStreetMap; the actual status may differ.');
        if (feature.get('routes_available') === false) {
            sourceInfo.textContent += ` ${t('Automatic loading failed; try again later.')}`;
        }
        trainLiveInfo.hidden = featureType !== 'train';
        metroEstimate.hidden = featureType !== 'subway' || routeGroups.length === 0;
        metroEstimate.textContent = t('Select exactly one metro line to estimate the next train.');
        assignTransitRouteColors(routeGroups, transitRouteColors, manuallySelectedTransitRouteColors);

        routeGroups.forEach((group, index) => {
            activeTransitRoutes.set(group.key, group);
            const hasRelation = group.routes.some(route => /^relation\/\d+$/.test(route.id));
            const row = document.createElement('div');
            row.className = 'poi-route-option border rounded p-2';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'poi-route-checkbox';
            checkbox.dataset.routeKey = group.key;
            checkbox.checked = visibleTransitRoutes.has(group.key);
            checkbox.disabled = !hasRelation;
            checkbox.setAttribute('aria-label', t('Show route :route', { route: group.label }));
            checkbox.title = t('Show route :route', { route: group.label });
            if (!hasRelation) {
                checkbox.title = t('The route relation has no valid OSM identifier.');
            }

            const colorPicker = document.createElement('input');
            colorPicker.type = 'color';
            colorPicker.className = 'poi-route-color';
            colorPicker.dataset.routeKey = group.key;
            colorPicker.value = getTransitRouteColor(group.key, transitRouteColors);
            colorPicker.setAttribute('aria-label', t('Route colour :route', { route: group.label }));

            const directions = [...new Set(group.routes.map(route => route.direction).filter(Boolean))];
            const latestUpdate = group.routes
                .map(route => route.updated_at)
                .filter(Boolean)
                .sort()
                .at(-1);
            const inactive = group.routes.some(route => route.status === 'possibly_inactive');
            const status = inactive
                ? t('Marked as possibly inactive in OSM')
                : latestUpdate
                    ? `OSM · ${new Intl.DateTimeFormat(intlLocale, { dateStyle: 'medium' }).format(new Date(latestUpdate))}`
                    : t('Listed in OSM; the actual status is not confirmed');
            const labelText = document.createElement('label');
            labelText.className = 'poi-route-text';
            labelText.htmlFor = checkbox.id = `transit-route-${index}`;
            const routeName = document.createElement('span');
            routeName.textContent = group.directionLabel
                ? `${group.label} — ${group.directionLabel}`
                : group.label;
            const routeInfo = document.createElement('span');
            routeInfo.className = 'poi-route-info';
            routeInfo.textContent = [...(group.directionLabel ? [] : directions), status].join(' · ');
            labelText.append(routeName, routeInfo);
            row.append(checkbox, colorPicker, labelText);
            routeList.appendChild(row);
        });
        updateMetroArrivalEstimate();

        if (featureType === 'train') {
            modalLink.href = getInfoferStationUrl(stationName);
            checkedAt.textContent = t('Link prepared at :time. Operational information is shown on the official Infofer website.', {
                time: new Intl.DateTimeFormat(intlLocale, {
                    dateStyle: 'short',
                    timeStyle: 'short',
                    timeZone: 'Europe/Bucharest',
                }).format(new Date()),
            });
        }

        bootstrap.Modal.getOrCreateInstance(modalElement).show();
        return;
    }

    const address = feature.get('address') ?? {};
    const addressText = address.formatted
        || [address.city, address.county, address.country].filter(Boolean).join(', ');

    poiTooltipName.textContent = feature.get('name') || t('Unknown');
    poiTooltipType.textContent = poiTypeLabels[feature.get('type')] || t('Point of interest');
    poiTooltipType.hidden = false;
    poiTooltipElement.classList.remove('poi-bus-tooltip');
    const featureTrainServices = feature.get('train_services');
    const trainServices = Array.isArray(featureTrainServices) ? featureTrainServices : [];
    poiTooltipRoutes.replaceChildren();
    poiTooltipRoutes.classList.remove('poi-bus-route-list');
    activeTransitRoutes.clear();
    if (routeGroups.length > 0) {
        const heading = document.createElement('div');
        heading.textContent = t('Lines / routes served:');
        poiTooltipRoutes.appendChild(heading);

        routeGroups.forEach(group => {
            activeTransitRoutes.set(group.key, group);
            const color = getTransitRouteColor(group.key, transitRouteColors);
            const hasRelation = group.routes.some(route =>
                /^relation\/\d+$/.test(route.id)
            );

            const routeLabel = document.createElement('div');
            routeLabel.className = 'poi-route-option';

            const routeCheckbox = document.createElement('input');
            routeCheckbox.type = 'checkbox';
            routeCheckbox.className = 'poi-route-checkbox';
            routeCheckbox.dataset.routeKey = group.key;
            routeCheckbox.checked = visibleTransitRoutes.has(group.key);
            routeCheckbox.disabled = !hasRelation;
            routeCheckbox.setAttribute('aria-label', t('Show route :route', { route: group.label }));
            if (routeCheckbox.disabled) {
                routeCheckbox.title = t('The route relation has no valid OSM identifier.');
            }

            const colorPicker = document.createElement('input');
            colorPicker.type = 'color';
            colorPicker.className = 'poi-route-color';
            colorPicker.dataset.routeKey = group.key;
            colorPicker.value = color;
            colorPicker.setAttribute('aria-label', t('Route colour :route', { route: group.label }));

            const routeName = document.createElement('span');
            routeName.textContent = group.label;
            const routeInfo = document.createElement('span');
            routeInfo.className = 'poi-route-info';
            const inactive = group.routes.some(route => route.status === 'possibly_inactive');
            const directions = [...new Set(group.routes.map(route => route.direction).filter(Boolean))];
            const latestUpdate = group.routes
                .map(route => route.updated_at)
                .filter(Boolean)
                .sort()
                .at(-1);
            const statusText = inactive
                ? t('Marked as possibly inactive in OSM')
                : latestUpdate
                    ? `OSM · ${new Intl.DateTimeFormat(intlLocale, { dateStyle: 'medium' }).format(new Date(latestUpdate))}`
                    : t('Listed in OSM; the actual status is not confirmed');
            routeInfo.textContent = [...directions, statusText].join(' · ');
            routeInfo.title = t('OpenStreetMap data; it does not confirm the operator\'s current timetable.');
            const routeText = document.createElement('span');
            routeText.className = 'poi-route-text';
            routeText.append(routeName, routeInfo);
            routeLabel.append(routeCheckbox, colorPicker, routeText);
            poiTooltipRoutes.appendChild(routeLabel);
        });
    }

    if (trainServices.length > 0) {
        const heading = document.createElement('div');
        heading.textContent = t('Trains in the published annual timetable:');
        poiTooltipRoutes.appendChild(heading);

        trainServices.forEach(service => {
            const serviceInfo = document.createElement('div');
            serviceInfo.className = 'poi-route-info';
            const details = [
                service.departure ? t('departure :time', { time: service.departure }) : null,
                service.direction ? t('towards :destination', { destination: service.direction }) : null,
                service.operator || null,
                service.service_days || null,
            ].filter(Boolean);
            serviceInfo.textContent = [
                `${service.category || t('Train')} ${service.number}`,
                ...details,
            ].join(' · ');
            poiTooltipRoutes.appendChild(serviceInfo);
        });

        const sourceInfo = document.createElement('div');
        sourceInfo.className = 'poi-route-info';
        const validUntil = feature.get('train_timetable_valid_until');
        const availabilityNote = feature.get('train_services_available') === false
            ? t('Some timetable sources could not be loaded.')
            : '';
        sourceInfo.textContent = [
            t('Source: :source · annual timetable, not real-time data; changes/exceptions may occur.', { source: feature.get('train_timetable_source') || 'data.gov.ro' }),
            validUntil ? t('Valid until :date.', { date: validUntil }) : '',
            availabilityNote,
        ].filter(Boolean).join(' ');
        poiTooltipRoutes.appendChild(sourceInfo);
    } else if (transitTypes.has(feature.get('type')) && routeGroups.length === 0) {
        const emptyRoutes = document.createElement('div');
        emptyRoutes.className = 'poi-route-info';
        if (feature.get('type') === 'train' && feature.get('train_services_available') === false) {
            emptyRoutes.textContent = t('Railway timetables could not be loaded from data.gov.ro.');
        } else {
            emptyRoutes.textContent = t('There are no lines linked to this station in OpenStreetMap.');
            if (feature.get('routes_available') === false) {
                emptyRoutes.textContent += ` ${t('Automatic loading failed; try again later.')}`;
            }
        }
        poiTooltipRoutes.appendChild(emptyRoutes);
    }
    if (feature.get('type') === 'subway' && routeGroups.length > 0) {
        const metroEstimate = document.createElement('div');
        metroEstimate.className = 'poi-route-info metro-arrival-estimate';
        metroEstimate.setAttribute('aria-live', 'polite');
        metroEstimate.textContent = t('Select exactly one metro line to estimate the next train.');
        poiTooltipRoutes.appendChild(metroEstimate);
    }
    poiTooltipRoutes.hidden = routeGroups.length === 0 && !transitTypes.has(feature.get('type'));
    poiTooltipAddress.textContent = addressText || '';
    poiTooltipAddress.hidden = !addressText;
    poiTooltipElement.hidden = false;
    poiTooltip.setPosition(coordinate);
}

async function loadBusArrival(feature, group, statusElement) {
    const coordinates = feature.get('coordinates') || {};
    const routeRef = group.label.replace(/^(Autobuz|Troleibuz)\s+/i, '').trim();
    statusElement.textContent = t('Searching for the next departure…');
    statusElement.dataset.routeKey = group.key;
    statusElement.hidden = false;

    try {
        const params = new URLSearchParams({
            lat: String(coordinates.lat),
            lon: String(coordinates.lon),
            route_ref: routeRef,
        });
        const result = await fetchJson(`/api/transit/stops/nearest-next-departure?${params}`);
        if (statusElement.dataset.routeKey !== group.key) return;
        if (!result.departure) {
            statusElement.textContent = result.reason === 'no_upcoming_departure'
                ? t('There are no departures scheduled in the next few days.')
                : result.reason === 'feed_importing'
                    ? t('The city timetable is being prepared. Try again in a few moments.')
                : result.reason === 'feed_import_failed'
                    ? t('Downloading the timetable for the selected city failed. Try again later.')
                    : result.reason === 'no_schedule_feed'
                        ? t('There is no GTFS timetable available for this area.')
                        : t('No timetable found for this line at the selected stop.');
            return;
        }

        const departure = result.departure;
        const countdown = departure.minutes_until === 0 ? t('now') : t('in :minutes min', { minutes: departure.minutes_until });
        statusElement.textContent = departure.headsign
            ? t('Next bus for :route: :time (:countdown) towards :headsign · GTFS timetable', { route: routeRef, time: departure.time, countdown, headsign: departure.headsign })
            : t('Next bus for :route: :time (:countdown) · GTFS timetable', { route: routeRef, time: departure.time, countdown });
    } catch {
        if (statusElement.dataset.routeKey === group.key) {
            statusElement.textContent = t('The timetable could not be loaded.');
        }
    }
}

let fuelBestRequest = 0;

function fuelBestRefs() {
    const el = (id) => document.getElementById(id);
    const refs = {
        modal: el('fuelBestModal'), select: el('fuelBestSelect'), status: el('fuelBestStatus'),
        result: el('fuelBestResult'), chain: el('fuelBestChain'), price: el('fuelBestPrice'),
        meta: el('fuelBestMeta'), station: el('fuelBestStation'), ranking: el('fuelBestRanking'),
        navigate: el('fuelBestNavigate'), source: el('fuelBestSource'),
        countrySection: el('fuelBestCountrySection'),
        countryStatus: el('fuelBestCountryStatus'), countryResult: el('fuelBestCountryResult'),
        countryChain: el('fuelBestCountryChain'), countryPrice: el('fuelBestCountryPrice'),
        countryMeta: el('fuelBestCountryMeta'), countryStation: el('fuelBestCountryStation'),
        countryNavigate: el('fuelBestCountryNavigate'),
    };
    return Object.values(refs).every(Boolean) ? refs : null;
}

// Brands listed in the fuel subcategories: the checked ones, or all that are available around the location.
function fuelBrandIds() {
    const brands = Array.from(document.querySelectorAll('.location-subcategory[data-filter^="subcategory:fuel:"]'))
        .filter(input => !input.disabled)
        .map(input => ({ id: input.dataset.filter.replace('subcategory:fuel:', ''), checked: input.checked }))
        .filter(brand => brand.id !== 'all' && brand.id !== 'lpg');
    const checked = brands.filter(brand => brand.checked);

    return (checked.length > 0 ? checked : brands).map(brand => brand.id);
}

const formatMeters = (meters) => (meters < 1000 ? `${meters} m` : `${(meters / 1000).toFixed(1)} km`);
const mapsDirectionsUrl = (station) => `https://www.google.com/maps/dir/?api=1&destination=${station.lat},${station.lon}&travelmode=driving`;

async function requestBestFuel(params) {
    return fetchJson(`/api/fuel/best?${params}`);
}

async function loadBestFuelPrice() {
    const refs = fuelBestRefs();
    const cl = document.getElementById('current_location');
    if (!refs) return;

    const lat = parseFloat(cl?.dataset.lat);
    const lon = parseFloat(cl?.dataset.lon);
    [refs.result, refs.countrySection, refs.countryResult, refs.source, refs.navigate, refs.countryNavigate]
        .forEach((node) => { node.hidden = true; });
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        refs.status.textContent = t('Choose a location first.');
        refs.countryStatus.textContent = '';
        return;
    }

    const requestId = ++fuelBestRequest;
    const fuel = refs.select.value;
    const isInRomania = lat >= 43.5 && lat <= 48.4 && lon >= 20 && lon <= 30;
    if (!isInRomania) {
        refs.status.textContent = t('Fuel price data is only available for Romania.');
        refs.countryStatus.textContent = '';
        return;
    }

    refs.status.textContent = t('Searching for the best prices in your area...');
    refs.countryStatus.textContent = t('Searching for the best price in the country...');
    const base = { lat, lon, fuel };
    const brands = fuelBrandIds();
    const localParams = new URLSearchParams({
        ...base,
        radius: Math.min(Math.round(Number(app_radius)) || 5000, 35000),
        ...(brands.length > 0 ? { brands: brands.join(',') } : {}),
    });

    const renderLocal = async () => {
        try {
            const data = await requestBestFuel(localParams);
            if (requestId !== fuelBestRequest) return null;
            if (!Array.isArray(data.chains) || data.chains.length === 0) {
                refs.status.textContent = t('No prices reported for :fuel at the subcategory chains in your area.', { fuel: data.fuel.toLowerCase() });
                return null;
            }

            const best = data.chains[0];
            const radiusKm = (data.radius / 1000).toLocaleString(intlLocale, { maximumFractionDigits: 1 });
            refs.status.textContent = t('Search radius: :radius km, :fuel.', { radius: radiusKm, fuel: data.fuel.toLowerCase() });
            refs.chain.textContent = best.name;
            refs.price.textContent = `${best.average_price.toFixed(2)} lei/l`;
            refs.meta.textContent = best.stations === 1
                ? t('average price, :count station', { count: best.stations })
                : t('average price, :count stations', { count: best.stations });
            const nearest = best.nearest;
            refs.station.textContent = t('Nearest: :station (:distance, :price lei/l)', {
                station: [nearest.name, nearest.address].filter(Boolean).join(', '),
                distance: formatMeters(nearest.meters),
                price: nearest.price.toFixed(2),
            });
            refs.ranking.replaceChildren();
            data.chains.forEach((chain) => {
                const item = document.createElement('li');
                item.textContent = `${chain.name} — ${chain.average_price.toFixed(2)} lei/l (${chain.stations})`;
                refs.ranking.appendChild(item);
            });
            refs.navigate.href = mapsDirectionsUrl(nearest);
            refs.navigate.hidden = false;
            refs.result.hidden = false;
            refs.source.textContent = t('Source: :source. Prices are indicative; the ranking uses the chain\'s average price.', { source: data.source });
            refs.source.hidden = false;
            return best;
        } catch (error) {
            if (requestId !== fuelBestRequest) return null;
            console.error('Best fuel price request failed.', error);
            refs.status.textContent = t('Prices could not be loaded right now. Try again.');
            return null;
        }
    };

    const localResultPromise = renderLocal();

    const renderCountry = async () => {
        try {
            const data = await requestBestFuel(new URLSearchParams({ ...base, scope: 'country' }));
            if (requestId !== fuelBestRequest) return;
            if (!Array.isArray(data.chains) || data.chains.length === 0) {
                refs.countryStatus.textContent = t('No data for the country.');
                return;
            }

            const best = data.chains[0];
            const localBest = await localResultPromise;
            if (requestId !== fuelBestRequest || !localBest || best.average_price >= localBest.average_price) return;

            refs.countrySection.hidden = false;
            refs.countryStatus.textContent = t('Calculated from :count county seat cities.', { count: data.cities });
            refs.countryChain.textContent = best.name;
            refs.countryPrice.textContent = `${best.average_price.toFixed(2)} lei/l`;
            refs.countryMeta.textContent = t('average price, :count stations', { count: best.stations });
            const cheapest = best.nearest;
            refs.countryStation.textContent = t('Cheapest station: :station (:price lei/l)', {
                station: [cheapest.name, cheapest.address, cheapest.city].filter(Boolean).join(', '),
                price: cheapest.price.toFixed(2),
            });
            refs.countryNavigate.href = mapsDirectionsUrl(cheapest);
            refs.countryNavigate.hidden = false;
            refs.countryResult.hidden = false;
        } catch (error) {
            if (requestId !== fuelBestRequest) return;
            console.error('National best fuel price request failed.', error);
            refs.countryStatus.textContent = t('Country prices could not be loaded right now.');
        }
    };

    await Promise.all([localResultPromise, renderCountry()]);
}
document.addEventListener('click', (event) => {
    if (!event.target.closest('.fuel-best-button')) return;
    const refs = fuelBestRefs();
    if (!refs) return;
    bootstrap.Modal.getOrCreateInstance(refs.modal).show();
    loadBestFuelPrice();
});
document.addEventListener('hide.bs.modal', (event) => {
    if (event.target instanceof HTMLElement && event.target.contains(document.activeElement)) {
        document.activeElement.blur();
    }
});
document.addEventListener('change', (event) => {
    if (event.target.id === 'fuelBestSelect') loadBestFuelPrice();
});

let fuelPricesRequest = 0;

async function showFuelPrices(feature) {
    const el = (id) => document.getElementById(id);
    const modalElement = el('fuelPricesModal');
    const refs = {
        title: el('fuelPricesModalTitle'),
        brand: el('fuelPricesBrand'),
        station: el('fuelPricesStation'),
        status: el('fuelPricesStatus'),
        fuelsHeading: el('fuelPricesFuelsHeading'),
        table: el('fuelPricesTable'),
        body: el('fuelPricesBody'),
        servicesHeading: el('fuelPricesServicesHeading'),
        services: el('fuelPricesServices'),
        servicesNote: el('fuelPricesServicesNote'),
        source: el('fuelPricesSource'),
        navigate: el('fuelPricesNavigate'),
    };
    const coordinates = feature.get('coordinates');
    if (!modalElement || Object.values(refs).some((ref) => !ref) || !coordinates) {
        console.error('The fuel prices modal is missing required elements.');
        return;
    }

    hidePoiTooltip();
    // The untranslated fallback is still sent to the API as the brand, as before.
    const name = feature.get('name') || 'Benzinărie';
    const displayName = feature.get('name') || t('Fuel station');
    const osmAddress = feature.get('address')?.formatted ?? '';
    refs.title.textContent = displayName;
    refs.brand.textContent = displayName;
    refs.station.textContent = osmAddress || t('Address not available');
    refs.status.textContent = t('Loading prices...');
    [refs.fuelsHeading, refs.table, refs.servicesHeading, refs.services, refs.servicesNote, refs.source]
        .forEach((node) => { node.hidden = true; });
    refs.body.replaceChildren();
    refs.services.replaceChildren();
    refs.navigate.href = `https://www.google.com/maps/dir/?api=1&destination=${coordinates.lat},${coordinates.lon}&travelmode=driving`;
    refs.navigate.hidden = false;
    bootstrap.Modal.getOrCreateInstance(modalElement).show();

    const requestId = ++fuelPricesRequest;
    try {
        const params = new URLSearchParams({ lat: coordinates.lat, lon: coordinates.lon, brand: name });
        const data = await fetchJson(`/api/fuel/prices?${params}`);
        if (requestId !== fuelPricesRequest) return;

        if (!data.station) {
            refs.status.textContent = t('The station has no price or service data in Monitorul Prețurilor.');
            return;
        }

        refs.brand.textContent = data.station.network || displayName;
        refs.station.textContent = data.station.address || osmAddress || t('Address not available');
        refs.status.textContent = '';

        if (Array.isArray(data.prices) && data.prices.length > 0) {
            data.prices.forEach((item) => {
                const row = document.createElement('tr');
                const fuel = document.createElement('td');
                fuel.textContent = item.label;
                if (item.product) {
                    const product = document.createElement('div');
                    product.className = 'small text-muted';
                    product.textContent = item.product;
                    fuel.appendChild(product);
                }
                const price = document.createElement('td');
                price.className = 'text-end fw-semibold';
                price.textContent = `${Number(item.price).toFixed(2)} lei`;
                row.append(fuel, price);
                refs.body.appendChild(row);
            });
            refs.fuelsHeading.hidden = false;
            refs.table.hidden = false;
        } else {
            refs.status.textContent = t('No prices reported for this station.');
        }

        if (Array.isArray(data.station.services) && data.station.services.length > 0) {
            data.station.services.forEach((service) => {
                const badge = document.createElement('li');
                badge.className = 'list-inline-item badge text-bg-secondary';
                badge.textContent = service;
                refs.services.appendChild(badge);
            });
            refs.servicesHeading.hidden = false;
            refs.services.hidden = false;
            refs.servicesNote.hidden = false;
        }

        refs.source.textContent = t('Source: :source. Updated: :date. Prices are indicative.', {
            source: data.source,
            date: data.station.updated_at || t('unknown'),
        });
        refs.source.hidden = false;
    } catch (error) {
        if (requestId !== fuelPricesRequest) return;
        console.error('Fuel prices request failed.', error);
        refs.status.textContent = t('Prices could not be loaded right now. Try again.');
    }
}
function showLodgingDetails(feature) {
    const modalElement = document.getElementById('lodgingDetailsModal');
    const modalTitle = document.getElementById('lodgingDetailsModalTitle');
    const detailsList = document.getElementById('lodgingDetailsList');
    const emptyMessage = document.getElementById('lodgingDetailsEmpty');
    const mapLink = document.getElementById('lodgingDetailsMapLink');
    const name = feature.get('name') || t('Lodging unit');
    const address = feature.get('address') ?? {};
    const details = feature.get('details') ?? {};
    const labels = {
        address: t('Address'),
        accommodation_type: t('Accommodation type'),
        phone: t('Phone'),
        email: t('Email'),
        website: t('Website'),
        opening_hours: t('Opening hours'),
        operator: t('Operator'),
        brand: t('Brand'),
        stars: t('Rating'),
        rooms: t('Rooms'),
        beds: t('Beds'),
        check_in: t('Check-in'),
        check_out: t('Check-out'),
        wheelchair: t('Accessibility'),
        internet_access: t('Internet'),
        description: t('Description'),
    };

    if (!modalElement || !modalTitle || !detailsList || !emptyMessage || !mapLink) {
        console.error('The lodging details modal is missing required elements.');
        return;
    }

    hidePoiTooltip();
    modalTitle.textContent = name;
    detailsList.replaceChildren();

    const addressText = details.address
        || address.formatted
        || [address.city, address.county, address.country].filter(Boolean).join(', ');
    const values = {
        address: addressText,
        ...details,
    };

    Object.entries(labels).forEach(([key, label]) => {
        const value = values[key];
        if (typeof value !== 'string' || !value.trim()) return;

        const row = document.createElement('div');
        row.className = 'lodging-detail-row';
        const term = document.createElement('dt');
        term.textContent = label;
        const description = document.createElement('dd');
        if (key === 'website') {
            const link = createSafeExternalLink(value);
            if (link) {
                link.textContent = value;
                description.appendChild(link);
            } else {
                description.textContent = value;
            }
        } else if (key === 'phone' && /^[+()\d\s.-]+$/.test(value)) {
            const link = document.createElement('a');
            link.href = `tel:${value.replace(/[^\d+]/g, '')}`;
            link.textContent = value;
            description.appendChild(link);
        } else if (key === 'email' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            const link = document.createElement('a');
            link.href = `mailto:${value}`;
            link.textContent = value;
            description.appendChild(link);
        } else {
            description.textContent = value;
        }
        row.append(term, description);
        detailsList.appendChild(row);
    });

    emptyMessage.hidden = detailsList.childElementCount > 0;
    emptyMessage.textContent = t('No other details are available for this property. The information depends on the data provided by Geoapify or OpenStreetMap.');
    const coordinates = feature.get('coordinates');
    const mapUrl = getGoogleMapsPlaceUrl(name, coordinates);
    if (mapUrl) {
        mapLink.href = mapUrl;
        mapLink.hidden = false;
    } else {
        mapLink.hidden = true;
    }

    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function createSafeExternalLink(value) {
    const href = safeExternalUrl(value);
    if (!href) return null;

    const link = document.createElement('a');
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    return link;
}

function enableTransitModalDragging(modalElement) {
    if (modalElement.dataset.interactionsEnabled === 'true') return;

    const dragHandle = modalElement.querySelector('.transit-modal-drag-handle');
    const resizeHandle = modalElement.querySelector('.transit-modal-resize-handle');
    const dialog = modalElement.querySelector('.modal-dialog');
    const content = modalElement.querySelector('.modal-content');
    if (!dragHandle || !resizeHandle || !dialog || !content) {
        console.error('The transit modal is missing its drag or resize controls.');
        return;
    }

    modalElement.dataset.interactionsEnabled = 'true';
    let dragState = null;
    let resizeState = null;

    dragHandle.addEventListener('pointerdown', event => {
        if (!event.isPrimary || event.button !== 0 || event.target.closest('button, a, input, select, textarea')) {
            return;
        }

        const rect = dialog.getBoundingClientRect();
        dialog.style.position = 'fixed';
        dialog.style.left = `${rect.left}px`;
        dialog.style.top = `${rect.top}px`;
        dialog.style.width = `${rect.width}px`;
        dialog.style.margin = '0';
        dialog.style.transform = 'none';
        dragState = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            startLeft: rect.left,
            startTop: rect.top,
        };
        dragHandle.setPointerCapture(event.pointerId);
        event.preventDefault();
    });

    const moveDialog = event => {
        if (!dragState || event.pointerId !== dragState.pointerId) return;

        const left = dragState.startLeft + event.clientX - dragState.startX;
        const top = dragState.startTop + event.clientY - dragState.startY;
        dialog.style.left = `${left}px`;
        dialog.style.top = `${top}px`;
        event.preventDefault();
    };

    const stopDragging = event => {
        if (dragState?.pointerId === event.pointerId) dragState = null;
        if (resizeState?.pointerId === event.pointerId) resizeState = null;
    };
    document.addEventListener('pointermove', moveDialog, { passive: false });
    document.addEventListener('pointerup', stopDragging);
    document.addEventListener('pointercancel', stopDragging);

    const minHeight = 180;
    const getMaxHeight = () => Math.max(minHeight, window.innerHeight - 16);
    const setHeight = height => {
        const nextHeight = Math.min(Math.max(height, minHeight), getMaxHeight());
        content.style.height = `${nextHeight}px`;
        resizeHandle.setAttribute('aria-valuenow', String(Math.round(nextHeight)));
        resizeHandle.setAttribute('aria-valuemin', String(minHeight));
        resizeHandle.setAttribute('aria-valuemax', String(Math.round(getMaxHeight())));
    };

    resizeHandle.addEventListener('pointerdown', event => {
        if (!event.isPrimary || event.button !== 0) return;
        resizeState = {
            pointerId: event.pointerId,
            startY: event.clientY,
            startHeight: content.getBoundingClientRect().height,
        };
        resizeHandle.setPointerCapture(event.pointerId);
        event.preventDefault();
    });

    document.addEventListener('pointermove', event => {
        if (!resizeState || event.pointerId !== resizeState.pointerId) return;
        setHeight(resizeState.startHeight + event.clientY - resizeState.startY);
        event.preventDefault();
    }, { passive: false });

    resizeHandle.addEventListener('keydown', event => {
        if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const currentHeight = content.getBoundingClientRect().height;
        if (event.key === 'Home') {
            setHeight(minHeight);
        } else if (event.key === 'End') {
            setHeight(getMaxHeight());
        } else {
            setHeight(currentHeight + (event.key === 'ArrowDown' ? 24 : -24));
        }
    });
}

document.addEventListener('change', async event => {
    const colorPicker = event.target.closest('.poi-route-color');
    if (colorPicker) {
        const routeKey = colorPicker.dataset.routeKey;
        transitRouteColors.set(routeKey, colorPicker.value);
        manuallySelectedTransitRouteColors.add(routeKey);
        const routeFeature = transitRouteSource.getFeatureById(`transit-route:${routeKey}`);
        routeFeature?.set('routeColor', colorPicker.value);
        return;
    }

    const checkbox = event.target.closest('.poi-route-checkbox');
    if (!checkbox) return;

    if (checkbox.dataset.geometryUnavailable === 'true') {
        checkbox.checked = false;
        return;
    }

    updateMetroArrivalEstimate();

    const routeKey = checkbox.dataset.routeKey;
    const routeGroup = activeTransitRoutes.get(routeKey);
    if (!routeGroup) return;

    const featureId = `transit-route:${routeKey}`;
    let routeFeature = transitRouteSource.getFeatureById(featureId);

    if (checkbox.checked) {
        if (!routeFeature) {
            const geometryRun = ++transitRouteGeometryRun;
            checkbox.disabled = true;
            try {
                const routeGeometries = await Promise.all(
                    routeGroup.routes.map(async route => {
                        const match = /^relation\/(\d+)$/.exec(route.id);
                        if (!match) return [];
                        if (unavailableTransitRouteGeometry.has(route.id)) return [];

                        let data;
                        try {
                            data = await fetchJson(`/api/transit-route/${match[1]}`);
                        } catch (error) {
                            if (error instanceof HttpError && error.status === 404) {
                                unavailableTransitRouteGeometry.add(route.id);
                                return [];
                            }
                            throw error;
                        }

                        const segments = Array.isArray(data.segments)
                            ? data.segments
                                .filter(segment => Array.isArray(segment) && segment.length >= 2)
                                .map(segment => segment
                                    .filter(coordinate => Array.isArray(coordinate) && coordinate.length >= 2)
                                    .map(([lon, lat]) => fromLonLat([lon, lat]))
                                )
                            : [];
                        if (segments.length === 0) unavailableTransitRouteGeometry.add(route.id);

                        return segments;
                    })
                );
                const lineCoordinates = routeGeometries.flat()
                    .filter(coordinates => coordinates.length >= 2);

                if (geometryRun !== transitRouteGeometryRun || !checkbox.checked) return;

                if (lineCoordinates.length === 0) {
                    throw new Error(t('OpenStreetMap did not return the route geometry.'));
                }

                routeFeature = new Feature({
                    geometry: new MultiLineString(lineCoordinates),
                });
                routeFeature.setId(featureId);
                routeFeature.set('routeKey', routeKey);
                routeFeature.set('routeColor', transitRouteColors.get(routeKey));
                transitRouteSource.addFeature(routeFeature);
                visibleTransitRoutes.add(routeKey);
            } catch (error) {
                if (geometryRun !== transitRouteGeometryRun || !checkbox.checked) return;
                checkbox.checked = false;
                if (checkbox._routeButton) {
                    checkbox.dataset.geometryUnavailable = 'true';
                    checkbox._routeButton.title = t('The timetable can be viewed; the route geometry is not available in OpenStreetMap.');
                    checkbox._dashboardRouteButton?.classList.remove('is-selected');
                    if (checkbox._dashboardRouteButton) {
                        const status = checkbox._dashboardRouteButton.parentElement?.querySelector('.dashboard-stop-route-status');
                        if (status) {
                            status.textContent = t('The route geometry is not available in OpenStreetMap.');
                            status.hidden = false;
                        }
                    }
                } else {
                    console.error('Transit route geometry could not be loaded:', error);
                    Swal.fire({
                        icon: 'error',
                        title: t('The route could not be loaded'),
                        text: error.message,
                    });
                }
            } finally {
                checkbox.disabled = false;
            }
        } else {
            visibleTransitRoutes.add(routeKey);
        }
    } else {
        if (routeFeature) {
            transitRouteSource.removeFeature(routeFeature);
        }
        visibleTransitRoutes.delete(routeKey);
    }
});

window.addEventListener('symbiot:osm-route-toggle', (event) => {
    const { group, feature, checkbox, button, status } = event.detail ?? {};
    if (!group || !feature || !checkbox || !button) return;

    if (checkbox.checked || visibleTransitRoutes.has(group.key)) {
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        button.classList.remove('is-selected');
        status.hidden = true;
        window.dispatchEvent(new CustomEvent('symbiot:osm-route-state', {
            detail: { routeKey: group.key, selected: false },
        }));
        return;
    }

    clearVisibleTransitRoutes();
    activeTransitRoutes.set(group.key, group);
    assignTransitRouteColors([group], transitRouteColors, manuallySelectedTransitRouteColors);
    checkbox._routeButton = button;
    button.classList.add('is-selected');
    button.querySelector('.dashboard-stop-route-badge')?.style.setProperty('--transit-route-color', getTransitRouteColor(group.key, transitRouteColors));
    button.querySelector('.dashboard-stop-route-badge')?.style.setProperty('background-color', getTransitRouteColor(group.key, transitRouteColors));
    checkbox.checked = true;
    status.textContent = t('Loading route…');
    status.hidden = false;
    window.dispatchEvent(new CustomEvent('symbiot:osm-route-state', {
        detail: { routeKey: group.key, selected: true, color: getTransitRouteColor(group.key, transitRouteColors) },
    }));
    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
    loadBusArrival(feature, group, status);
});

function updateMetroArrivalEstimate() {
    const estimateElement = document.getElementById('metroArrivalEstimate');
    if (!estimateElement) return;

    const selectedLines = document.querySelectorAll('#transitLinesList .poi-route-checkbox:checked');
    if (selectedLines.length !== 1) {
        estimateElement.textContent = t('Select exactly one metro line to estimate the next train.');
        return;
    }

    const { from, until } = getMetroArrivalWindow();
    estimateElement.textContent = t('Next train: estimated between :from and :until (generic ~5 min interval; this is not live information and does not confirm service).', { from, until });
}

function hidePoiTooltip() {
    poiTooltipElement.hidden = true;
    poiTooltip.setPosition(undefined);
}

function clearVisibleTransitRoutes() {
    transitRouteGeometryRun++;
    visibleTransitRoutes.clear();
    activeTransitRoutes.clear();
    transitRouteSource.clear();
    window.dispatchEvent(new CustomEvent('symbiot:osm-route-state', { detail: { clear: true } }));
}

map.on('pointermove', event => {
    const feature = poiFeatureAtPixel(map, poiSource, event.pixel);
    map.getTargetElement().style.cursor = feature ? 'pointer' : '';
});

bindPoiMapClick({
    target: document,
    map,
    poiSource,
    tooltipElement: poiTooltipElement,
    showTooltip: showPoiTooltip,
    hideTooltip: hidePoiTooltip,
    ignoreFeatureAtPixel: pixel => transitStops.hasStopAtPixel(pixel),
});

map.getViewport().addEventListener('pointerleave', () => {
    map.getTargetElement().style.cursor = '';
});






const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content');






function initLocalFeatures() {

    if (userFeature) return;

    const center = currentUserLocation
        ? fromLonLat([currentUserLocation.lon, currentUserLocation.lat])
        : fromLonLat([25.6, 45.65]);

    userFeature = new Feature({
        geometry: new Point(center)
    });

    userFeature.setStyle(userStyle);

    trackFeature = new Feature({
        geometry: new LineString([])
    });

    trackFeature.setStyle(trackStyle);

    radiusFeature = new Feature({
        geometry: new Circle(
            center,
            Number(app_radius)
        )
    });

    radiusFeature.setGeometry(
        circular(
            fromLonLat([currentUserLocation.lon, currentUserLocation.lat], 'EPSG:4326'),
            Number(app_radius),
            64
        ).transform('EPSG:4326', 'EPSG:3857')
    );

    radiusFeature.setStyle(radiusStyle);

    vectorSource.addFeature(radiusFeature);
    vectorSource.addFeature(trackFeature);
    vectorSource.addFeature(userFeature);
}





function updateUserOnMap(userId, lat, lon) {

    const coords = fromLonLat([lon, lat]);

    let feature = users.get(userId);

    if (!feature) {

        feature = new Feature({
            geometry: new Point(coords)
        });

        userSource.addFeature(feature);

        users.set(userId, feature);

    } else {
        feature
            .getGeometry()
            .setCoordinates(coords);
    }
}






function renderPOI(data = [], userLat, userLon) {

    poiSource.clear();

    if (!Array.isArray(data)) return;

    const features = [];

    for (const item of data) {
        const coordinates = getPoiCoordinates(item);
        if (!coordinates) continue;
        const { lat, lon } = coordinates;

        const distance = getDistanceMeters(userLat, userLon, lat, lon);

        if (distance > Number(app_radius)) continue;

        const coords = fromLonLat([lon, lat]);

        const feature = new Feature({
            geometry: new Point(coords)
        });

        feature.setProperties({
            id: item.id,
            name: item.name,
            type: normalizePoiType(item.type),
            address: item.address,
            details: item.details ?? {},
            coordinates,
            routes: item.routes ?? [],
            routes_available: item.routes_available,
            train_services: item.train_services ?? [],
            train_services_available: item.train_services_available,
            train_timetable_valid_until: item.train_timetable_valid_until ?? null,
            train_timetable_source: item.train_timetable_source ?? null,
            distance
        });

        features.push(feature);
    }

    poiSource.addFeatures(features);
}





function getLocation() {

    return new Promise((resolve, reject) => {

        navigator.geolocation.getCurrentPosition(

            (pos) => resolve({
                lat: pos.coords.latitude,
                lon: pos.coords.longitude
            }),

            (err) => {

                console.warn(
                    'High accuracy failed, retrying low accuracy...',
                    err
                );

                navigator.geolocation.getCurrentPosition(

                    (pos2) => resolve({
                        lat: pos2.coords.latitude,
                        lon: pos2.coords.longitude
                    }),

                    (err2) => reject(err2),

                    {
                        enableHighAccuracy: false,
                        timeout: 60000,
                        maximumAge: 60000
                    }
                );
            },

            {
                enableHighAccuracy: true,
                timeout: 20000,
                maximumAge: 0
            }
        );
    });
}





async function loadTourismPOI() {

    let cl = document.getElementById('current_location');
    const lat = parseFloat(cl.dataset.lat);
    const lon = parseFloat(cl.dataset.lon);


    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        console.warn('Invalid coordinates');
        hidePOIModal();
        return;
    }

    const url =
        `/api/transport-nearby?lat=${lat}&lon=${lon}&radius=${app_radius}&types=tourism`;

    let data;
    try {
        data = await fetchJson(url);
    } catch (error) {
        console.error('Tourism POI request failed:', error);
        hidePOIModal();
        Swal.fire({ icon: 'error', title: t('Tourist attractions'), text: error.message });
        return;
    }

    showResultData(data, lat, lon);
    addUserEvents();
}






function getGoogleMapsUrl() {

    if (!currentUserLocation) {
        return null;
    }

    return `https://www.google.com/maps?q=${currentUserLocation.lat},${currentUserLocation.lon}`;
}

async function shareLocationWhatsApp() {

    const googleMapsUrl = getGoogleMapsUrl();

    if (!googleMapsUrl) {

        Swal.fire({
            title: t('GPS inactive'),
            text: t('Activate location first'),
            icon: 'warning'
        });

        return;
    }

    try {

        await navigator.clipboard.writeText(
            googleMapsUrl
        );

    } catch (error) {

        console.warn(
            'Clipboard failed',
            error
        );
    }

    const text = encodeURIComponent(
        `📍 ${t('My current location')}:\n${googleMapsUrl}`
    );

    const whatsappUrl =
        `https://wa.me/?text=${text}`;

    window.open(
        whatsappUrl,
        '_blank'
    );
}





async function shareLocation() {

    if (!currentUserLocation) {

        Swal.fire({
            title: t('GPS inactive'),
            text: t('Activate location first'),
            icon: 'warning'
        });

        return;
    }

    const { lat, lon } = currentUserLocation;

    const googleMapsUrl =
        `https://www.google.com/maps?q=${lat},${lon}`;


    if (navigator.share) {

        try {

            await navigator.share({

                title: t('My Location'),

                text: `📍 ${t('My current location')}`,

                url: googleMapsUrl
            });

            return;

        } catch (error) {

            console.warn(error);
        }
    }


    const text = encodeURIComponent(
        `📍 ${t('My current location')}:\n${googleMapsUrl}`
    );

    const whatsappUrl =
        `https://wa.me/?text=${text}`;

    window.open(
        whatsappUrl,
        '_blank'
    );
}







let turismBtn = document.getElementById('turismLocations')
turismBtn?.addEventListener('click', () => {
    loadTourismPOI();
});





const shareBtn =
    document.getElementById('shareLocation');

if (shareBtn) {

    shareBtn.addEventListener(
        'click',
        async () => {

            await shareLocation();
        }
    );
}

function createInputLocationElement(create) {
    if(create) {
        if (document.getElementById('current_location')) return;

        let currentLocation = document.createElement('input');
        currentLocation.setAttribute('id', 'current_location');
        currentLocation.classList.add('d-none');
        document.body.appendChild(currentLocation);        
    }
    else {
        let cl = document.getElementById('current_location');
        if(cl) {
            cl.remove();
        }
    }
}

document.getElementById('city-location-mode')?.addEventListener('change', async event => {
    if (!event.target.checked) return;

    window.dispatchEvent(new CustomEvent('symbiot:location-changing', { detail: { mode: 'city' } }));
    resetPOIs();

    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }
    tracking = false;
    hasCentered = false;
    currentUserLocation = null;
    document.getElementById('toggleLocation').checked = false;
    document.getElementById('city-location-form')?.classList.remove('d-none');
    poiRequestId++;
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
    if (pendingCityResults.length > 0) {
        const selectedIndex = Number(resultsSelect.value);
        if (resultsSelect.value === '' || !pendingCityResults[selectedIndex]) {
            status.textContent = t('Select the correct city from the list.');
            status.className = 'col-12 small text-danger';
            resultsSelect.focus();
            return;
        }

        result = pendingCityResults[selectedIndex];
    }

    window.dispatchEvent(new CustomEvent('symbiot:location-changing', { detail: { mode: 'city' } }));
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
                pendingCityResults = candidates;
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
                return;
            }

            result = candidates[0];
        }

        resetPOIs();
        showBestGasPriceButton(result.country_code);

        pendingCityResults = [];
        resultsSelect.disabled = true;
        resultsContainer.classList.add('d-none');
        result.lat = Number(result.lat);
        result.lon = Number(result.lon);
        createInputLocationElement(true);
        const currentLocation = document.getElementById('current_location');
        currentLocation.dataset.lat = result.lat;
        currentLocation.dataset.lon = result.lon;
        cityRadiusMeters = radiusMeters;
        app_radius = cityRadiusMeters;
        citywideSearch = false;
        cityBoundaryFeature = null;
        cityBoundaryRadiusMeters = null;
        dashboardCitywide && (dashboardCitywide.checked = false);
        cityBoundarySource.clear();
        fixedCityLocation = { lat: result.lat, lon: result.lon, placeId: result.place_id || null };
        currentUserLocation = { lat: result.lat, lon: result.lon };
        hasCentered = true;
        initLocalFeatures();

        const center = fromLonLat([result.lon, result.lat]);
        userFeature.getGeometry().setCoordinates(center);
        trackFeature.getGeometry().setCoordinates([]);
        radiusFeature.setGeometry(
            circular([result.lon, result.lat], Number(app_radius), 64)
                .transform('EPSG:4326', 'EPSG:3857')
        );
        map.getView().setCenter(center);
        map.getView().setZoom(13);
        document.getElementById('map_card')?.classList.remove('d-none');

        status.textContent = t('Location has been set to :place, with a radius of :radius m.', {
            place: result.label || result.name,
            radius: cityRadiusMeters.toLocaleString(intlLocale),
        });
        status.className = 'col-12 small text-success';
        window.dispatchEvent(new CustomEvent('symbiot:location-set', {
            detail: {
                label: result.label || result.name || city,
                deferPoiCategories: true,
            },
        }));
        syncDashboardRadius(app_radius, true);
        setTimeout(() => map.updateSize(), 100);

        mbc?.classList.add('d-none');
        watchTransitCoverage(result.lat, result.lon, status, async () => {
            const selectedFilters = selectedPoiFilterTokens(
                document.getElementById('mobility_card')
            );

            syncDashboardRadius(app_radius, true);
            if (selectedFilters.includes('bus')) {
                try {
                    await transitStops.load(
                        result.lat,
                        result.lon,
                        Math.min(Number(app_radius) || 1500, 35000)
                    );
                    transitStops.watchVehicles(result.lat, result.lon);
                } catch (error) {
                    console.error('Transit stops load failed:', error);
                }
            }

            mbc?.classList.remove('d-none');
            if (selectedFilters.length > 0) {
                await loadNearby({ lat: result.lat, lon: result.lon });
            }
        }, Math.min(Number(app_radius) || 1500, 35000));
    } catch (error) {
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
    pendingCityResults = [];
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

    cityRadiusMeters = radiusMeters;
    app_radius = cityRadiusMeters;
    syncDashboardRadius(radiusMeters, true);
    if (fixedCityLocation && radiusFeature) {
        radiusFeature.setGeometry(
            circular(
                [fixedCityLocation.lon, fixedCityLocation.lat],
                cityRadiusMeters,
                64
            ).transform('EPSG:4326', 'EPSG:3857')
        );
        status.textContent = t('Search radius: :radius m.', { radius: cityRadiusMeters.toLocaleString(intlLocale) });
        status.className = 'col-12 small text-success';
        if (selectedPoiFilterTokens(document.getElementById('mobility_card')).length > 0) {
            loadNearby(fixedCityLocation);
        }
    }
});






// document
//     .getElementById('toggleLocation')
//     ?.addEventListener('change', async function () {

//         if (!this.checked) return;

//         tracking = true;
//         fixedCityLocation = null;
//         app_radius = deviceRadiusMeters;
//         document.getElementById('city-location-mode').checked = false;
//         document.getElementById('city-location-form')?.classList.add('d-none');

//         let mapCard =
//             document.getElementById('map_card');

//         let mobCard =
//             document.getElementById('mobility_card');

//         let shareBtn = document.getElementById('shareLocation');    
//         let turismBtn = document.getElementById('turismLocations');
//         await fetch(
//             '/location/toggle',
//             {
//                 method: 'POST',

//                 headers: {
//                     'Content-Type': 'application/json',
//                     'X-CSRF-TOKEN':  csrfToken
//                 },

//                 body: JSON.stringify({
//                     location: this.checked
//                 })
//             }
//         );


//         document.getElementById("i_location").classList.add("rotate3d-y");

//         mobCard.querySelector('.row')
//             .classList.remove('mt-3');

//         mobCard.classList.remove('d-none');
//         mapCard.classList.remove('d-none');

//         setTimeout(() => {
//             map.updateSize();
//         }, 100);

//         createInputLocationElement(true);

//         if (!navigator.geolocation) {
//             Swal.fire({
//                 icon: 'error',
//                 title: 'Geolocation not supported',
//                 text: 'Your browser does not support geolocation.'
//             });

//             tracking = false;
//             this.checked = false;
//             return;
//         }

//         watchId =
//             navigator.geolocation.watchPosition(

//                 (position) => {

//                     let lon =
//                         position.coords.longitude;

//                     let lat =
//                         position.coords.latitude;

//                     if (lastCoords) {

//                         lon =
//                             lastCoords.lon * 0.7 +
//                             lon * 0.3;

//                         lat =
//                             lastCoords.lat * 0.7 +
//                             lat * 0.3;
//                     }

//                     lastCoords = {
//                         lon,
//                         lat
//                     };

//                     currentUserLocation = {
//                         lon,
//                         lat
//                     };

//                     document
//                         .getElementById('shareLocation')
//                         ?.classList.remove('d-none');
//                     document
//                         .getElementById('turismLocations')
//                         ?.classList.remove('d-none');

//                     const coords =
//                         fromLonLat([lon, lat]);
//                     const isFirstLocationFix = !hasCentered;

//                     if (lastPosition) {

//                         const dx =
//                             lon - lastPosition.lon;

//                         const dy =
//                             lat - lastPosition.lat;

//                         heading =
//                             Math.atan2(dy, dx);
//                     }

//                     lastPosition = {
//                         lon,
//                         lat
//                     };

//                     requestAnimationFrame(() => {

//                         userFeature
//                             .getGeometry()
//                             .setCoordinates(coords);                        

//                         if (radiusFeature) {

//                             radiusFeature.setGeometry(
//                                 circular(
//                                     [lon, lat],
//                                     Number(app_radius),
//                                     64
//                                 ).transform('EPSG:4326', 'EPSG:3857')
//                             );
//                         }                        

//                         userStyle
//                             .getImage()
//                             .setRotation(heading);

//                         userFeature.setStyle(
//                             userStyle
//                         );

//                         const geometry =
//                             trackFeature.getGeometry();

//                         geometry.appendCoordinate(
//                             coords
//                         );

//                         const coordinates =
//                             geometry.getCoordinates();

//                         if (
//                             coordinates.length > 1000
//                         ) {

//                             coordinates.shift();

//                             geometry.setCoordinates(
//                                 coordinates
//                             );
//                         }
//                     });

//                     if (!hasCentered) {

//                         map.getView().animate({

//                             center: coords,

//                             zoom: 17,

//                             duration: 500
//                         });

//                         hasCentered = true;
//                     }


//                     let shareBtn = document.getElementById('shareLocation');
//                     shareBtn.classList.remove('d-none');

//                     let currentLocation = document.getElementById('current_location');
//                     currentLocation.dataset.lat = lat;    
//                     currentLocation.dataset.lon = lon;

//                     initLocalFeatures();
//                     updateSubcategoryAvailability(lat, lon);
//                     const mobilityCard = document.getElementById('mobility_card');
//                     if (isFirstLocationFix
//                         && mobilityCard
//                         && selectedPoiFilterTokens(mobilityCard).length > 0) {
//                         loadNearby({ lat, lon });
//                     }

//                     const now = Date.now();

//                     if (
//                         now - lastSent > 5000
//                     ) {

//                         lastSent = now;

//                         fetch(
//                             '/location/update',
//                             {
//                                 method: 'POST',

//                                 headers: {
//                                     'Content-Type':
//                                         'application/json',

//                                     'X-CSRF-TOKEN':
//                                         csrfToken
//                                 },

//                                 body: JSON.stringify({
//                                     lat,
//                                     lon,
//                                     active: true,
//                                     heading
//                                 })
//                             }
//                         );
//                     }
//                 },

//                 console.error,

//                 {
//                     enableHighAccuracy: true,
//                     timeout: 10000,
//                     maximumAge: 0
//                 }
//             );
//     });



document
    .getElementById('toggleLocation')
    ?.addEventListener('change', async function () {
        const toggle = this;

        if (!toggle.checked) {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }

            tracking = false;
            hasCentered = false;
            lastCoords = null;
            lastPosition = null;
            heading = null;
            currentUserLocation = null;
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

        window.dispatchEvent(new CustomEvent('symbiot:location-changing', { detail: { mode: 'gps' } }));

        // Switching from a city to GPS starts with a clean set of map filters and POIs.
        resetPOIs();

        // Oprește orice urmărire GPS precedentă.
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }

        tracking = true;
        hasCentered = false;
        lastCoords = null;
        lastPosition = null;
        heading = null;
        fixedCityLocation = null;

        app_radius = deviceRadiusMeters;

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

        setTimeout(() => map.updateSize(), 100);

        // Înregistrează activarea locației pe server.
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

        let initialized = false;

        const initializeGpsArea = async (lat, lon) => {
            if (initialized) return;
            initialized = true;

            const radiusMeters = Number(deviceRadiusMeters);

            if (!Number.isFinite(radiusMeters) || radiusMeters <= 0) {
                initialized = false;
                throw new Error(t('The configured GPS radius is not valid.'));
            }

            // Salvează coordonatele GPS reale.
            currentLocation.dataset.lat = String(lat);
            currentLocation.dataset.lon = String(lon);

            currentUserLocation = { lat, lon };
            fixedCityLocation = null;
            app_radius = radiusMeters;
            cityRadiusMeters = radiusMeters;

            // Inițializează markerul și geometria locală.
            initLocalFeatures();

            const center = fromLonLat([lon, lat]);

            userFeature.getGeometry().setCoordinates(center);
            trackFeature.getGeometry().setCoordinates([]);

            // Desenează cercul exact la raza configurată.
            radiusFeature.setGeometry(
                circular([lon, lat], radiusMeters, 96)
                    .transform('EPSG:4326', 'EPSG:3857')
            );

            map.getView().animate({
                center: center,
                zoom: 16,
                duration: 500
            });

            mapCard?.classList.remove('d-none');
            mobilityCard?.classList.remove('d-none');
            window.dispatchEvent(new CustomEvent('symbiot:location-set', {
                detail: { label: t('GPS location') },
            }));
            syncDashboardRadius(app_radius, true);

            setTimeout(() => map.updateSize(), 100);

            document.getElementById('shareLocation')?.classList.remove('d-none');
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

                    syncDashboardRadius(app_radius, true);
                },
                Math.min(radiusMeters, 35000)
            );

            // Încarcă POI-urile pentru toate filtrele selectate.
            if (selectedPoiFilterTokens(mobilityCard).length > 0) {
                await loadNearby({ lat, lon });
            }
        };

        watchId = navigator.geolocation.watchPosition(
            async position => {
                if (!tracking || !toggle.checked) return;

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

                lastCoords = { lat, lon };
                currentUserLocation = { lat, lon };
                // Live vehicles follow the user (GPS mode only; this watch is cleared in city mode).
                transitStops.moveVehicleCenter(lat, lon);

                const currentCenter = fromLonLat([lon, lat]);

                const gpsHeading = position.coords.heading;
                const gpsSpeed = position.coords.speed;
                if (Number.isFinite(gpsHeading) && Number.isFinite(gpsSpeed) && gpsSpeed > 0) {
                    heading = gpsHeading * Math.PI / 180;
                    lastPosition = { lat, lon };
                } else if (!lastPosition) {
                    lastPosition = { lat, lon };
                } else if (approximateDistanceMeters(lastPosition, { lat, lon }) >= HEADING_MIN_MOVE_METERS) {
                    // Keep the previous heading for GPS jitter below the threshold.
                    heading = bearingBetween(lastPosition, { lat, lon });
                    lastPosition = { lat, lon };
                }

                currentLocation.dataset.lat = String(lat);
                currentLocation.dataset.lon = String(lon);

                requestAnimationFrame(() => {
                    userFeature.getGeometry().setCoordinates(currentCenter);

                    if (radiusFeature) {
                        radiusFeature.setGeometry(
                            circular(
                                [lon, lat],
                                Number(app_radius),
                                96
                            ).transform('EPSG:4326', 'EPSG:3857')
                        );
                    }

                    if (heading === null) {
                        userFeature.setStyle(userStyle);
                    } else {
                        userArrowStyle.getImage().setRotation(heading);
                        userFeature.setStyle(userArrowStyle);
                        userFeature.changed();
                    }

                    const geometry = trackFeature.getGeometry();
                    geometry.appendCoordinate(currentCenter);

                    const coordinates = geometry.getCoordinates();

                    if (coordinates.length > 1000) {
                        coordinates.shift();
                        geometry.setCoordinates(coordinates);
                    }
                });

                // Prima poziție inițializează întreaga zonă.
                if (!initialized) {
                    try {
                        await initializeGpsArea(lat, lon);
                    } catch (error) {
                        initialized = false;
                        console.error('GPS area initialization failed:', error);

                        if (status) {
                            status.textContent = error.message;
                            status.className = 'col-12 small text-danger';
                        }
                    }
                }

                const now = Date.now();

                if (now - lastSent > 5000) {
                    lastSent = now;

                    fetchJson('/location/update', {
                        method: 'POST',
                        body: JSON.stringify({
                            lat,
                            lon,
                            active: true,
                            // The API expects degrees in 0..360.
                            heading: heading === null
                                ? null
                                : ((heading * 180 / Math.PI) + 360) % 360
                        })
                    }).catch(error => {
                        console.error('Location update failed:', error);
                    });
                }
            },
            error => {
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

                if (!initialized) {
                    tracking = false;
                    toggle.checked = false;

                    if (watchId !== null) {
                        navigator.geolocation.clearWatch(watchId);
                        watchId = null;
                    }
                }
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    });



function resetMap() {

    if (watchId !== null) {

        navigator.geolocation.clearWatch(
            watchId
        );

        watchId = null;
    }

    tracking = false;

    vectorSource.clear();

    poiSource.clear();
    clearVisibleTransitRoutes();
    userSource.clear();
    users.clear();

    userFeature = null;
    trackFeature = null;

    radiusFeature = null;

    lastCoords = null;
    lastPosition = null;

    currentUserLocation = null;

    document
        .getElementById('shareLocation')
        ?.classList.add('d-none');

    heading = null;
    hasCentered = false;

    map.getView().animate({

        center: fromLonLat([25.6, 45.65]),

        zoom: 12,

        duration: 500
    });

    fetchJson('/location/update', {
        method: 'POST',
        body: JSON.stringify({
            active: false
        })
    }).catch(error => {
        console.error('Location reset failed:', error);
    });
}





async function loadNearby(locationOverride = null) {

    const requestId = ++poiRequestId;
    poiLoadController?.abort();
    const loadController = new AbortController();
    poiLoadController = loadController;
    let notification = null;

    try {

        const cl = document.getElementById('current_location');

        if (!cl && !locationOverride) {
            hidePOIModal();
            return;
        }

        const lat = locationOverride?.lat ?? parseFloat(cl.dataset.lat);
        const lon = locationOverride?.lon ?? parseFloat(cl.dataset.lon);

        if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
            hidePOIModal();
            return;
        }

        const poiContainer = document.getElementById('mobility_card');
        const hasSelectedFilters = Boolean(
            poiContainer?.querySelector('.location-category:checked, .location-subcategory:checked')
        );
        if (hasSelectedFilters) {
            showPOIModal();
        }

        allPoisRaw = [];
        allPois = [];
        activePoiFilters.clear();
        addUserEvents();

        await updateSubcategoryAvailability(lat, lon, loadController.signal);
        if (requestId !== poiRequestId) return;

        let selectedTypes = poiContainer ? selectedPoiFilterTokens(poiContainer) : [];
        if (selectedTypes.length > 0) {
            window.dispatchEvent(new CustomEvent('symbiot:nearby-loading'));
        }
        transitStops.setVisible(selectedTypes.includes('bus'));
        // Bus stops and lines come from the stored transit feeds (blue stops) wherever they exist;
        // the OpenStreetMap bus POI layer stays only as a fallback for uncovered areas.
        if (selectedTypes.includes('bus')) {
            let hasStoredStops = false;
            try {
                hasStoredStops = await transitStops.load(
                    lat,
                    lon,
                    Math.min(Number(app_radius) || 1500, 35000)
                );
            } catch (error) {
                console.error('Stored transit stops could not be loaded; trying OpenStreetMap POIs.', error);
            }
            if (requestId !== poiRequestId) return;
            if (hasStoredStops) {
                selectedTypes = selectedTypes.filter(type => type !== 'bus');
            }
        }
        if (selectedTypes.length === 0) {
            poiRequestId++;
            poiSource.clear();
            clearVisibleTransitRoutes();
            hidePoiTooltip();
            hidePOIModal();
            window.dispatchEvent(new CustomEvent('symbiot:nearby-cleared'));

            return;
        }

        const transitEndpoints = {
            bus: '/api/transport/bus',
            train: '/api/transport/train',
            subway: '/api/transport/subway',
            airport: '/api/transport/airport',
        };
        const categoryEndpoints = {
            fuel: '/api/poi/fuel',
            parking: '/api/poi/parking',
            restaurant: '/api/poi/restaurant',
            cafe: '/api/poi/cafe',
            lodging: '/api/poi/lodging',
            supermarket: '/api/poi/supermarket',
            taxi: '/api/poi/taxi',
            hospital: '/api/poi/hospital',
            pharmacy: '/api/poi/pharmacy',
            fire: '/api/poi/fire',
            tourism: '/api/poi/tourism',
            charging_station: '/api/poi/charging_station',
            police: '/api/poi/police',
            speed_camera: '/api/poi/speed_camera',
            speed_limit: '/api/poi/speed_limit',
            traffic_sign: '/api/poi/traffic_sign',
            vignette_control: '/api/poi/vignette_control',
            control: '/api/poi/control',
            locality: '/api/poi/locality',
        };
        const categoryLabels = new Map(
            Array.from(poiContainer.querySelectorAll('.poi-category-group'))
                .map(group => [
                    group.dataset.type,
                    // The element right after the checkbox is the icon, not the visible name.
                    group.querySelector('.poi-filter-name')?.textContent?.trim()
                        || group.dataset.type,
                ])
        );
        const cacheKey = `${lat.toFixed(4)}:${lon.toFixed(4)}:${app_radius}`;
        if (poiResultCache.key !== cacheKey) {
            poiResultCache = { key: cacheKey, tokens: new Set(), data: [] };
        }
        // Only filters not already loaded for this place and radius are requested again.
        const pendingTypes = selectedTypes.filter(token => !poiResultCache.tokens.has(token));
        const requests = buildPoiCategoryRequests(pendingTypes, {
            transitEndpoints,
            categoryEndpoints,
            wazeEndpoint: '/api/poi/waze-traffic',
            categoryLabels,
            lat,
            lon,
            radius: app_radius,
        });

        const responses = await Promise.allSettled(
            requests.map(({ url }) => fetchPoiJsonResponse(url, { signal: loadController.signal }))
        );
        if (requestId !== poiRequestId) return;
        const successfulResponses = responses
            .filter(result => result.status === 'fulfilled');
        const failedRequests = responses
            .flatMap((result, index) => result.status === 'rejected'
                ? [`${requests[index].label} (${result.reason?.message || t('request failed')})`]
                : []);

        responses.forEach((result, index) => {
            if (result.status !== 'fulfilled') return;
            const { category } = requests[index];
            if (category === 'poi') {
                pendingTypes
                    .filter(token => !Object.hasOwn(transitEndpoints, token))
                    .forEach(token => poiResultCache.tokens.add(token));
            } else if (category !== 'waze-traffic') {
                pendingTypes
                    .filter(token => token === category || token.startsWith(`subcategory:${category}:`))
                    .forEach(token => poiResultCache.tokens.add(token));
            }
            poiResultCache.data.push(...result.value);
        });
        if (successfulResponses.length > 0 || pendingTypes.length === 0) {
            const seen = new Set();
            poiResultCache.data = poiResultCache.data.filter(poi => {
                const key = `${poi.type}:${poi.id ?? ''}:${poi.lat}:${poi.lon}:${poi.name ?? ''}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });
        }
        const data = poiResultCache.data;

        if (requestId !== poiRequestId) return;
        if (successfulResponses.length === 0 && failedRequests.length > 0) {
            throw new Error(failedRequests.join('; '));
        }

        showResultData(data, lat, lon);
        if (failedRequests.length > 0) {
            notification = {
                title: t('Partial results'),
                text: t('Not all filters could be loaded: :errors', { errors: failedRequests.join('; ') }),
                icon: 'warning'
            };
        }

    } catch (error) {
        if (requestId !== poiRequestId) return;
        
        console.error(error.message);
        window.dispatchEvent(new CustomEvent('symbiot:nearby-error', {
            detail: { message: t('Could not load the points. Check your connection and the selected filters.') },
        }));
        notification = {
            title: t('Error loading the categories'),
            text: error.message,
            icon: 'error',
        };
    } finally {
        if (requestId === poiRequestId) {
            await hidePOIModal();
            if (notification) {
                await Swal.fire(notification);
            }
        }

    }
}

















function sortLocations(locations, sortBy = 'name', direction = 'asc') {

    return [...locations].sort((a, b) => {

        let result = 0;

        switch (sortBy) {

            case 'distance':

                result =
                    (a.distance?.meters || 0) -
                    (b.distance?.meters || 0);

                break;

            case 'name':
            default:

                result = (a.name || '').localeCompare(
                    b.name || '',
                    'ro',
                    {
                        sensitivity: 'base',
                        numeric: true,
                    }
                );

                break;
        }

        return direction === 'desc'
            ? result * -1
            : result;
    });
}




function applyPOIFilters(catFilters, data) {
    if (!Array.isArray(data)) return [];

    if (!Array.isArray(catFilters) || catFilters.length === 0) return [];

    return data.filter(poi => {
        return catFilters.includes(poi.type);
    });
}

let subcategoriesKey = null;
let subcategoriesPendingKey = null;

// The lists open only once they are filtered for the chosen location, so they appear instantly when clicked.
function setSubcategoryButtonsLoading(loading) {
    document.querySelectorAll('.poi-subcategory-toggle').forEach(button => {
        if (loading) {
            button.dataset.label ??= button.textContent.trim();
            button.textContent = t('Loading…');
            button.disabled = true;
            document.querySelector(button.dataset.bsTarget)?.classList.remove('show');
        } else {
            button.textContent = button.dataset.label ?? t('Subcategories');
            button.disabled = false;
        }
    });
}

// Subcategories are limited to the ones that actually have places around the chosen location.
const SUBCATEGORY_TIMEOUT_MS = 10_000;

async function updateSubcategoryAvailability(lat, lon, signal = null) {
    const key = `${lat.toFixed(2)}:${lon.toFixed(2)}:${app_radius}`;
    if (key === subcategoriesKey || key === subcategoriesPendingKey) return;
    subcategoriesPendingKey = key;
    setSubcategoryButtonsLoading(true);

    // loadNearby() waits for this behind the loading modal: never let it hang.
    const timeout = AbortSignal.timeout(SUBCATEGORY_TIMEOUT_MS);
    const requestSignal = signal && typeof AbortSignal.any === 'function'
        ? AbortSignal.any([signal, timeout])
        : timeout;

    let available = null;
    try {
        const params = new URLSearchParams({ lat, lon, radius: Math.min(Math.round(Number(app_radius)) || 5000, 35000) });
        const response = await fetch(`/api/poi/subcategories?${params}`, {
            headers: { Accept: 'application/json' },
            signal: requestSignal,
        });
        if (response.ok) {
            available = (await response.json()).subcategories;
        }
    } catch (error) {
        console.error('Subcategory availability request failed.', error);
    } finally {
        if (subcategoriesPendingKey === key) {
            subcategoriesPendingKey = null;
            setSubcategoryButtonsLoading(false);
        }
    }
    if (!available || typeof available !== 'object') return;

    subcategoriesKey = key;
    document.querySelectorAll('.poi-category-group').forEach(group => {
        const allowedIds = available[group.dataset.type];
        if (!Array.isArray(allowedIds)) return;

        const allowed = new Set(allowedIds);
        group.querySelectorAll('.location-subcategory').forEach(input => {
            const visible = allowed.has(input.dataset.filter);
            input.disabled = !visible;
            if (!visible) input.checked = false;
            const row = input.closest('.poi-subcategory-choice');
            if (row) row.hidden = !visible;
        });

        const parent = group.querySelector('.location-category');
        if (parent) parent.disabled = false;
        group.classList.remove('opacity-50');
    });

    const container = document.getElementById('mobility_card');
    if (container) syncPoiFilterSelection(container);
}
function syncPoiFilterSelection(container) {
    activePoiFilters.clear();

    container.querySelectorAll('.poi-category-group').forEach(group => {
        const parent = group.querySelector('.location-category');
        const children = Array.from(group.querySelectorAll('.location-subcategory:not(:disabled)'));
        const selectedChildren = children.filter(checkbox => checkbox.checked);

        if (parent) {
            if (children.length > 0) {
                parent.checked = selectedChildren.length === children.length;
                parent.indeterminate = selectedChildren.length > 0 && selectedChildren.length < children.length;
            } else {
                parent.indeterminate = false;
            }
        }

        if (parent?.checked) {
            activePoiFilters.add(group.dataset.type);
        } else if (selectedChildren.length > 0) {
            selectedChildren.forEach(child => {
                activePoiFilters.add(child.dataset.type || group.dataset.type);
            });
        }
    });

    assignCategoryColors(activePoiFilters);
    poiLayer.changed();
}

function selectedPoiFilterTokens(container) {
    const selected = [];

    container.querySelectorAll('.poi-category-group').forEach(group => {
        const type = group.dataset.type;
        const children = Array.from(group.querySelectorAll('.location-subcategory:not(:disabled)'));
        const checkedChildren = children.filter(checkbox => checkbox.checked);

        if (children.length === 0) {
            if (group.querySelector('.location-category')?.checked) {
                selected.push(type);
            }
            return;
        }

        const childTypes = new Set(children.map(child => child.dataset.type || type));
        if (children.length > 0 && checkedChildren.length === children.length && childTypes.size === 1) {
            selected.push(type);
            return;
        }

        if (children.length > 0 && checkedChildren.length === children.length && childTypes.size > 1) {
            selected.push(type);
        }

        checkedChildren.forEach(checkbox => selected.push(checkbox.dataset.filter));
    });

    return selected;
}

function showResultData(data, lat, lon) {

    const filtered = filterByRadius(data, lat, lon, app_radius);

    const normalized = filtered.map(p => ({
        ...p,
        type: normalizePoiType(p.type)
    }));

    const selectedTypes = applyPOIFilters(Array.from(activePoiFilters), normalized);
    const enriched = enrichPOIWithDistance(selectedTypes, lat, lon);

    enriched.sort(
        (a, b) =>
            a.distance.meters - b.distance.meters
    );


    allPoisRaw = enriched;
    allPois = enriched;

    renderPOI(enriched, lat, lon);
    window.dispatchEvent(new CustomEvent('symbiot:nearby-pois', {
        detail: {
            pois: enriched.map(poi => ({
                id: poi.id ?? null,
                name: poi.name || poi.label || t('Unnamed point'),
                type: poi.type || 'other',
                distance: poi.distance?.meters ?? null,
                distanceLabel: poi.distance?.formatted || '',
                lat: poi.coordinates?.lat ?? poi.lat ?? null,
                lon: poi.coordinates?.lon ?? poi.lon ?? null,
            })),
        },
    }));
}

function addUserEvents () {
    const container = document.getElementById('mobility_card');
    if (!container) return;

    syncPoiFilterSelection(container);

    if (container.dataset.poiEventsBound === 'true') return;

    container.dataset.poiEventsBound = 'true';

    container.addEventListener('change', event => {
        const checkbox = event.target.closest('.location-category, .location-subcategory');
        if (!checkbox || !container.contains(checkbox)) return;

        const group = checkbox.closest('.poi-category-group');
        const children = group?.querySelectorAll('.location-subcategory:not(:disabled)') ?? [];

        if (checkbox.matches('.location-category')) {
            children.forEach(child => {
                child.checked = checkbox.checked;
            });
        }

        syncPoiFilterSelection(container);
        removeUnselectedPoiFeatures();
        transitStops.setVisible(selectedPoiFilterTokens(container).includes('bus'));

        if (selectedPoiFilterTokens(container).length === 0) {
            clearTimeout(poiLoadDebounce);
            poiLoadController?.abort();
            poiRequestId++;
            poiSource.clear();
            clearVisibleTransitRoutes();
            hidePoiTooltip();
            hidePOIModal();
            window.dispatchEvent(new CustomEvent('symbiot:nearby-cleared'));
            return;
        }

        const currentLocation = document.getElementById('current_location');
        if (currentLocation && Number.isFinite(parseFloat(currentLocation.dataset.lat))
            && Number.isFinite(parseFloat(currentLocation.dataset.lon))) {
            clearVisibleTransitRoutes();
            clearTimeout(poiLoadDebounce);
            poiLoadDebounce = setTimeout(() => {
                poiLoadDebounce = null;
                loadNearby();
            }, POI_FILTER_DEBOUNCE_MS);
        }
    });

    container.addEventListener('input', event => {
        const search = event.target.closest('.poi-category-search');
        if (!search || !container.contains(search)) return;

        const query = search.value.trim().toLocaleLowerCase();
        container.querySelectorAll('.poi-category-group').forEach(group => {
            const label = group.querySelector('.poi-filter-name')?.textContent?.trim().toLocaleLowerCase() ?? '';
            group.classList.toggle('poi-category-hidden', Boolean(query) && !label.includes(query));
        });
    });

    container.addEventListener('click', event => {
        if (!event.target.closest('.poi-clear-selection')) return;
        container.querySelector('.poi-category-search').value = '';
        container.querySelectorAll('.poi-category-group').forEach(group => {
            group.classList.remove('poi-category-hidden');
            group.querySelectorAll('.location-category, .location-subcategory').forEach(input => {
                input.checked = false;
            });
        });
        const firstCheckbox = container.querySelector('.location-category, .location-subcategory');
        if (firstCheckbox) firstCheckbox.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

addUserEvents();

/** Drops markers of deselected categories right away instead of waiting for the next response. */
function removeUnselectedPoiFeatures() {
    poiSource.getFeatures()
        .filter(feature => feature.get('type') && !activePoiFilters.has(feature.get('type')))
        .forEach(feature => poiSource.removeFeature(feature));
    allPoisRaw = (allPoisRaw ?? []).filter(poi => activePoiFilters.has(poi.type));
    allPois = (allPois ?? []).filter(poi => activePoiFilters.has(poi.type));
    clearVisibleTransitRoutes();
    hidePoiTooltip();
}

function enrichPOIWithDistance(poiList, userLat, userLon) {
    return poiList.map((item) => {
        const coordinates = getPoiCoordinates(item);
        const lat = coordinates?.lat;
        const lon = coordinates?.lon;

        let distanceMeters = null;

        if (typeof lat === 'number' && typeof lon === 'number') {
            distanceMeters = getDistanceMeters(
                userLat,
                userLon,
                lat,
                lon
            );
        }

        return {
            ...item,
            ...(coordinates ? { coordinates } : {}),
            distance: {
                meters: distanceMeters,
                km: distanceMeters ? distanceMeters / 1000 : null,
                formatted: distanceMeters < 1000
                    ? `${Math.round(distanceMeters)} m`
                    : `${(distanceMeters / 1000).toFixed(1)} km`
            }
        };
    });
}





function getDistanceMeters(lat1, lon1, lat2, lon2) {

    const R = 6371000;

    const toRad = (deg) =>
        deg * Math.PI / 180;

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

/** "Cancel" in the loading modal: stops the running POI load; POIs already on the map stay. */
function cancelPoiLoad() {
    clearTimeout(poiLoadDebounce);
    poiLoadController?.abort();
    poiRequestId++;
    hidePOIModal();
}

function showPOIModal() {
    const modalEl = document.getElementById('poiLoadingModal');
    if (!modalEl) return;

    if (!poiLoadingModalEventsBound) {
        modalEl.addEventListener('shown.bs.modal', () => {
            poiLoadingModalShown = true;
            poiLoadingModalShowing = false;
            startPoiElapsedTimer();
            if (poiLoadingModalHidePending) {
                poiLoadingModalHidePending = false;
                hidePOIModal();
            }
        });
        modalEl.addEventListener('hidden.bs.modal', () => {
            poiLoadingModalShown = false;
            poiLoadingModalShowing = false;
            poiLoadingModalHidePending = false;
            stopPoiElapsedTimer();
            poiLoadingModalHidePromise = null;
        });
        modalEl.querySelector('#poiLoadingCancel')?.addEventListener('click', cancelPoiLoad);
        poiLoadingModalEventsBound = true;
    }

    poiLoadingModalHidePending = false;
    startPoiElapsedTimer();

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    if (!poiLoadingModalShown) poiLoadingModalShowing = true;
    modal.show();
}

function startPoiElapsedTimer() {
    if (poiLoadingTimer !== null) return;

    const modalEl = document.getElementById('poiLoadingModal');
    const elapsed = modalEl?.querySelector('#poi-loading-elapsed');
    if (!elapsed) return;

    poiLoadingStartedAt = performance.now();

    const updateElapsed = () => {
        const elapsedElement = document
            .getElementById('poiLoadingModal')
            ?.querySelector('#poi-loading-elapsed');
        if (!elapsedElement || poiLoadingStartedAt === null) return;

        const seconds = Math.floor((performance.now() - poiLoadingStartedAt) / 1000);
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        elapsedElement.textContent = t('Elapsed time: :time', { time: `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}` });
    };

    updateElapsed();
    poiLoadingTimer = window.setInterval(updateElapsed, 250);
}

function stopPoiElapsedTimer() {
    if (poiLoadingTimer !== null) {
        window.clearInterval(poiLoadingTimer);
    }
    poiLoadingTimer = null;
    poiLoadingStartedAt = null;
}

function hidePOIModal() {
    stopPoiElapsedTimer();

    const modalEl = document.getElementById('poiLoadingModal');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getInstance(modalEl);
    if (!modal) return Promise.resolve();

    if (!poiLoadingModalShown) {
        // show() was already requested but the fade-in has not finished: hide as soon as it does.
        if (!poiLoadingModalShowing && !modalEl.classList.contains('show') && modalEl.style.display === 'none') {
            return Promise.resolve();
        }
        poiLoadingModalHidePending = true;
        if (!poiLoadingModalHidePromise) {
            poiLoadingModalHidePromise = new Promise(resolve => {
                modalEl.addEventListener('hidden.bs.modal', resolve, { once: true });
            });
        }
        return poiLoadingModalHidePromise;
    }

    if (!poiLoadingModalHidePromise) {
        poiLoadingModalHidePromise = new Promise(resolve => {
            modalEl.addEventListener('hidden.bs.modal', resolve, { once: true });
        });
    }

    if (modalEl.contains(document.activeElement)) {
        document.activeElement.blur();
    }
    modal.hide();
    return poiLoadingModalHidePromise;
}
