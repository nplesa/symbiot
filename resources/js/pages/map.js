import 'ol/ol.css';
import { bindCollapsibleCard } from '../collapsible-card.js';
import { watchTransitCoverage } from '../map/transit-coverage.js';
import { createTransitLayer } from '../map/transit-stops-layer.js';
import { fetchJson } from '../http.js';
import { MAP_ATTRIBUTION } from '../map-attribution.js';
import { t, intlLocale } from '../i18n.js';
import { haversineMeters } from '../geo.js';

import Map from 'ol/Map.js';
import View from 'ol/View.js';

import TileLayer from 'ol/layer/Tile.js';
import VectorLayer from 'ol/layer/Vector.js';

import XYZ from 'ol/source/XYZ.js';
import VectorSource from 'ol/source/Vector.js';
import Cluster from 'ol/source/Cluster.js';

import Feature from 'ol/Feature.js';

import Point from 'ol/geom/Point.js';
import LineString from 'ol/geom/LineString.js';

import { fromLonLat } from 'ol/proj.js';

import Style from 'ol/style/Style.js';
import Icon from 'ol/style/Icon.js';
import Fill from 'ol/style/Fill.js';
import Stroke from 'ol/style/Stroke.js';
import CircleStyle from 'ol/style/Circle.js';
import Text from 'ol/style/Text.js';

import Circle from 'ol/geom/Circle.js';

import { circular } from 'ol/geom/Polygon.js';
import GeoJSON from 'ol/format/GeoJSON.js';
import {
    bindPoiMapClick,
    getPoiMarkerIcon,
    poiFeatureAtPixel,
} from '../map/poi-map-interactions.js';
import { configureFuelPrices } from '../map/fuel-prices.js';
import { assignedCategoryColor } from '../map/poi-helpers.js';
import { configureLodgingDetails } from '../map/lodging-details.js';
import { watchTileProgress } from '../map/tile-progress.js';
import { activePoiFilters, mapState } from '../map/state.js';
import {
    addUserEvents,
    cancelPoiLoad,
    configurePoiLoading,
    loadNearby,
    loadTourismPOI,
    selectedPoiFilterTokens,
    syncPoiFilterSelection,
    updateSubcategoryAvailability,
} from '../map/poi-loading.js';
import { bindCitySearch } from '../map/city-search.js';
import { bindDeviceLocation } from '../map/device-location.js';
import { configurePoiLoadingModal, hidePOIModal } from '../map/poi-loading-modal.js';
import { bindShareLocation } from '../map/share-location.js';
import { loadWeatherForecast, resetWeather } from '../map/weather.js';
import { attachPoiTooltip, hidePoiTooltip, poiTooltipElement, showPoiTooltip } from '../map/poi-tooltip.js';
import { clearVisibleTransitRoutes, transitRouteLayer } from '../map/transit-routes.js';

// Modules moved out of this page get what they need from it here (point 71).
configureFuelPrices({ getRadius: () => mapState.app_radius, hidePoiTooltip: () => hidePoiTooltip() });
configureLodgingDetails({ hidePoiTooltip: () => hidePoiTooltip() });
configurePoiLoadingModal({ onCancel: () => cancelPoiLoad() });







const radiusEl = document.getElementById('radius');

const app_unit =
    radiusEl?.dataset?.unit?.toLowerCase() ?? 'm';

mapState.app_radius = Number(radiusEl?.value ?? 1000) * (app_unit === 'km' ? 1000 : 1);
const deviceRadiusMeters = mapState.app_radius;


const dashboardRadiusInput = document.getElementById('dashboard-search-radius');
const dashboardRadiusControl = document.getElementById('dashboard-radius-control');
const dashboardRadiusOutput = document.getElementById('dashboard-radius-value');
// Collapsed unless the user opened it; the heading shows the radius meanwhile.
bindCollapsibleCard({
    card: dashboardRadiusControl,
    toggle: document.getElementById('dashboard-radius-toggle'),
    body: document.getElementById('dashboard-radius-body'),
    storageKey: 'symbiot-radius-open',
});
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
        dashboardRadiusInput.disabled = mapState.citywideSearch;
    }
    if (dashboardRadiusOutput) dashboardRadiusOutput.value = mapState.citywideSearch ? t('Whole city') : formatSearchRadius(meters);
    const legacyRadiusInput = document.getElementById('city-location-radius');
    if (legacyRadiusInput) {
        legacyRadiusInput.value = String(meters);
        legacyRadiusInput.disabled = mapState.citywideSearch;
    }
    dashboardRadiusControl?.classList.toggle('d-none', !visible);
    // A searched city or the GPS location (its locality): both have a "whole city".
    dashboardCitywideWrap?.classList.toggle('d-none', !(mapState.fixedCityLocation || mapState.currentUserLocation));
}

window.addEventListener('symbiot:location-changing', () => {
    resetWeather();
    mapState.citywideSearch = false;
    mapState.cityBoundaryFeature = null;
    mapState.cityBoundaryRadiusMeters = null;
    dashboardCitywide && (dashboardCitywide.checked = false);
    if (dashboardRadiusInput) dashboardRadiusInput.disabled = false;
    cityBoundarySource?.clear();
    syncDashboardRadius(mapState.app_radius, false);
});


