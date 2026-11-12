/** GPS fixes less precise than this are shown on the map but not stored. */
export const MAX_ACCURACY_METERS = 50;

/** Store a point once the user moved this far or this much time passed since the last stored one. */
export const MIN_DISTANCE_METERS = 10;
export const MIN_INTERVAL_MS = 10_000;

function metersBetween(a, b) {
    const toRad = (value) => value * Math.PI / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLon = toRad(b.lon - a.lon);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLon / 2) ** 2;

    return 6371000 * 2 * Math.asin(Math.min(1, Math.sqrt(h)));
}

/**
 * Whether a navigation GPS fix should be sent to /tracking/point.
 *
 * @param {{lat: number, lon: number, at: number}|null} lastSent
 * @param {{latitude: number, longitude: number, accuracy?: number|null}} coords
 * @param {number} nowMs
 */
export function shouldSendPoint(lastSent, coords, nowMs) {
    if (Number.isFinite(coords.accuracy) && coords.accuracy > MAX_ACCURACY_METERS) return false;
    if (!lastSent) return true;

    return nowMs - lastSent.at >= MIN_INTERVAL_MS
        || metersBetween(lastSent, { lat: coords.latitude, lon: coords.longitude }) >= MIN_DISTANCE_METERS;
}
