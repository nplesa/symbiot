import 'ol/ol.css';

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

import Style from 'ol/style/Style.js';
import Icon from 'ol/style/Icon.js';
import Fill from 'ol/style/Fill.js';
import Stroke from 'ol/style/Stroke.js';
import CircleStyle from 'ol/style/Circle.js';
import Text from 'ol/style/Text.js';

import Circle from 'ol/geom/Circle.js';

import Polygon from 'ol/geom/Polygon.js';
import { circular } from 'ol/geom/Polygon.js';
import MultiLineString from 'ol/geom/MultiLineString.js';
import {
    assignTransitRouteColors,
    bindPoiMapClick,
    groupTransitRoutes,
    getInfoferStationUrl,
    getMetroArrivalWindow,
    getTransitRouteColor,
    poiFeatureAtPixel,
    readPoiJsonResponse,
    splitTransitRouteDirections,
} from './poi-map-interactions.js';





let tracking = false;
let watchId = null;

let userFeature = null;
let trackFeature = null;

let lastCoords = null;
let lastPosition = null;

let heading = 0;
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

let radiusFeature = null;


const state = {
    radiusFeature: null,
    userFeature: null,
    trackFeature: null
};

const users = new globalThis.Map();

const activePoiFilters = new Set();

let poiRequestId = 0;
let poiLoadingTimer = null;
let poiLoadingStartedAt = null;
let poiLoadingModalEventsBound = false;
let poiLoadingModalShown = false;
let poiLoadingModalHidePending = false;
let poiLoadingModalHidePromise = null;

let allPois = null;

let allPoisRaw = [];

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

