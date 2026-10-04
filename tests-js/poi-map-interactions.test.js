import assert from 'node:assert/strict';
import test from 'node:test';

import {
    assignTransitRouteColors,
    bindPoiMapClick,
    groupTransitRoutes,
    getInfoferStationUrl,
    getMetroArrivalWindow,
    getTransitRouteColor,
    poiFeatureAtPixel,
    readPoiJsonResponse,
    splitTransitRouteDirections,
} from '../resources/js/pages/poi-map-interactions.js';

test('calculates a five-minute metro arrival window in Bucharest local time', () => {
    assert.deepEqual(
        getMetroArrivalWindow(new Date('2026-10-03T20:54:00Z')),
        { from: '23:54', until: '23:59' }
    );
});

test('builds a current-day Infofer departures link for a station name', () => {
    assert.equal(
        getInfoferStationUrl('Ploiești Nord', new Date('2026-10-03T12:00:00Z')),
        'https://mersultrenurilor.infofer.ro/ro-RO/Statie/Ploiesti-Nord?Date=03.10.2026'
    );
});

test('maps the Geoapify Gara de Nord name to Infofer official station slug', () => {
    assert.equal(
        getInfoferStationUrl('Gara-de-Nord', new Date('2026-10-03T12:00:00Z')),
        'https://mersultrenurilor.infofer.ro/ro-RO/Statie/Bucuresti-Nord?Date=03.10.2026'
    );
});

test('keeps Bucuresti Basarab mapped to its own Infofer station page', () => {
    assert.equal(
        getInfoferStationUrl('București Basarab', new Date('2026-10-03T12:00:00Z')),
        'https://mersultrenurilor.infofer.ro/ro-RO/Statie/Bucuresti-Basarab?Date=03.10.2026'
    );
});

test('groups duplicate line labels while preserving separate direction geometries', () => {
    const outbound = { id: 'relation/1', label: 'Autobuz 612', coordinates: [[1, 2], [3, 4]] };
    const inbound = { id: 'relation/2', label: ' autobuz 612 ', coordinates: [[3, 4], [1, 2]] };
    const otherLine = { id: 'relation/3', label: 'Autobuz 610', coordinates: [[5, 6], [7, 8]] };

    assert.deepEqual(groupTransitRoutes([outbound, inbound, otherLine]), [
        { key: 'autobuz 612', label: 'Autobuz 612', routes: [outbound, inbound] },
        { key: 'autobuz 610', label: 'Autobuz 610', routes: [otherLine] },
    ]);
});

test('groups OSM variants with trailing dashes under the same line number', () => {
    const line611 = { id: 'relation/10', label: 'Autobuz 611' };
    const dashedLine611 = { id: 'relation/11', label: 'Autobuz 611---' };
    const line611Inbound = { id: 'relation/12', label: 'Autobuz 611' };
    const line610 = { id: 'relation/13', label: 'Autobuz 610' };

    assert.deepEqual(groupTransitRoutes([
        line611,
        dashedLine611,
        line611Inbound,
        line610,
    ]), [
        {
            key: 'autobuz 611',
            label: 'Autobuz 611',
            routes: [line611, dashedLine611, line611Inbound],
        },
        { key: 'autobuz 610', label: 'Autobuz 610', routes: [line610] },
    ]);
});

test('splits bus line directions into independently selectable route groups', () => {
    const outbound = { id: 'relation/20', label: 'Autobuz 5', direction: 'Centru → Gară' };
    const inbound = { id: 'relation/21', label: 'Autobuz 5', direction: 'Gară → Centru' };
    const groups = splitTransitRouteDirections(groupTransitRoutes([outbound, inbound]));

    assert.deepEqual(groups, [
        {
            key: 'autobuz 5::centru → gară',
            label: 'Autobuz 5',
            directionLabel: 'Centru → Gară',
            routes: [outbound],
        },
        {
            key: 'autobuz 5::gară → centru',
            label: 'Autobuz 5',
            directionLabel: 'Gară → Centru',
            routes: [inbound],
        },
    ]);
});

