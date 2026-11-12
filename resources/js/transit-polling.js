/** Total time the coverage panel keeps polling an import before giving up. */
export const MAX_POLL_DURATION_MS = 30 * 60 * 1000;

/**
 * Delay before the next transit coverage poll, or null once polling should stop.
 * Fast at first so progress feels live, then slower: large GTFS imports can take
 * tens of minutes and every poll is a request against the coverage rate limit.
 *
 * @param {number} elapsedMs time since polling started
 * @returns {number|null}
 */
export function pollDelayMs(elapsedMs) {
    if (elapsedMs >= MAX_POLL_DURATION_MS) return null;
    if (elapsedMs < 60 * 1000) return 2000;
    if (elapsedMs < 5 * 60 * 1000) return 5000;

    return 15000;
}