function getColorForType(type) {
    return POI_CATEGORIES[type]?.color || '#666';
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

function filterByRadius(data, lat, lon, radiusMeters) {

    return data.filter(item => {

        const itemLat = item.coordinates?.lat;
        const itemLon = item.coordinates?.lon;

        if (typeof itemLat !== 'number' || typeof itemLon !== 'number') {
            return false;
        }

        const d = getDistanceMeters(lat, lon, itemLat, itemLon);

        return d <= radiusMeters;
    });
}





const vectorSource = new VectorSource();

const userSource = new VectorSource();

const poiSource = new VectorSource();
const transitRouteSource = new VectorSource();
const visibleTransitRoutes = new Set();
const activeTransitRoutes = new globalThis.Map();
const transitRouteColors = new globalThis.Map();
const manuallySelectedTransitRouteColors = new Set();

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
    source: new XYZ({ url: '/map/tiles/{z}/{x}/{y}' })
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






const map = new Map({

    target: 'map',

    layers: [
        baseLayer,
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
poiTooltipClose.setAttribute('aria-label', 'Închide');
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
    fuel: 'Benzinărie',
    parking: 'Parcare',
    restaurant: 'Restaurant',
    cafe: 'Cafenea',
    lodging: 'Cazare',
    supermarket: 'Supermarket',
    airport: 'Aeroport',
    bus: 'Stație de autobuz',
    subway: 'Metrou',
    train: 'Gară',
    taxi: 'Taxi',
    hospital: 'Spital',
    pharmacy: 'Farmacie',
    fire: 'Pompieri',
    tourism: 'Obiectiv turistic',
    charging_station: 'Stație de încărcare',
    police: 'Poliție',
    speed_camera: 'Cameră de viteză',
    speed_limit: 'Limită de viteză',
    traffic_sign: 'Indicator rutier',
    vignette_control: 'Control rovinietă',
    control: 'Punct de control',
    locality: 'Localitate',
    transport: 'Punct de interes',
};

function showPoiTooltip(feature, coordinate) {
    const transitTypes = new Set(['bus', 'subway', 'train', 'airport']);
    const featureType = feature.get('type');
    const featureRoutes = feature.get('routes');
    const routes = Array.isArray(featureRoutes) ? featureRoutes : [];
    const groupedRoutes = groupTransitRoutes(routes);
    const routeGroups = featureType === 'bus'
        ? splitTransitRouteDirections(groupedRoutes)
        : groupedRoutes;

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
        const stationName = feature.get('name') || 'Punct de transport';
        const typeLabel = poiTypeLabels[featureType] || 'Transport';

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
        emptyMessage.textContent = feature.get('routes_available') === false
            ? 'Liniile nu au putut fi încărcate din OpenStreetMap. Încearcă din nou mai târziu.'
            : 'Nu există linii asociate acestei stații în OpenStreetMap.';
        sourceInfo.textContent = 'Linii și trasee din OpenStreetMap; statutul în teren poate fi diferit.';
        trainLiveInfo.hidden = featureType !== 'train';
        metroEstimate.hidden = featureType !== 'subway' || routeGroups.length === 0;
        metroEstimate.textContent = 'Selectează exact o magistrală pentru estimarea următorului tren.';
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
            checkbox.setAttribute('aria-label', `Afișează traseul ${group.label}`);
            checkbox.title = `Afișează traseul ${group.label}`;
            if (!hasRelation) {
                checkbox.title = 'Relația traseului nu are un identificator OSM valid.';
            }

            const colorPicker = document.createElement('input');
            colorPicker.type = 'color';
            colorPicker.className = 'poi-route-color';
            colorPicker.dataset.routeKey = group.key;
            colorPicker.value = getTransitRouteColor(group.key, transitRouteColors);
            colorPicker.setAttribute('aria-label', `Culoare traseu ${group.label}`);

            const directions = [...new Set(group.routes.map(route => route.direction).filter(Boolean))];
            const latestUpdate = group.routes
                .map(route => route.updated_at)
                .filter(Boolean)
                .sort()
                .at(-1);
            const inactive = group.routes.some(route => route.status === 'possibly_inactive');
            const status = inactive
                ? 'Marcată ca posibil inactivă în OSM'
                : latestUpdate
                    ? `OSM · ${new Intl.DateTimeFormat('ro-RO', { dateStyle: 'medium' }).format(new Date(latestUpdate))}`
                    : 'Listată în OSM; statutul real nu este confirmat';
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
            checkedAt.textContent = `Link pregătit la ${new Intl.DateTimeFormat('ro-RO', {
                dateStyle: 'short',
                timeStyle: 'short',
                timeZone: 'Europe/Bucharest',
            }).format(new Date())}. Informațiile operative sunt afișate pe site-ul oficial Infofer.`;
        }

        bootstrap.Modal.getOrCreateInstance(modalElement).show();
        return;
    }

    const address = feature.get('address') ?? {};
    const addressText = address.formatted
        || [address.city, address.county, address.country].filter(Boolean).join(', ');

    poiTooltipName.textContent = feature.get('name') || 'Necunoscut';
    poiTooltipType.textContent = poiTypeLabels[feature.get('type')] || 'Punct de interes';
    const featureTrainServices = feature.get('train_services');
    const trainServices = Array.isArray(featureTrainServices) ? featureTrainServices : [];
    poiTooltipRoutes.replaceChildren();
    activeTransitRoutes.clear();
    if (routeGroups.length > 0) {
        const heading = document.createElement('div');
        heading.textContent = 'Linii / rute deservite:';
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
            routeCheckbox.setAttribute('aria-label', `Afișează traseul ${group.label}`);
            if (routeCheckbox.disabled) {
                routeCheckbox.title = 'Relația traseului nu are un identificator OSM valid.';
            }

            const colorPicker = document.createElement('input');
            colorPicker.type = 'color';
            colorPicker.className = 'poi-route-color';
            colorPicker.dataset.routeKey = group.key;
            colorPicker.value = color;
            colorPicker.setAttribute('aria-label', `Culoare traseu ${group.label}`);

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
                ? 'Marcată ca posibil inactivă în OSM'
                : latestUpdate
                    ? `OSM · ${new Intl.DateTimeFormat('ro-RO', { dateStyle: 'medium' }).format(new Date(latestUpdate))}`
                    : 'Listată în OSM; statutul real nu este confirmat';
            routeInfo.textContent = [...directions, statusText].join(' · ');
            routeInfo.title = 'Date OpenStreetMap; acestea nu confirmă orarul actual al operatorului.';
            const routeText = document.createElement('span');
            routeText.className = 'poi-route-text';
            routeText.append(routeName, routeInfo);
            routeLabel.append(routeCheckbox, colorPicker, routeText);
            poiTooltipRoutes.appendChild(routeLabel);
        });
    }

    if (trainServices.length > 0) {
        const heading = document.createElement('div');
        heading.textContent = 'Trenuri în mersul anual publicat:';
        poiTooltipRoutes.appendChild(heading);

        trainServices.forEach(service => {
            const serviceInfo = document.createElement('div');
            serviceInfo.className = 'poi-route-info';
            const details = [
                service.departure ? `plecare ${service.departure}` : null,
                service.direction ? `spre ${service.direction}` : null,
                service.operator || null,
                service.service_days || null,
            ].filter(Boolean);
            serviceInfo.textContent = [
                `${service.category || 'Tren'} ${service.number}`,
                ...details,
            ].join(' · ');
            poiTooltipRoutes.appendChild(serviceInfo);
        });

        const sourceInfo = document.createElement('div');
        sourceInfo.className = 'poi-route-info';
        const validUntil = feature.get('train_timetable_valid_until');
        const availabilityNote = feature.get('train_services_available') === false
            ? 'Unele surse de orar nu au putut fi încărcate.'
            : '';
        sourceInfo.textContent = [
            `Sursa: ${feature.get('train_timetable_source') || 'data.gov.ro'} · orar anual, nu date în timp real; pot exista modificări/excepții.`,
            validUntil ? `Valabil până la ${validUntil}.` : '',
            availabilityNote,
        ].filter(Boolean).join(' ');
        poiTooltipRoutes.appendChild(sourceInfo);
    } else if (transitTypes.has(feature.get('type')) && routeGroups.length === 0) {
        const emptyRoutes = document.createElement('div');
        emptyRoutes.className = 'poi-route-info';
        if (feature.get('type') === 'train' && feature.get('train_services_available') === false) {
            emptyRoutes.textContent = 'Orarele feroviare nu au putut fi încărcate din data.gov.ro.';
        } else {
            emptyRoutes.textContent = feature.get('routes_available') === false
                ? 'Liniile nu au putut fi încărcate din OpenStreetMap.'
                : 'Nu există linii asociate stației în OpenStreetMap.';
        }
        poiTooltipRoutes.appendChild(emptyRoutes);
    }
    if (feature.get('type') === 'subway' && routeGroups.length > 0) {
        const metroEstimate = document.createElement('div');
        metroEstimate.className = 'poi-route-info metro-arrival-estimate';
        metroEstimate.setAttribute('aria-live', 'polite');
        metroEstimate.textContent = 'Selectează exact o magistrală pentru estimarea următorului tren.';
        poiTooltipRoutes.appendChild(metroEstimate);
    }
    poiTooltipRoutes.hidden = routeGroups.length === 0 && !transitTypes.has(feature.get('type'));
    poiTooltipAddress.textContent = addressText || '';
    poiTooltipAddress.hidden = !addressText;
    poiTooltipElement.hidden = false;
    poiTooltip.setPosition(coordinate);
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

