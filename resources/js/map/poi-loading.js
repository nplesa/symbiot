/**
 * Loading the POIs of the ticked categories around the location (loadNearby), drawing them, the
 * category filters of the right panel and which subcategories exist around (two-step availability).
 */
import Feature from 'ol/Feature.js';
import Point from 'ol/geom/Point.js';
import { fromLonLat } from 'ol/proj.js';
import { haversineMeters } from '../geo.js';
import { fetchJson } from '../http.js';
import { t } from '../i18n.js';
import { assignCategoryColors, filterByRadius, getPoiCoordinates, normalizePoiType } from './poi-helpers.js';
import { hidePOIModal, showPOIModal } from './poi-loading-modal.js';
import { buildPoiCategoryRequests, fetchPoiJsonResponse } from './poi-map-interactions.js';
import { hidePoiTooltip } from './poi-tooltip.js';
import { activePoiFilters, mapState } from './state.js';
import { clearVisibleTransitRoutes } from './transit-routes.js';

const POI_FILTER_DEBOUNCE_MS = 400;

/** The map page objects this module works with (configurePoiLoading). */
let map = null;
let poiSource = null;
let poiLayer = null;
let transitStops = null;

/** Called once by the map page, before any POI is loaded. */
export function configurePoiLoading(page) {
    ({ map, poiSource, poiLayer, transitStops } = page);
}

