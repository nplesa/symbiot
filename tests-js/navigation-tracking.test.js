import assert from 'node:assert/strict';
import test from 'node:test';

import { shouldSendPoint } from '../resources/js/navigation-tracking.js';

const start = { lat: 45.65, lon: 25.6, at: 0 };
const fix = (meters, accuracy = 5) => ({ latitude: 45.65 + meters / 111320, longitude: 25.6, accuracy });

test('the first precise fix is always sent', () => {
    assert.equal(shouldSendPoint(null, fix(0), 0), true);
});

test('imprecise fixes are never stored', () => {
    assert.equal(shouldSendPoint(null, fix(0, 120), 0), false);
    assert.equal(shouldSendPoint(start, fix(500, 51), 60_000), false);
});

test('nearby fixes within 10 s are skipped', () => {
    assert.equal(shouldSendPoint(start, fix(4), 3_000), false);
});

test('a fix is sent after 10 m or after 10 s', () => {
    assert.equal(shouldSendPoint(start, fix(12), 1_000), true);
    assert.equal(shouldSendPoint(start, fix(1), 10_000), true);
});

test('a missing accuracy value does not block the fix', () => {
    assert.equal(shouldSendPoint(null, { latitude: 45.65, longitude: 25.6, accuracy: null }, 0), true);
});
