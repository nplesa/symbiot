import { haversineMeters } from './geo.js';

/** Never reload the stops for movements shorter than this, whatever the radius. */
export const MIN_RELOAD_DISTANCE_METERS = 500;

/** At most one follow-up reload per this interval, so fast travel does not flood the API. */
export const MIN_RELOAD_INTERVAL_MS = 30_000;

/**
 * Whether the transit stops should be reloaded around the user's new GPS position:
 * once they have moved half the search radius (at least 500 m) away from the last load,
 * and not more often than every 30 seconds.
 *
 * @param {{lat: number, lon: number, radius: number, at: number}|null} lastLoad
 * @param {number} lat
 * @param {number} lon
 * @param {number} now milliseconds, e.g. Date.now()
 */
export function shouldReloadStops(lastLoad, lat, lon, now) {
    if (!lastLoad) return false;
    if (now - lastLoad.at < MIN_RELOAD_INTERVAL_MS) return false;

    const threshold = Math.max(MIN_RELOAD_DISTANCE_METERS, (Number(lastLoad.radius) || 0) / 2);

    return haversineMeters(lastLoad.lat, lastLoad.lon, lat, lon) >= threshold;
}
