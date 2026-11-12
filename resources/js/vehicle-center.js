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

    const toRad = (value) => value * Math.PI / 180;
    const dLat = toRad(lat - center.lat);
    const dLon = toRad(lon - center.lon);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(center.lat)) * Math.cos(toRad(lat)) * Math.sin(dLon / 2) ** 2;

    return 6371000 * 2 * Math.asin(Math.min(1, Math.sqrt(h))) >= VEHICLE_RECENTER_METERS;
}