document.getElementById('transitLinesList')?.addEventListener('change', async event => {
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

    updateMetroArrivalEstimate();

    const routeKey = checkbox.dataset.routeKey;
    const routeGroup = activeTransitRoutes.get(routeKey);
    if (!routeGroup) return;

    const featureId = `transit-route:${routeKey}`;
    let routeFeature = transitRouteSource.getFeatureById(featureId);

    if (checkbox.checked) {
        if (!routeFeature) {
            checkbox.disabled = true;
            try {
                const routeGeometries = await Promise.all(
                    routeGroup.routes.map(async route => {
                        const match = /^relation\/(\d+)$/.exec(route.id);
                        if (!match) return [];

                        const response = await fetch(`/api/transit-route/${match[1]}`);
                        const data = await response.json();
                        if (!response.ok) {
                            throw new Error(data.error || `HTTP ${response.status}`);
                        }

                        return Array.isArray(data.segments)
                            ? data.segments
                                .filter(segment => Array.isArray(segment) && segment.length >= 2)
                                .map(segment => segment
                                    .filter(coordinate => Array.isArray(coordinate) && coordinate.length >= 2)
                                    .map(([lon, lat]) => fromLonLat([lon, lat]))
                                )
                            : [];
                    })
                );
                const lineCoordinates = routeGeometries.flat()
                    .filter(coordinates => coordinates.length >= 2);

                if (lineCoordinates.length === 0) {
                    throw new Error('OpenStreetMap nu a returnat geometria traseului.');
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
                checkbox.checked = false;
                console.error('Transit route geometry could not be loaded:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Traseul nu a putut fi încărcat',
                    text: error.message,
                });
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

function updateMetroArrivalEstimate() {
    const estimateElement = document.getElementById('metroArrivalEstimate');
    if (!estimateElement) return;

    const selectedLines = document.querySelectorAll('#transitLinesList .poi-route-checkbox:checked');
    if (selectedLines.length !== 1) {
        estimateElement.textContent = 'Selectează exact o magistrală pentru estimarea următorului tren.';
        return;
    }

    const { from, until } = getMetroArrivalWindow();
    estimateElement.textContent = `Următorul tren: estimativ între ${from} și ${until} (interval generic ~5 min; nu este informație live și nu confirmă circulația).`;
}

function hidePoiTooltip() {
    poiTooltipElement.hidden = true;
    poiTooltip.setPosition(undefined);
}

function clearVisibleTransitRoutes() {
    visibleTransitRoutes.clear();
    activeTransitRoutes.clear();
    transitRouteSource.clear();
}

map.on('pointermove', event => {
    const feature = poiFeatureAtPixel(map, poiSource, poiLayer, event.pixel);
    map.getTargetElement().style.cursor = feature ? 'pointer' : '';
});

bindPoiMapClick({
    target: document,
    map,
    poiSource,
    poiLayer,
    tooltipElement: poiTooltipElement,
    showTooltip: showPoiTooltip,
    hideTooltip: hidePoiTooltip,
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

        if (!item?.coordinates) continue;

        const lat = item.coordinates.lat;
        const lon = item.coordinates.lon;

        if (typeof lat !== 'number' || typeof lon !== 'number') continue;

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

    const res = await fetch(url);
    const data = await res.json();

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
            title: 'GPS inactive',
            text: 'Activate location first',
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
        `📍 My current location:\n${googleMapsUrl}`
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
            title: 'GPS inactive',
            text: 'Activate location first',
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

                title: 'My Location',

                text: '📍 My current location',

                url: googleMapsUrl
            });

            return;

        } catch (error) {

            console.warn(error);
        }
    }


    const text = encodeURIComponent(
        `📍 My current location:\n${googleMapsUrl}`
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
    const response = await fetch('/location/toggle', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ location: false }),
    });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    document.getElementById('map_card')?.classList.add('d-none');
    document.getElementById('mobility_card')?.classList.add('d-none');
});

