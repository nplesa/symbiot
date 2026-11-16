import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MIN_RELOAD_INTERVAL_MS, shouldReloadStops } from '../resources/js/transit-follow.js';

// One degree of latitude is about 111.2 km, so 0.01° ≈ 1112 m.
const lastLoad = { lat: 45.65, lon: 25.6, radius: 1500, at: 0 };
const later = MIN_RELOAD_INTERVAL_MS + 1;

test('without a previous load the stops are not reloaded by GPS moves', () => {
    assert.equal(shouldReloadStops(null, 45.7, 25.6, later), false);
});

test('moving less than half the radius keeps the loaded stops', () => {
    // ~556 m < 750 m (half of 1500 m)
    assert.equal(shouldReloadStops(lastLoad, 45.655, 25.6, later), false);
});

test('moving half the radius away reloads the stops', () => {
    // ~1112 m >= 750 m
    assert.equal(shouldReloadStops(lastLoad, 45.66, 25.6, later), true);
});

test('a small radius still waits for at least 500 m', () => {
    const small = { ...lastLoad, radius: 300 };
    assert.equal(shouldReloadStops(small, 45.6533, 25.6, later), false); // ~367 m
    assert.equal(shouldReloadStops(small, 45.655, 25.6, later), true); // ~556 m
});

test('reloads are spaced at least 30 seconds apart', () => {
    assert.equal(shouldReloadStops(lastLoad, 45.7, 25.6, MIN_RELOAD_INTERVAL_MS - 1), false);
    assert.equal(shouldReloadStops(lastLoad, 45.7, 25.6, MIN_RELOAD_INTERVAL_MS), true);
});