function renderPOI(data = [], userLat, userLon) {

    poiSource.clear();

    if (!Array.isArray(data)) return;

    const features = [];

    for (const item of data) {
        const coordinates = getPoiCoordinates(item);
        if (!coordinates) continue;
        const { lat, lon } = coordinates;

        const distance = haversineMeters(userLat, userLon, lat, lon);

        if (distance > Number(mapState.app_radius)) continue;

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

export async function loadTourismPOI() {

    let cl = document.getElementById('current_location');
    const lat = parseFloat(cl.dataset.lat);
    const lon = parseFloat(cl.dataset.lon);


    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        console.warn('Invalid coordinates');
        hidePOIModal();
        return;
    }

    const url =
        `/api/transport-nearby?lat=${lat}&lon=${lon}&radius=${mapState.app_radius}&types=tourism`;

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

export async function loadNearby(locationOverride = null) {

    const requestId = ++mapState.poiRequestId;
    mapState.poiLoadController?.abort();
    const loadController = new AbortController();
    mapState.poiLoadController = loadController;
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

        activePoiFilters.clear();
        addUserEvents();

        await updateSubcategoryAvailability(lat, lon, loadController.signal);
        if (requestId !== mapState.poiRequestId) return;

        let selectedTypes = poiContainer ? selectedPoiFilterTokens(poiContainer) : [];
        if (selectedTypes.length > 0) {
            window.dispatchEvent(new CustomEvent('symbiot:nearby-loading'));
        }
        transitStops.setVisible(selectedTypes.includes('bus'));
        // Bus stops and lines come from the stored transit feeds (blue stops) wherever they exist;
        // the OpenStreetMap bus POI layer stays only as a fallback for uncovered areas.
        let hasStoredStops = false;
        if (selectedTypes.includes('bus')) {
            try {
                hasStoredStops = await transitStops.load(
                    lat,
                    lon,
                    Math.min(Number(mapState.app_radius) || 1500, 35000),
                    'bus'
                );
            } catch (error) {
                console.error('Stored transit stops could not be loaded; trying OpenStreetMap POIs.', error);
            }
            if (requestId !== mapState.poiRequestId) return;
            if (hasStoredStops) {
                selectedTypes = selectedTypes.filter(type => type !== 'bus');
            }
        }
        if (selectedTypes.length === 0) {
            mapState.poiRequestId++;
            poiSource.clear();
            clearVisibleTransitRoutes();
            hidePoiTooltip();
            hidePOIModal();
            if (hasStoredStops) {
                // The GTFS stop request already emitted nearby-transit-stops. Clear
                // only regular POIs so the dashboard keeps the bus-stop count.
                window.dispatchEvent(new CustomEvent('symbiot:nearby-pois', { detail: { pois: [] } }));
            } else {
                window.dispatchEvent(new CustomEvent('symbiot:nearby-cleared'));
            }

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
        const cacheKey = `${lat.toFixed(4)}:${lon.toFixed(4)}:${mapState.app_radius}`;
        if (mapState.poiResultCache.key !== cacheKey) {
            mapState.poiResultCache = { key: cacheKey, tokens: new Set(), data: [] };
        }
        // Only filters not already loaded for this place and radius are requested again.
        const pendingTypes = selectedTypes.filter(token => !mapState.poiResultCache.tokens.has(token));
        const requests = buildPoiCategoryRequests(pendingTypes, {
            transitEndpoints,
            categoryEndpoints,
            wazeEndpoint: '/api/poi/waze-traffic',
            categoryLabels,
            lat,
            lon,
            radius: mapState.app_radius,
        });

        const responses = await Promise.allSettled(
            requests.map(({ url }) => fetchPoiJsonResponse(url, { signal: loadController.signal }))
        );
        if (requestId !== mapState.poiRequestId) return;
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
                    .forEach(token => mapState.poiResultCache.tokens.add(token));
            } else if (category !== 'waze-traffic') {
                pendingTypes
                    .filter(token => token === category || token.startsWith(`subcategory:${category}:`))
                    .forEach(token => mapState.poiResultCache.tokens.add(token));
            }
            mapState.poiResultCache.data.push(...result.value);
        });
        if (successfulResponses.length > 0 || pendingTypes.length === 0) {
            const seen = new Set();
            mapState.poiResultCache.data = mapState.poiResultCache.data.filter(poi => {
                const key = `${poi.type}:${poi.id ?? ''}:${poi.lat}:${poi.lon}:${poi.name ?? ''}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });
        }
        const data = mapState.poiResultCache.data;

        if (requestId !== mapState.poiRequestId) return;
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
        if (requestId !== mapState.poiRequestId) return;
        
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
        if (requestId === mapState.poiRequestId) {
            await hidePOIModal();
            if (notification) {
                await Swal.fire(notification);
            }
        }

    }
}





















function applyPOIFilters(catFilters, data) {
    if (!Array.isArray(data)) return [];

    if (!Array.isArray(catFilters) || catFilters.length === 0) return [];

    return data.filter(poi => {
        return catFilters.includes(poi.type);
    });
}


// The lists open only once they are filtered for the chosen location, so they appear instantly when clicked.
/**
 * While the availability check runs the arrow stays (dimmed, aria-busy); open lists stay open and
 * their rows update when the answer arrives. (Replacing the content used to wipe the arrow icon.)
 */
function setSubcategoryButtonsLoading(loading) {
    document.querySelectorAll('.poi-subcategory-toggle').forEach(button => {
        button.classList.toggle('is-loading', loading);
        button.setAttribute('aria-busy', String(loading));
    });
}

/**
 * The count next to a category shows its subcategories available in the area; an empty list
 * says so instead of opening blank.
 */
function showAvailableSubcategoryCount(group) {
    const available = group.querySelectorAll('.location-subcategory:not(:disabled)').length;
    const count = group.querySelector('.poi-filter-count');
    if (count) {
        count.textContent = String(available);
        count.setAttribute('aria-label', t(':count subcategories', { count: available }));
    }

    const list = group.querySelector('.poi-subcategory-list');
    if (!list) return;
    let empty = list.querySelector('.poi-subcategory-empty');
    if (available === 0) {
        if (!empty) {
            empty = document.createElement('p');
            empty.className = 'poi-subcategory-empty';
            list.append(empty);
        }
        empty.textContent = t('Nothing in this area within the chosen radius. Increase the search radius.');
        empty.hidden = false;
    } else if (empty) {
        empty.hidden = true;
    }
}

// Subcategories are limited to the ones that actually have places around the chosen location, in two
// steps (see PoiSubcategoryAvailability): "fast" (existence checks) is waited for; "details" (subcategories
// of the dense categories, Overpass, Nominatim: slow) completes the pending categories in the background.
const SUBCATEGORY_TIMEOUT_MS = 15_000;
// Up to 15 s of Geoapify plus the Nominatim fallback of the aerodromes on the server.
const SUBCATEGORY_DETAILS_TIMEOUT_MS = 22_000;

export async function updateSubcategoryAvailability(lat, lon, signal = null) {
    const key = `${lat.toFixed(2)}:${lon.toFixed(2)}:${mapState.app_radius}`;
    if (key === mapState.subcategoriesKey || key === mapState.subcategoriesPendingKey) return;
    mapState.subcategoriesPendingKey = key;
    setSubcategoryButtonsLoading(true);

    // loadNearby() waits for this behind the loading modal: never let it hang.
    const timeout = AbortSignal.timeout(SUBCATEGORY_TIMEOUT_MS);
    const requestSignal = signal && typeof AbortSignal.any === 'function'
        ? AbortSignal.any([signal, timeout])
        : timeout;

    let available = null;
    let pending = [];
    try {
        const params = new URLSearchParams({ lat, lon, radius: Math.min(Math.round(Number(mapState.app_radius)) || 5000, 35000), phase: 'fast' });
        const response = await fetch(`/api/poi/subcategories?${params}`, {
            headers: { Accept: 'application/json' },
            signal: requestSignal,
        });
        if (response.ok) {
            const body = await response.json();
            available = body.subcategories;
            pending = Array.isArray(body.pending) ? body.pending : [];
        }
    } catch (error) {
        console.error('Subcategory availability request failed.', error);
    } finally {
        if (mapState.subcategoriesPendingKey === key) {
            mapState.subcategoriesPendingKey = null;
            setSubcategoryButtonsLoading(false);
        }
    }
    if (!available || typeof available !== 'object') {
        // Unknown here (timeout, error): show everything rather than keep the previous area's answer
        // (e.g. a subway hidden in Brașov must not stay hidden in Bucharest). The key is not
        // remembered, so the next search asks again.
        applySubcategoryAvailability({});
        return;
    }

    mapState.subcategoriesKey = key;
    // Pending categories are unknown (visible) until the background step answers.
    const fast = Object.fromEntries(Object.entries(available).filter(([type]) => type === 'airport' || !pending.includes(type)));
    applySubcategoryAvailability(fast);
    if (pending.length > 0) completeSubcategoryAvailability(lat, lon, key, fast, pending);
}

/** Step 2: the pending categories, not awaited by the POI loading. */
async function completeSubcategoryAvailability(lat, lon, key, fast, pending) {
    let osm = null;
    try {
        const params = new URLSearchParams({ lat, lon, radius: Math.min(Math.round(Number(mapState.app_radius)) || 5000, 35000), phase: 'details' });
        const response = await fetch(`/api/poi/subcategories?${params}`, {
            headers: { Accept: 'application/json' },
            signal: AbortSignal.timeout(SUBCATEGORY_DETAILS_TIMEOUT_MS),
        });
        if (response.ok) osm = (await response.json()).subcategories;
    } catch {
        // The pending categories simply stay visible.
    }
    // Another location or radius was chosen meanwhile, or nothing came back.
    if (mapState.subcategoriesKey !== key || !osm || typeof osm !== 'object') return;

    const merged = { ...fast };
    pending.forEach((type) => {
        if (!Array.isArray(osm[type])) return;
        // Airport subcategories of the OSM aerodromes add to the Geoapify ones.
        merged[type] = type === 'airport' && Array.isArray(fast.airport)
            ? [...new Set([...fast.airport, ...osm[type]])]
            : osm[type];
    });
    applySubcategoryAvailability(merged);
}

function applySubcategoryAvailability(available) {
    document.querySelectorAll('.poi-category-group').forEach(group => {
        const allowedIds = available[group.dataset.type];
        // Unknown for this area (no data source answered): everything stays selectable.
        const allowed = Array.isArray(allowedIds) ? new Set(allowedIds) : null;
        group.querySelectorAll('.location-subcategory').forEach(input => {
            const visible = allowed === null || allowed.has(input.dataset.filter);
            input.disabled = !visible;
            if (!visible) input.checked = false;
            const row = input.closest('.poi-subcategory-choice');
            if (row) row.hidden = !visible;
        });
        showAvailableSubcategoryCount(group);

        const parent = group.querySelector('.location-category');
        // Nothing of this category in the area (e.g. a subway in Brașov): hide it until the area or radius changes.
        const unavailable = allowed !== null && allowed.size === 0;
        group.classList.toggle('poi-category-unavailable', unavailable);
        if (parent) {
            parent.disabled = false;
            if (unavailable) {
                parent.checked = false;
                parent.indeterminate = false;
            }
        }
        group.classList.remove('opacity-50');
    });

    const container = document.getElementById('mobility_card');
    if (container) syncPoiFilterSelection(container);
}

export function syncPoiFilterSelection(container) {
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

export function selectedPoiFilterTokens(container) {
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

    const filtered = filterByRadius(data, lat, lon, mapState.app_radius, mapState.citywideSearch && mapState.cityBoundaryFeature ? mapState.cityBoundaryFeature.geometry : null);

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



    renderPOI(enriched, lat, lon);
    const poiCountBadge = document.getElementById('dashboard-panel-poi-count');
    if (poiCountBadge) poiCountBadge.textContent = `${enriched.length} POI`;
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

export function addUserEvents () {
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
            // Categories with default subcategories (Parking: cars) tick only those; a click on the
            // partly ticked category clears it (otherwise it could never be unticked from there).
            const defaults = [...children].filter(child => child.hasAttribute('data-default-subcategory'));
            if (defaults.length > 0) {
                const anyTicked = [...children].some(child => child.checked);
                children.forEach(child => {
                    child.checked = !anyTicked && defaults.includes(child);
                });
            } else {
                children.forEach(child => {
                    child.checked = checkbox.checked;
                });
            }
        }

        syncPoiFilterSelection(container);
        removeUnselectedPoiFeatures();
        transitStops.setVisible(selectedPoiFilterTokens(container).includes('bus'));
        const poiCountBadge = document.getElementById('dashboard-panel-poi-count');
        if (poiCountBadge) {
            poiCountBadge.textContent = selectedPoiFilterTokens(container).length > 0 ? '… POI' : '0 POI';
        }

        if (selectedPoiFilterTokens(container).length === 0) {
            clearTimeout(mapState.poiLoadDebounce);
            mapState.poiLoadController?.abort();
            mapState.poiRequestId++;
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
            clearTimeout(mapState.poiLoadDebounce);
            mapState.poiLoadDebounce = setTimeout(() => {
                mapState.poiLoadDebounce = null;
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


/** Drops markers of deselected categories right away instead of waiting for the next response. */
function removeUnselectedPoiFeatures() {
    poiSource.getFeatures()
        .filter(feature => feature.get('type') && !activePoiFilters.has(feature.get('type')))
        .forEach(feature => poiSource.removeFeature(feature));
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
            distanceMeters = haversineMeters(
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





/** "Cancel" in the loading modal: stops the running POI load; POIs already on the map stay. */
export function cancelPoiLoad() {
    clearTimeout(mapState.poiLoadDebounce);
    mapState.poiLoadController?.abort();
    mapState.poiRequestId++;
    hidePOIModal();
}
