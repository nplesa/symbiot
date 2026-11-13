/** Mean Earth radius used across the app, in metres (same as App\Support\GeoDistance). */
export const EARTH_RADIUS_METERS = 6371000;

/**
 * Great-circle distance in metres between two lat/lon points (haversine).
 *
 * @param {number} lat1
 * @param {number} lon1
 * @param {number} lat2
 * @param {number} lon2
 * @returns {number}
 */
export function haversineMeters(lat1, lon1, lat2, lon2) {
    const toRad = (degrees) => degrees * Math.PI / 180;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const h = Math.sin(dLat / 2) ** 2
        + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;

    return EARTH_RADIUS_METERS * 2 * Math.asin(Math.min(1, Math.sqrt(h)));
}
