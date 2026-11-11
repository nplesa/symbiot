/**
 * Normalizes an external link from third-party data (OSM tags, timetable feeds)
 * and returns it only for http(s), so values like `javascript:` never reach an href.
 * Bare domains ("example.com") are treated as https.
 *
 * @param {unknown} value
 * @returns {string|null}
 */
export function safeExternalUrl(value) {
    if (typeof value !== 'string') return null;

    const trimmed = value.trim();
    if (trimmed === '') return null;

    // An explicit non-http(s) scheme (javascript:, data:, file:, mailto:...) is rejected
    // instead of being prefixed; "host:port" stays allowed because a digit follows the colon.
    if (/^[a-z][a-z\d+.-]*:(?!\d)/i.test(trimmed) && !/^https?:\/\//i.test(trimmed)) return null;

    const candidate = /^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
    try {
        const url = new URL(candidate);
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;

        return url.href;
    } catch {
        return null;
    }
}