document.getElementById('city-location-form')?.addEventListener('submit', async event => {
    event.preventDefault();

    const input = document.getElementById('city-location-input');
    const radiusInput = document.getElementById('city-location-radius');
    const button = document.getElementById('city-location-submit');
    const status = document.getElementById('city-location-status');
    const city = input.value.trim();
    const radiusMeters = Number(radiusInput.value);
    if (city.length < 2) {
        status.textContent = 'Introdu numele unui oraș.';
        status.className = 'col-12 small text-danger';
        return;
    }
    if (!Number.isInteger(radiusMeters) || radiusMeters < 100 || radiusMeters > 35000) {
        status.textContent = 'Raza trebuie să fie între 100 și 35.000 de metri.';
        status.className = 'col-12 small text-danger';
        radiusInput.focus();
        return;
    }

    button.disabled = true;
    status.textContent = 'Caut orașul...';
    status.className = 'col-12 small text-muted';

    try {
        const params = new URLSearchParams({ city });
        const response = await fetch(`/api/location/city?${params.toString()}`);
        const result = await response.json();
        if (!response.ok) {
            throw new Error(result.error || `HTTP ${response.status}`);
        }

        createInputLocationElement(true);
        const currentLocation = document.getElementById('current_location');
        currentLocation.dataset.lat = result.lat;
        currentLocation.dataset.lon = result.lon;
        cityRadiusMeters = radiusMeters;
        app_radius = cityRadiusMeters;
        fixedCityLocation = { lat: result.lat, lon: result.lon };
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
        map.getView().animate({ center, zoom: 13, duration: 500 });
        document.getElementById('map_card')?.classList.remove('d-none');
        document.getElementById('mobility_card')?.classList.remove('d-none');

        status.textContent = `Locația a fost fixată în centrul orașului ${result.name}, cu raza de ${cityRadiusMeters.toLocaleString('ro-RO')} m.`;
        status.className = 'col-12 small text-success';
        setTimeout(() => map.updateSize(), 100);

        if (selectedPoiFilterTokens(document.getElementById('mobility_card')).length > 0) {
            loadNearby({ lat: result.lat, lon: result.lon });
        }
    } catch (error) {
        status.textContent = error.message;
        status.className = 'col-12 small text-danger';
    } finally {
        button.disabled = false;
    }
});

