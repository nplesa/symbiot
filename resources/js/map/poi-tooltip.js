/**
 * The tooltip of a POI picked on the map: name, type, address, and for transit stops the lines that
 * serve them (GTFS, OSM), the next bus arrival, the metro estimate and the trains of a station.
 * Fuel stations and lodging open their own modals.
 */
import Overlay from 'ol/Overlay.js';
import Point from 'ol/geom/Point.js';
import { haversineMeters } from '../geo.js';
import { fetchJson } from '../http.js';
import { intlLocale, t } from '../i18n.js';
import { showFuelPrices } from './fuel-prices.js';
import { showLodgingDetails } from './lodging-details.js';
import {
    assignTransitRouteColors,
    getInfoferStationUrl,
    getMetroArrivalWindow,
    getTransitRouteColor,
    groupTransitRoutes,
    splitTransitRouteDirections,
} from './poi-map-interactions.js';
import {
    activeTransitRoutes,
    manuallySelectedTransitRouteColors,
    mapState,
    transitRouteColors,
    visibleTransitRoutes,
} from './state.js';
import { enableTransitModalDragging } from './transit-modal-drag.js';

/** The OpenLayers map the tooltip belongs to (attachPoiTooltip). */
let map = null;

export const poiTooltipElement = document.createElement('div');
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
        const nearbyResponse = await fetch(`/api/transit/stops?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}&radius=1000&type=bus`, {
            headers: { Accept: 'application/json' },
        });
        if (nearbyResponse.ok) {
            const nearbyPayload = await nearbyResponse.json();
            const candidates = (Array.isArray(nearbyPayload?.stops) ? nearbyPayload.stops : [])
                .map((stop) => ({
                    stop,
                    distance: haversineMeters(lat, lon, Number(stop.lat), Number(stop.lon)),
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
                            tickets: routesPayload.tickets,
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
                distance: haversineMeters(lat, lon, Number(item.coordinates?.lat), Number(item.coordinates?.lon)),
            }))
            .filter((candidate) => Number.isFinite(candidate.distance) && candidate.distance <= 100)
            .sort((a, b) => a.distance - b.distance)[0]?.item;
    }

    return {
        routes: Array.isArray(stop?.routes) ? stop.routes : [],
        routesAvailable: stop?.routes_available !== false,
    };
}