window.addEventListener('symbiot:location-set', event => {
    loadWeatherForecast(event.detail?.lat, event.detail?.lon);
    // Hide the POI categories with nothing around the new location right away, not only after a category is ticked.
    const lat = Number(event.detail?.lat);
    const lon = Number(event.detail?.lon);
    if (Number.isFinite(lat) && Number.isFinite(lon)) {
        updateSubcategoryAvailability(lat, lon).catch((error) => console.warn('POI availability check failed:', error));
    }
});

dashboardRadiusInput?.addEventListener('input', () => {
    const radiusMeters = Number(dashboardRadiusInput.value);
    if (!Number.isFinite(radiusMeters)) return;
    mapState.app_radius = radiusMeters;
    if (mapState.fixedCityLocation) mapState.cityRadiusMeters = radiusMeters;
    if (mapState.radiusFeature) {
        const center = mapState.fixedCityLocation ?? mapState.currentUserLocation;
        if (center && Number.isFinite(Number(center.lat)) && Number.isFinite(Number(center.lon))) {
            mapState.radiusFeature.setGeometry(
                circular([Number(center.lon), Number(center.lat)], radiusMeters, 64)
                    .transform('EPSG:4326', 'EPSG:3857')
            );
        }
    }
    if (dashboardRadiusOutput) dashboardRadiusOutput.value = mapState.citywideSearch ? t('Whole city') : formatSearchRadius(radiusMeters);
    const legacyRadiusInput = document.getElementById('city-location-radius');
    if (legacyRadiusInput) legacyRadiusInput.value = String(radiusMeters);
});

async function refreshLocationData(center) {
    const radiusMeters = Number(mapState.app_radius);
    // A new radius changes which POI categories have something in range.
    updateSubcategoryAvailability(Number(center.lat), Number(center.lon)).catch((error) => console.warn('POI availability check failed:', error));
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
    const center = mapState.fixedCityLocation ?? mapState.currentUserLocation;
    if (!center || !Number.isInteger(radiusMeters) || radiusMeters < 100 || radiusMeters > 35000) return;
    mapState.cityRadiusMeters = radiusMeters;
    refreshLocationData(center);
});

dashboardCitywide?.addEventListener('change', async () => {
    // The searched city, else the locality of the GPS position (found by the point alone, see CityBoundaries::pick).
    const center = mapState.fixedCityLocation ?? mapState.currentUserLocation;
    if (!center) return;
    const radiusStatus = document.getElementById('dashboard-radius-status');
    if (radiusStatus) radiusStatus.textContent = '';
    if (!dashboardCitywide.checked) {
        mapState.citywideSearch = false;
        mapState.cityBoundaryFeature = null;
        mapState.cityBoundaryRadiusMeters = null;
        cityBoundarySource.clear();
        mapState.app_radius = mapState.cityRadiusMeters;
        mapState.radiusFeature?.setGeometry(circular([center.lon, center.lat], mapState.app_radius, 64).transform('EPSG:4326', 'EPSG:3857'));
        syncDashboardRadius(mapState.app_radius, true);
        const radiusExtent = mapState.radiusFeature?.getGeometry()?.getExtent();
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
        if (mapState.fixedCityLocation) {
            if (center.placeId) params.set('id', center.placeId);
            if (center.name) params.set('name', center.name);
        }
        const payload = await fetchJson(`/api/location/city-boundary?${params}`);
        if (!payload?.feature?.geometry) throw new Error(t('Could not load the city boundary.'));

        mapState.cityBoundaryFeature = payload.feature;
        mapState.cityBoundaryRadiusMeters = 0;
        const visitCoordinates = (coordinates) => {
            if (!Array.isArray(coordinates)) return;
            if (typeof coordinates[0] === 'number' && typeof coordinates[1] === 'number') {
                mapState.cityBoundaryRadiusMeters = Math.max(mapState.cityBoundaryRadiusMeters, haversineMeters(center.lat, center.lon, coordinates[1], coordinates[0]));
                return;
            }
            coordinates.forEach(visitCoordinates);
        };
        visitCoordinates(mapState.cityBoundaryFeature.geometry.coordinates);
        mapState.app_radius = Math.max(100, Math.ceil(mapState.cityBoundaryRadiusMeters / 100) * 100);
        const boundaryMapFeature = new GeoJSON().readFeature(mapState.cityBoundaryFeature, { featureProjection: 'EPSG:3857' });
        const mapSize = map.getSize();
        // "Whole city" always applies, even when the city already fits in the current view.
        mapState.citywideSearch = true;
        cityBoundarySource.clear();
        cityBoundarySource.addFeature(boundaryMapFeature);
        mapState.radiusFeature?.setGeometry(null);
        syncDashboardRadius(mapState.app_radius, true);
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
        mapState.citywideSearch = false;
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
    poiSource.clear();

    // Invalidează cererile POI în curs.
    mapState.poiRequestId++;

    // Elimină traseele și tooltip-ul asociat hărții.
    clearVisibleTransitRoutes();
    hidePoiTooltip();
    hidePOIModal();

    // Actualizează afișarea filtrelor pe hartă.
    poiLayer.changed();
    clusterLayer.changed();
}

function getColorForType(type) {
    return assignedCategoryColor(type) || POI_CATEGORIES[type]?.color || '#666';
}

function hashColor(str) {
    return getColorForType(str);
}
const vectorSource = new VectorSource();
const cityBoundarySource = new VectorSource();

const userSource = new VectorSource();

const poiSource = new VectorSource();

const clusterSource = new Cluster({
    distance: 40,
    source: userSource,
});

const updatePoiClusterDistance = () => {
    clusterSource.setDistance((map?.getView?.().getZoom?.() ?? 0) >= 16 ? 0 : 40);
};






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
        const showIndividualPoints = (map.getView().getZoom() ?? 0) >= 16;

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

                radius: showIndividualPoints ? 6 : Math.min(10 + size, 25),

                fill: new Fill({
                    color: 'rgba(0,150,255,0.7)'
                }),

                stroke: new Stroke({
                    color: '#fff',
                    width: 2
                })
            }),

            text: !showIndividualPoints && size > 1 ? new Text({
                text: size.toString(),
                fill: new Fill({ color: '#fff' }),
            }) : undefined,
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

    // Attach only after the responsive layout gives the target a real size.
    // OpenLayers' own ResizeObserver reports an error if the target starts at 0×0.
    target: null,

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

