/**
 * Pure helpers of the map page: POI types and category colours, POI coordinates, radius and
 * city-boundary filtering, and the direction of travel. No DOM, no map: unit-tested in tests-js.
 */
import { haversineMeters } from '../geo.js';

const POI_TYPES = {
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
    transport: 'transport',
};

/** The POI category of a type sent by the server (aliases such as "aerodrom" included). */
export function normalizePoiType(type) {
    return POI_TYPES[type] || 'transport';
}

const CATEGORY_PALETTE = [
    '#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#008080',
    '#f032e6', '#9a6324', '#800000', '#808000', '#000075', '#e6a800',
    '#00a6d6', '#7cb518', '#b5179e', '#ff6f59',
];
const assignedCategoryColors = new Map();

/** Every category selected after another one gets the next unused colour, kept for the rest of the session. */
export function assignCategoryColors(types) {
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

/** The colour assigned to a category, if any. */
export function assignedCategoryColor(type) {
    return assignedCategoryColors.get(type);
}

/** { lat, lon } of a POI (coordinates.lat/lon or lat/lon), or null when missing or out of range. */
export function getPoiCoordinates(item) {
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

/**
 * The POIs within the radius, or inside the city boundary when one is given ("whole city" search).
 *
 * @param {Array<object>} data
 * @param {object|null} boundary GeoJSON Polygon/MultiPolygon geometry of the city
 */
export function filterByRadius(data, lat, lon, radiusMeters, boundary = null) {
    return data.filter(item => {
        const coordinates = getPoiCoordinates(item);
        if (!coordinates) return false;
        const { lat: itemLat, lon: itemLon } = coordinates;

        if (boundary) {
            return pointInCityBoundary(itemLon, itemLat, boundary);
        }

        return haversineMeters(lat, lon, itemLat, itemLon) <= radiusMeters;
    });
}

export function pointInRing(lon, lat, ring) {
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

/** Whether a point lies in a GeoJSON Polygon/MultiPolygon (outer ring, outside its holes). */
export function pointInCityBoundary(lon, lat, geometry) {
    const polygons = geometry?.type === 'Polygon'
        ? [geometry.coordinates]
        : geometry?.type === 'MultiPolygon' ? geometry.coordinates : [];
    return polygons.some((rings) => pointInRing(lon, lat, rings[0])
        && !rings.slice(1).some((hole) => pointInRing(lon, lat, hole)));
}

/** Bearing in radians, clockwise from north, between two { lat, lon } points. */
export function bearingBetween(from, to) {
    const longitudeDelta = (to.lon - from.lon) * Math.cos((from.lat + to.lat) * Math.PI / 360);
    const latitudeDelta = to.lat - from.lat;

    return Math.atan2(longitudeDelta, latitudeDelta);
}

/** Flat-earth distance in metres, good enough for a few metres of movement. */
export function approximateDistanceMeters(from, to) {
    const longitudeDelta = (to.lon - from.lon) * Math.cos((from.lat + to.lat) * Math.PI / 360);

    return Math.hypot(longitudeDelta, to.lat - from.lat) * 111320;
}