document.getElementById('city-location-radius')?.addEventListener('change', event => {
    const radiusMeters = Number(event.target.value);
    const status = document.getElementById('city-location-status');
    if (!Number.isInteger(radiusMeters) || radiusMeters < 100 || radiusMeters > 35000) {
        status.textContent = 'Raza trebuie să fie între 100 și 35.000 de metri.';
        status.className = 'col-12 small text-danger';
        return;
    }

    cityRadiusMeters = radiusMeters;
    app_radius = cityRadiusMeters;
    if (fixedCityLocation && radiusFeature) {
        radiusFeature.setGeometry(
            circular(
                [fixedCityLocation.lon, fixedCityLocation.lat],
                cityRadiusMeters,
                64
            ).transform('EPSG:4326', 'EPSG:3857')
        );
        status.textContent = `Raza de căutare: ${cityRadiusMeters.toLocaleString('ro-RO')} m.`;
        status.className = 'col-12 small text-success';
        if (selectedPoiFilterTokens(document.getElementById('mobility_card')).length > 0) {
            loadNearby(fixedCityLocation);
        }
    }
});






document
    .getElementById('toggleLocation')
    ?.addEventListener('change', async function () {

        if (!this.checked) return;

        tracking = true;
        fixedCityLocation = null;
        app_radius = deviceRadiusMeters;
        document.getElementById('city-location-mode').checked = false;
        document.getElementById('city-location-form')?.classList.add('d-none');

        let mapCard =
            document.getElementById('map_card');

        let mobCard =
            document.getElementById('mobility_card');

        let shareBtn = document.getElementById('shareLocation');    
        let turismBtn = document.getElementById('turismLocations');
        await fetch(
            '/location/toggle',
            {
                method: 'POST',

                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':  csrfToken
                },

                body: JSON.stringify({
                    location: this.checked
                })
            }
        );


        document.getElementById("i_location").classList.add("rotate3d-y");

        mobCard.querySelector('.row')
            .classList.remove('mt-3');

        mobCard.classList.remove('d-none');
        mapCard.classList.remove('d-none');

        setTimeout(() => {
            map.updateSize();
        }, 100);

        createInputLocationElement(true);

        if (!navigator.geolocation) {
            Swal.fire({
                icon: 'error',
                title: 'Geolocation not supported',
                text: 'Your browser does not support geolocation.'
            });

            tracking = false;
            this.checked = false;
            return;
        }

        watchId =
            navigator.geolocation.watchPosition(

                (position) => {

                    let lon =
                        position.coords.longitude;

                    let lat =
                        position.coords.latitude;

                    if (lastCoords) {

                        lon =
                            lastCoords.lon * 0.7 +
                            lon * 0.3;

                        lat =
                            lastCoords.lat * 0.7 +
                            lat * 0.3;
                    }

                    lastCoords = {
                        lon,
                        lat
                    };

                    currentUserLocation = {
                        lon,
                        lat
                    };

                    document
                        .getElementById('shareLocation')
                        ?.classList.remove('d-none');
                    document
                        .getElementById('turismLocations')
                        ?.classList.remove('d-none');

                    const coords =
                        fromLonLat([lon, lat]);
                    const isFirstLocationFix = !hasCentered;

                    if (lastPosition) {

                        const dx =
                            lon - lastPosition.lon;

                        const dy =
                            lat - lastPosition.lat;

                        heading =
                            Math.atan2(dy, dx);
                    }

                    lastPosition = {
                        lon,
                        lat
                    };

                    requestAnimationFrame(() => {

                        userFeature
                            .getGeometry()
                            .setCoordinates(coords);                        

                        if (radiusFeature) {

                            radiusFeature.setGeometry(
                                circular(
                                    [lon, lat],
                                    Number(app_radius),
                                    64
                                ).transform('EPSG:4326', 'EPSG:3857')
                            );
                        }                        

                        userStyle
                            .getImage()
                            .setRotation(heading);

                        userFeature.setStyle(
                            userStyle
                        );

                        const geometry =
                            trackFeature.getGeometry();

                        geometry.appendCoordinate(
                            coords
                        );

                        const coordinates =
                            geometry.getCoordinates();

                        if (
                            coordinates.length > 1000
                        ) {

                            coordinates.shift();

                            geometry.setCoordinates(
                                coordinates
                            );
                        }
                    });

                    if (!hasCentered) {

                        map.getView().animate({

                            center: coords,

                            zoom: 17,

                            duration: 500
                        });

                        hasCentered = true;
                    }


                    let shareBtn = document.getElementById('shareLocation');
                    shareBtn.classList.remove('d-none');

                    let currentLocation = document.getElementById('current_location');
                    currentLocation.dataset.lat = lat;    
                    currentLocation.dataset.lon = lon;

                    initLocalFeatures();
                    const mobilityCard = document.getElementById('mobility_card');
                    if (isFirstLocationFix
                        && mobilityCard
                        && selectedPoiFilterTokens(mobilityCard).length > 0) {
                        loadNearby({ lat, lon });
                    }

                    const now = Date.now();

                    if (
                        now - lastSent > 5000
                    ) {

                        lastSent = now;

                        fetch(
                            '/location/update',
                            {
                                method: 'POST',

                                headers: {
                                    'Content-Type':
                                        'application/json',

                                    'X-CSRF-TOKEN':
                                        csrfToken
                                },

                                body: JSON.stringify({
                                    lat,
                                    lon,
                                    active: true,
                                    heading
                                })
                            }
                        );
                    }
                },

                console.error,

                {
                    enableHighAccuracy: true,
                    timeout: 10000,
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

    heading = 0;
    hasCentered = false;

    map.getView().animate({

        center: fromLonLat([25.6, 45.65]),

        zoom: 12,

        duration: 500
    });

    fetch('/location/update', {

        method: 'POST',

        headers: {
            'Content-Type':
                'application/json',

            'X-CSRF-TOKEN':
                csrfToken
        },

        body: JSON.stringify({
            active: false
        })
    });
}





async function loadNearby(locationOverride = null) {

    const requestId = ++poiRequestId;
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

        allPoisRaw = [];
        allPois = [];
        activePoiFilters.clear();
        addUserEvents();

        const poiContainer = document.getElementById('mobility_card');
        const selectedTypes = poiContainer ? selectedPoiFilterTokens(poiContainer) : [];
        if (selectedTypes.length === 0) {
            poiRequestId++;
            poiSource.clear();
            clearVisibleTransitRoutes();
            hidePoiTooltip();
            hidePOIModal();

            return;
        }

        showPOIModal();

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
        const categoryForToken = token => {
            if (Object.hasOwn(transitEndpoints, token) || Object.hasOwn(categoryEndpoints, token)) return token;
            return token.match(/^subcategory:([^:]+):/)?.[1] ?? null;
        };
        const selectedCategories = [...new Set(selectedTypes.map(categoryForToken).filter(Boolean))];
        const categoryLabels = new Map(
            Array.from(poiContainer.querySelectorAll('.poi-category-group'))
                .map(group => [
                    group.dataset.type,
                    group.querySelector('.location-category')?.nextElementSibling?.textContent?.trim()
                        || group.dataset.type,
                ])
        );
        const requests = selectedCategories.map(category => {
            const params = new URLSearchParams({
                lat: String(lat),
                lon: String(lon),
                radius: String(app_radius),
            });
            const categoryFilters = selectedTypes.filter(token => categoryForToken(token) === category);
            if (!Object.hasOwn(transitEndpoints, category)) {
                params.set('types', categoryFilters.join(','));
            }
            if (category === 'train') {
                params.set('include_routes', 'false');
            }

            const endpoint = transitEndpoints[category] ?? categoryEndpoints[category];
            return {
                category,
                label: categoryLabels.get(category) || category,
                url: `${endpoint}?${params.toString()}`,
            };
        });

        const responses = await Promise.allSettled(requests.map(async ({ url }) => {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                },
            });

            return readPoiJsonResponse(response);
        }));
        if (requestId !== poiRequestId) return;
        const successfulResponses = responses
            .filter(result => result.status === 'fulfilled');
        const failedRequests = responses
            .flatMap((result, index) => result.status === 'rejected'
                ? [`${requests[index].label} (${result.reason?.message || 'cerere eșuată'})`]
                : []);
        const data = successfulResponses.flatMap(result => result.value);

        if (requestId !== poiRequestId) return;
        if (successfulResponses.length === 0 && failedRequests.length > 0) {
            throw new Error(failedRequests.join('; '));
        }

        showResultData(data, lat, lon);
        if (failedRequests.length > 0) {
            notification = {
                title: 'Rezultate parțiale',
                text: `Nu s-au putut încărca toate filtrele: ${failedRequests.join('; ')}`,
                icon: 'warning'
            };
        }

    } catch (error) {
        if (requestId !== poiRequestId) return;
        
        console.error(error.message);
        notification = {
            title: 'Eroare la încărcarea categoriilor',
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

function syncPoiFilterSelection(container) {
    activePoiFilters.clear();

    container.querySelectorAll('.poi-category-group').forEach(group => {
        const parent = group.querySelector('.location-category');
        const children = Array.from(group.querySelectorAll('.location-subcategory'));
        const selectedChildren = children.filter(checkbox => checkbox.checked);

        if (parent) {
            parent.checked = children.length > 0 && selectedChildren.length === children.length;
            parent.indeterminate = selectedChildren.length > 0 && selectedChildren.length < children.length;
        }

        if (selectedChildren.length > 0 || (children.length === 0 && parent?.checked)) {
            activePoiFilters.add(group.dataset.type);
        }
    });
}

function selectedPoiFilterTokens(container) {
    const selected = [];

    container.querySelectorAll('.poi-category-group').forEach(group => {
        const type = group.dataset.type;
        const children = Array.from(group.querySelectorAll('.location-subcategory'));
        const checkedChildren = children.filter(checkbox => checkbox.checked);

        if (children.length > 0 && checkedChildren.length === children.length) {
            selected.push(type);
            return;
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
        const children = group?.querySelectorAll('.location-subcategory') ?? [];

        if (checkbox.matches('.location-category')) {
            children.forEach(child => {
                child.checked = checkbox.checked;
            });
        }

        syncPoiFilterSelection(container);

        if (selectedPoiFilterTokens(container).length === 0) {
            poiRequestId++;
            poiSource.clear();
            clearVisibleTransitRoutes();
            hidePoiTooltip();
            hidePOIModal();
            return;
        }

        const currentLocation = document.getElementById('current_location');
        if (currentLocation && Number.isFinite(parseFloat(currentLocation.dataset.lat))
            && Number.isFinite(parseFloat(currentLocation.dataset.lon))) {
            clearVisibleTransitRoutes();
            loadNearby();
        }
    });
}

addUserEvents();

function enrichPOIWithDistance(poiList, userLat, userLon) {
    return poiList.map((item) => {
        const lat = item.coordinates?.lat ?? item.lat;
        const lon = item.coordinates?.lon ?? item.lon;

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

function showPOIModal() {
    const modalEl = document.getElementById('poiLoadingModal');
    if (!modalEl) return;

    if (!poiLoadingModalEventsBound) {
        modalEl.addEventListener('shown.bs.modal', () => {
            poiLoadingModalShown = true;
            startPoiElapsedTimer();
            if (poiLoadingModalHidePending) {
                poiLoadingModalHidePending = false;
                hidePOIModal();
            }
        });
        modalEl.addEventListener('hidden.bs.modal', () => {
            poiLoadingModalShown = false;
            poiLoadingModalHidePending = false;
            stopPoiElapsedTimer();
            poiLoadingModalHidePromise = null;
        });
        poiLoadingModalEventsBound = true;
    }

    poiLoadingModalHidePending = false;
    startPoiElapsedTimer();

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
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
        elapsedElement.textContent = `Timp scurs: ${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
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
        if (!modalEl.classList.contains('show') && modalEl.style.display === 'none') {
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
