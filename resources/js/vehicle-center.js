import { haversineMeters } from './geo.js';

/** Live vehicles are re-queried right away once the user moved this far from the last query point. */
export const VEHICLE_RECENTER_METERS = 300;

/**
 * Whether the live-vehicle search center should jump to the user's new position now
 * (instead of waiting for the next 10 s refresh).
 *
 * @param {{lat: number, lon: number}|null} center
 * @param {number} lat
 * @param {number} lon
 */
export function movedFarEnough(center, lat, lon) {
    if (!center) return true;

    return haversineMeters(center.lat, center.lon, lat, lon) >= VEHICLE_RECENTER_METERS;
}