test('keeps routes grouped when OSM provides fewer than two directions', () => {
    const forward = { id: 'relation/30', label: 'Autobuz 10', direction: 'Centru → Gară' };
    const withoutDirection = { id: 'relation/31', label: 'Autobuz 10' };
    const groups = splitTransitRouteDirections(groupTransitRoutes([forward, withoutDirection]));

    assert.equal(groups.length, 1);
    assert.deepEqual(groups[0].routes, [forward, withoutDirection]);
});

test('rejects non-JSON category responses with a clear error', async () => {
    await assert.rejects(
        readPoiJsonResponse(new Response('<!doctype html>', {
            status: 200,
            headers: { 'content-type': 'text/html' },
        })),
        /non-JSON.*HTTP 200/
    );
});

test('returns a helpful message for non-JSON responses from category endpoints', async () => {
    const response = new Response('<!doctype html>', {
        status: 200,
        headers: { 'content-type': 'text/html' },
    });
    Object.defineProperties(response, {
        redirected: { value: true },
        url: { value: 'https://symbiot.npsoft.ro/login' },
    });

    await assert.rejects(
        readPoiJsonResponse(response),
        /Sesiunea a expirat/
    );
});

test('surfaces JSON API errors and rejects unexpected payload shapes', async () => {
    await assert.rejects(
        readPoiJsonResponse(new Response(JSON.stringify({ error: 'Provider down' }), {
            status: 500,
            headers: { 'content-type': 'application/json' },
        })),
        /Provider down/
    );

    await assert.rejects(
        readPoiJsonResponse(new Response(JSON.stringify({ data: [] }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        })),
        /format neașteptat/
    );
});

function createFixture({ nearestPixels = [], hitFeature = null } = {}) {
    const features = nearestPixels.map(({ pixel, feature }) => ({
        feature: {
            getGeometry: () => ({
                getCoordinates: () => pixel,
            }),
        },
    }));
    const map = {
        forEachFeatureAtPixel: (pixel, callback, options) => {
            assert.equal(options.hitTolerance, 12);
            assert.equal(options.layerFilter('poi-layer'), true);
            return hitFeature ? callback(hitFeature) : undefined;
        },
        getPixelFromCoordinate: coordinates => coordinates,
        getEventPixel: event => [event.clientX - 100, event.clientY - 50],
        getCoordinateFromPixel: pixel => ['coordinate', ...pixel],
        getViewport: () => ({
            getBoundingClientRect: () => ({
                left: 100,
                top: 50,
                right: 500,
                bottom: 450,
            }),
        }),
    };
    const poiSource = {
        getFeatures: () => features.map(({ feature }) => feature),
    };
    const target = {
        listener: null,
        capture: null,
        addEventListener(type, listener, capture) {
            assert.equal(type, 'click');
            this.listener = listener;
            this.capture = capture;
        },
        removeEventListener(type, listener, capture) {
            if (
                this.listener === listener
                && Boolean(this.capture?.capture ?? this.capture)
                    === Boolean(capture?.capture ?? capture)
            ) {
                this.listener = null;
            }
        },
    };
    const tooltipElement = {
        contains: targetNode => targetNode === 'tooltip-child',
    };

    return { map, poiSource, target, tooltipElement };
}