map.getView().on('change:resolution', updatePoiClusterDistance);
updatePoiClusterDistance();

const updateMapSizeWhenVisible = () => {
    const target = document.getElementById('map');
    if (!target?.isConnected) {
        if (map.getTargetElement()) map.setTarget(null);
        return false;
    }

    const { width, height } = target.getBoundingClientRect();
    if (width <= 0 || height <= 0) {
        if (map.getTargetElement() === target) map.setTarget(null);
        return false;
    }

    if (map.getTargetElement() !== target) {
        map.setTarget(target);
    } else {
        map.updateSize();
    }
    return true;
};

const scheduleMapSizeUpdate = () => {
    requestAnimationFrame(() => requestAnimationFrame(updateMapSizeWhenVisible));
};

const mapCardResizeObserver = new ResizeObserver(updateMapSizeWhenVisible);
const mapCardElement = document.getElementById('map_card');
if (mapCardElement) mapCardResizeObserver.observe(mapCardElement);
const mapTargetElement = document.getElementById('map');
if (mapTargetElement) mapCardResizeObserver.observe(mapTargetElement);

window.addEventListener('symbiot:dashboard-panel-toggled', () => {
    scheduleMapSizeUpdate();
});

const transitStops = createTransitLayer(map);
configurePoiLoading({ map, poiSource, poiLayer, transitStops });

window.addEventListener('symbiot:focus-map-point', event => {
    const { lat, lon } = event.detail ?? {};
    if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lon))) return;
    map.getView().animate({
        center: fromLonLat([Number(lon), Number(lat)]),
        zoom: Math.max(map.getView().getZoom() || 13, 15),
        duration: 350,
    });
});


watchTileProgress(baseLayer.getSource());
attachPoiTooltip(map);


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

    if (mapState.userFeature) return;

    const center = mapState.currentUserLocation
        ? fromLonLat([mapState.currentUserLocation.lon, mapState.currentUserLocation.lat])
        : fromLonLat([25.6, 45.65]);

    mapState.userFeature = new Feature({
        geometry: new Point(center)
    });

    mapState.userFeature.setStyle(userStyle);

    mapState.trackFeature = new Feature({
        geometry: new LineString([])
    });

    mapState.trackFeature.setStyle(trackStyle);

    mapState.radiusFeature = new Feature({
        geometry: new Circle(
            center,
            Number(mapState.app_radius)
        )
    });

    mapState.radiusFeature.setGeometry(
        circular(
            fromLonLat([mapState.currentUserLocation.lon, mapState.currentUserLocation.lat], 'EPSG:4326'),
            Number(mapState.app_radius),
            64
        ).transform('EPSG:4326', 'EPSG:3857')
    );

    mapState.radiusFeature.setStyle(radiusStyle);

    vectorSource.addFeature(mapState.radiusFeature);
    vectorSource.addFeature(mapState.trackFeature);
    vectorSource.addFeature(mapState.userFeature);
}



























let turismBtn = document.getElementById('turismLocations')
turismBtn?.addEventListener('click', () => {
    loadTourismPOI();
});





bindShareLocation(() => mapState.currentUserLocation);

// The location modules get the map, its sources and the page functions they need.
const locationPage = {
    map, poiSource, cityBoundarySource, transitStops, userStyle, userArrowStyle, csrfToken, dashboardCitywide,
    deviceRadiusMeters, HEADING_MIN_MOVE_METERS, syncDashboardRadius, showBestGasPriceButton, resetPOIs,
    scheduleMapSizeUpdate, initLocalFeatures, loadNearby, selectedPoiFilterTokens,
};
bindCitySearch(locationPage);
bindDeviceLocation(locationPage);








addUserEvents();