export function showPoiTooltip(feature, coordinate) {
    const transitTypes = new Set(['bus', 'subway', 'train', 'airport']);
    const featureType = feature.get('type');
    if (featureType !== 'subway') mapState.subwayStopRoutesRun++;
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
        const lookupRun = ++mapState.busStopLookupRun;
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
                    if (lookupRun !== mapState.busStopLookupRun) return;
                    if (result.source === 'gtfs') {
                        window.dispatchEvent(new CustomEvent('symbiot:transit-stop-routes', {
                            detail: {
                                feed: result.feed,
                                stopId: result.stopId,
                                stopName: result.stopName,
                                routes: result.routes,
                                tickets: result.tickets,
                            },
                        }));
                        return;
                    }
                    showStopRoutes(result.routes, result.routesAvailable);
                })
                .catch((error) => {
                    if (lookupRun !== mapState.busStopLookupRun) return;
                    console.warn('Bus stop route lookup failed:', error);
                    showStopRoutes([], false);
                });
        }
        return;
    }

    if (featureType === 'subway' && !feature.get('subwayRoutesLoaded')) {
        hidePoiTooltip();
        const lookupRun = ++mapState.subwayStopRoutesRun;
        const stopName = feature.get('name') || poiTypeLabels.subway;
        window.dispatchEvent(new CustomEvent('symbiot:osm-stop-routes', {
            detail: { stopName, routes: [], feature, routesAvailable: true, loading: true },
        }));
        const coordinates = feature.get('coordinates') || {};
        const params = new URLSearchParams({ lat: String(coordinates.lat), lon: String(coordinates.lon) });
        fetchJson(`/api/transport/subway/stop-routes?${params}`, { signal: AbortSignal.timeout(35_000) })
            .then((payload) => {
                if (lookupRun !== mapState.subwayStopRoutesRun) return;
                feature.set('routes', Array.isArray(payload.routes) ? payload.routes : []);
                feature.set('routes_available', payload.available !== false);
                feature.set('subwayRoutesLoaded', true);
                showPoiTooltip(feature, coordinate);
            })
            .catch((error) => {
                if (lookupRun !== mapState.subwayStopRoutesRun) return;
                console.warn('Subway station routes could not be loaded:', error);
                feature.set('routes', []);
                feature.set('routes_available', false);
                feature.set('subwayRoutesLoaded', true);
                showPoiTooltip(feature, coordinate);
            });
        return;
    }

    if (featureType === 'train' || featureType === 'subway') {
        mapState.activeMetroStationFeature = featureType === 'subway' ? feature : null;
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
                // The station board (live departures/arrivals) is loaded on demand by position.
                coordinates: feature.get('coordinates') || null,
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

export async function loadBusArrival(feature, group, statusElement) {
    const coordinates = feature.get('coordinates') || {};
    const mode = feature.get('type') === 'subway' ? 'subway' : 'bus';
    const routeRef = group.label.replace(/^(Autobuz|Troleibuz|Metrou)\s+/i, '').trim();
    statusElement.textContent = t('Searching for the next departure…');
    statusElement.dataset.routeKey = group.key;
    statusElement.hidden = false;

    try {
        const params = new URLSearchParams({
            lat: String(coordinates.lat),
            lon: String(coordinates.lon),
            route_ref: routeRef,
        });
        if (mode === 'subway') params.set('mode', mode);
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
        const modeLabel = mode === 'subway' ? t('metro train') : t('bus');
        statusElement.textContent = departure.headsign
            ? t('Next :mode on :route: :time (:countdown) towards :headsign · GTFS timetable', { mode: modeLabel, route: routeRef, time: departure.time, countdown, headsign: departure.headsign })
            : t('Next :mode on :route: :time (:countdown) · GTFS timetable', { mode: modeLabel, route: routeRef, time: departure.time, countdown });
    } catch {
        if (statusElement.dataset.routeKey === group.key) {
            statusElement.textContent = t('The timetable could not be loaded.');
        }
    }
}

export async function updateMetroArrivalEstimate() {
    const estimateElement = document.getElementById('metroArrivalEstimate');
    if (!estimateElement) return;

    const selectedLines = document.querySelectorAll('#transitLinesList .poi-route-checkbox:checked');
    if (selectedLines.length !== 1) {
        mapState.metroScheduleRun++;
        estimateElement.textContent = t('Select exactly one metro line to estimate the next train.');
        return;
    }

    const checkbox = selectedLines[0];
    const group = activeTransitRoutes.get(checkbox.dataset.routeKey);
    const coordinates = mapState.activeMetroStationFeature?.get('coordinates') || {};
    const routeRef = group?.label?.replace(/^Metrou\s+/i, '').trim();
    if (routeRef && Number.isFinite(Number(coordinates.lat)) && Number.isFinite(Number(coordinates.lon))) {
        const run = ++mapState.metroScheduleRun;
        estimateElement.textContent = t('Searching for the next scheduled departure…');
        const params = new URLSearchParams({
            lat: String(coordinates.lat),
            lon: String(coordinates.lon),
            route_ref: routeRef,
            mode: 'subway',
        });
        try {
            const result = await fetchJson(`/api/transit/stops/nearest-next-departure?${params}`, {
                signal: AbortSignal.timeout(12_000),
            });
            if (run !== mapState.metroScheduleRun) return;
            if (result.departure) {
                const departure = result.departure;
                const countdown = departure.minutes_until === 0
                    ? t('now')
                    : t('in :minutes min', { minutes: departure.minutes_until });
                estimateElement.textContent = departure.estimated
                    ? t('Expected next metro train on :route: around :time (:countdown) · estimated from GTFS', {
                        route: routeRef,
                        time: departure.time,
                        countdown,
                    })
                    : t('Next metro train on :route: :time (:countdown) · GTFS timetable', {
                        route: routeRef,
                        time: departure.time,
                        countdown,
                    });
                return;
            }
        } catch {
            if (run !== mapState.metroScheduleRun) return;
        }

        if (run !== mapState.metroScheduleRun) return;
        const approximate = getMetroArrivalWindow();
        estimateElement.textContent = t('The published metro timetable is unavailable. Estimated next train: :from–:until (about a 5-minute interval).', approximate);
        return;
    }

    const { from, until } = getMetroArrivalWindow();
    estimateElement.textContent = t('Next train: estimated between :from and :until (generic ~5 min interval; this is not live information and does not confirm service).', { from, until });
}

export function hidePoiTooltip() {
    poiTooltipElement.hidden = true;
    poiTooltip.setPosition(undefined);
}

/** Puts the tooltip overlay on the map; called once by the map page. */
export function attachPoiTooltip(olMap) {
    map = olMap;
    map.addOverlay(poiTooltip);
}
