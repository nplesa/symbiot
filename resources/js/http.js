/**
 * JSON requests to the app's own endpoints with consistent error messages,
 * so an HTML error page (419, 500, login redirect) never surfaces as "Unexpected token <".
 */

export class HttpError extends Error {
    constructor(message, status = 0, payload = null) {
        super(message);
        this.name = 'HttpError';
        this.status = status;
        this.payload = payload;
    }
}

// The server's own text for these is either missing (HTML page) or misleading
// (the global throttle handler talks about login attempts).
const STATUS_MESSAGES = {
    401: 'Sesiunea a expirat. Autentifică-te din nou.',
    419: 'Sesiunea a expirat. Reîncarcă pagina.',
    429: 'Prea multe cereri. Încearcă din nou peste un minut.',
};

function isJsonResponse(response) {
    return /\bapplication\/(?:[\w.+-]*\+)?json\b/i.test(response.headers.get('content-type') || '');
}

/**
 * @param {Response} response
 * @returns {Promise<any>} the parsed JSON payload of a successful response
 * @throws {HttpError}
 */
export async function readJson(response) {
    const responseUrl = response.url ? new URL(response.url, 'http://localhost') : null;
    if (response.redirected && responseUrl?.pathname.endsWith('/login')) {
        throw new HttpError(STATUS_MESSAGES[401], 401);
    }

    let payload = null;
    let invalidJson = false;
    if (isJsonResponse(response)) {
        try {
            payload = await response.json();
        } catch {
            invalidJson = true;
        }
    }

    if (!response.ok) {
        const validationError = Object.values(payload?.errors || {}).flat()[0];
        const message = STATUS_MESSAGES[response.status]
            || payload?.error
            || validationError
            || payload?.message
            || `Cererea a eșuat (HTTP ${response.status}).`;

        throw new HttpError(message, response.status, payload);
    }

    if (invalidJson) {
        throw new HttpError('Serverul a returnat un răspuns JSON invalid.', response.status);
    }
    if (payload === null && !isJsonResponse(response)) {
        throw new HttpError(`Serverul a returnat un răspuns neașteptat (HTTP ${response.status}).`, response.status);
    }

    return payload;
}

/**
 * fetch() + readJson(), with the JSON Accept header and, for writes, the CSRF token.
 *
 * @param {string} url
 * @param {RequestInit & {fetchImpl?: typeof fetch}} options
 */
export async function fetchJson(url, { fetchImpl = fetch, headers = {}, ...options } = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const requestHeaders = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...headers,
    };

    if (method !== 'GET' && method !== 'HEAD') {
        const csrfToken = globalThis.document?.querySelector('meta[name="csrf-token"]')?.content;
        if (csrfToken && !requestHeaders['X-CSRF-TOKEN']) requestHeaders['X-CSRF-TOKEN'] = csrfToken;
        if (typeof options.body === 'string' && !requestHeaders['Content-Type']) {
            requestHeaders['Content-Type'] = 'application/json';
        }
    }

    const response = await fetchImpl(url, { credentials: 'same-origin', ...options, headers: requestHeaders });

    return readJson(response);
}
