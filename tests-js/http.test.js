import assert from 'node:assert/strict';
import test from 'node:test';

import { fetchJson, HttpError, readJson } from '../resources/js/http.js';

function jsonResponse(body, status = 200, extra = {}) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
        ...extra,
    });
}

function htmlResponse(status) {
    return new Response('<!doctype html><title>Error</title>', { status, headers: { 'Content-Type': 'text/html' } });
}

async function rejection(promise) {
    try {
        await promise;
    } catch (error) {
        return error;
    }
    assert.fail('Expected the promise to reject.');
}

test('returns the payload of a successful JSON response', async () => {
    assert.deepEqual(await readJson(jsonResponse({ stops: [1, 2] })), { stops: [1, 2] });
});

test('explains expired sessions and rate limits instead of showing raw pages', async () => {
    const expired = await rejection(readJson(htmlResponse(419)));
    assert.ok(expired instanceof HttpError);
    assert.equal(expired.status, 419);
    assert.equal(expired.message, 'Your session has expired. Please reload the page.');

    // The server's throttle message talks about login attempts; the client knows better.
    const limited = await rejection(readJson(jsonResponse({ message: 'Prea multe încercări de autentificare.' }, 429)));
    assert.equal(limited.message, 'Too many requests. Try again in a minute.');
});

test('uses the server message, then the first validation error', async () => {
    const server = await rejection(readJson(jsonResponse({ error: 'Localitatea nu a fost găsită.' }, 404)));
    assert.equal(server.message, 'Localitatea nu a fost găsită.');
    assert.equal(server.status, 404);

    const validation = await rejection(readJson(jsonResponse({ message: 'The given data was invalid.', errors: { lat: ['Latitudinea este invalidă.'] } }, 422)));
    assert.equal(validation.message, 'Latitudinea este invalidă.');
});

test('never surfaces "Unexpected token <" for HTML error pages or invalid JSON', async () => {
    const serverError = await rejection(readJson(htmlResponse(500)));
    assert.equal(serverError.message, 'The request failed (HTTP 500).');

    const unexpectedHtml = await rejection(readJson(htmlResponse(200)));
    assert.match(unexpectedHtml.message, /unexpected response \(HTTP 200\)/);

    const broken = await rejection(readJson(new Response('{"oops"', { status: 200, headers: { 'Content-Type': 'application/json' } })));
    assert.equal(broken.message, 'The server returned an invalid JSON response.');
});

test('a redirect to the login page is reported as an expired session', async () => {
    const response = htmlResponse(200);
    Object.defineProperty(response, 'redirected', { value: true });
    Object.defineProperty(response, 'url', { value: 'https://symbiot.local/login' });

    const error = await rejection(readJson(response));
    assert.equal(error.status, 401);
});

test('fetchJson sends JSON headers and the CSRF token on writes', async () => {
    globalThis.document = { querySelector: () => ({ content: 'csrf-123' }) };
    let seen;
    const fetchImpl = async (url, init) => {
        seen = { url, init };
        return jsonResponse({ success: true });
    };

    try {
        assert.deepEqual(await fetchJson('/location/update', { method: 'POST', body: '{}', fetchImpl }), { success: true });
    } finally {
        delete globalThis.document;
    }

    assert.equal(seen.init.headers.Accept, 'application/json');
    assert.equal(seen.init.headers['X-CSRF-TOKEN'], 'csrf-123');
    assert.equal(seen.init.headers['Content-Type'], 'application/json');
    assert.equal(seen.init.credentials, 'same-origin');
});
