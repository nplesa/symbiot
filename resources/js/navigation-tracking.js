import { haversineMeters } from './geo.js';

/** GPS fixes less precise than this are shown on the map but not stored. */
export const MAX_ACCURACY_METERS = 50;

/** Store a point once the user moved this far or this much time passed since the last stored one. */
export const MIN_DISTANCE_METERS = 10;
export const MIN_INTERVAL_MS = 10_000;

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
        || haversineMeters(lastSent.lat, lastSent.lon, coords.latitude, coords.longitude) >= MIN_DISTANCE_METERS;
}