test('assigns distinct reusable picker-compatible colors to lines', () => {
    const assignedColors = new Map();
    const firstColor = getTransitRouteColor('autobuz 612', assignedColors);
    const secondColor = getTransitRouteColor('autobuz 610', assignedColors);

    assert.match(firstColor, /^#[\da-f]{6}$/i);
    assert.match(secondColor, /^#[\da-f]{6}$/i);
    assert.notEqual(firstColor, secondColor);
    assert.equal(getTransitRouteColor('autobuz 612', assignedColors), firstColor);
});

test('assigns maximally separated automatic hues to multiple transit lines', () => {
    const groups = ['line one', 'line two', 'line three', 'line four']
        .map(key => ({ key }));
    const assignedColors = new Map();
    assignTransitRouteColors(groups, assignedColors);

    const hues = groups.map(group => {
        const [red, green, blue] = assignedColors.get(group.key).slice(1).match(/../g)
            .map(channel => Number.parseInt(channel, 16) / 255);
        const maximum = Math.max(red, green, blue);
        const minimum = Math.min(red, green, blue);
        const difference = maximum - minimum;
        const hue = maximum === red
            ? ((green - blue) / difference) % 6
            : maximum === green
                ? (blue - red) / difference + 2
                : (red - green) / difference + 4;
        return Math.round(hue * 60 + 360) % 360;
    }).sort((first, second) => first - second);
    const hueGaps = hues.map((hue, index) =>
        ((hues[(index + 1) % hues.length] ?? hues[0]) - hue + 360) % 360
    );

    assert.ok(Math.min(...hueGaps) >= 60);
});

test('poiFeatureAtPixel prefers OpenLayers hits and otherwise finds the nearest POI', () => {
    const directFeature = { name: 'direct' };
    const fixture = createFixture({
        hitFeature: directFeature,
        nearestPixels: [{ pixel: [12, 12] }],
    });

    assert.equal(
        poiFeatureAtPixel(fixture.map, fixture.poiSource, 'poi-layer', [10, 10]),
        directFeature
    );

    const nearestFixture = createFixture({
        nearestPixels: [
            { pixel: [80, 80] },
            { pixel: [10, 12] },
        ],
    });
    assert.equal(
        poiFeatureAtPixel(nearestFixture.map, nearestFixture.poiSource, 'poi-layer', [10, 10]),
        nearestFixture.poiSource.getFeatures()[1]
    );
});

test('document capture click uses OpenLayers pixel conversion and shows the nearest POI', () => {
    const fixture = createFixture({
        nearestPixels: [{ pixel: [20, 30] }],
    });
    let shown;
    let hidden = false;
    bindPoiMapClick({
        ...fixture,
        poiLayer: 'poi-layer',
        showTooltip: (feature, coordinate) => {
            shown = { feature, coordinate };
        },
        hideTooltip: () => {
            hidden = true;
        },
    });

    assert.deepEqual(fixture.target.capture, { capture: true });
    fixture.target.listener({ target: 'map-canvas', clientX: 120, clientY: 80 });
    assert.deepEqual(shown, {
        feature: fixture.poiSource.getFeatures()[0],
        coordinate: ['coordinate', 20, 30],
    });
    assert.equal(hidden, false);
});

test('document capture ignores outside and tooltip clicks, closes on empty map', () => {
    const fixture = createFixture();
    let hideCount = 0;
    let showCount = 0;
    const unbind = bindPoiMapClick({
        ...fixture,
        poiLayer: 'poi-layer',
        showTooltip: () => {
            showCount += 1;
        },
        hideTooltip: () => {
            hideCount += 1;
        },
    });

    fixture.target.listener({ target: 'outside', clientX: 50, clientY: 50 });
    fixture.target.listener({ target: 'tooltip-child', clientX: 120, clientY: 80 });
    assert.equal(hideCount, 0);
    fixture.target.listener({ target: 'map-canvas', clientX: 120, clientY: 80 });
    assert.equal(hideCount, 1);
    assert.equal(showCount, 0);

    unbind();
    assert.equal(fixture.target.listener, null);
});

test('document capture ignores clicks inside the transit modal', () => {
    const fixture = createFixture();
    let showCount = 0;
    let hideCount = 0;
    bindPoiMapClick({
        ...fixture,
        poiLayer: 'poi-layer',
        showTooltip: () => {
            showCount += 1;
        },
        hideTooltip: () => {
            hideCount += 1;
        },
    });

    fixture.target.listener({
        target: { closest: selector => selector === '.modal, .modal-backdrop' },
        clientX: 120,
        clientY: 80,
    });
    assert.equal(showCount, 0);
    assert.equal(hideCount, 0);
});
