import { test } from 'node:test';
import assert from 'node:assert/strict';
import { haversineMeters } from '../resources/js/geo.js';

test('the same point is zero metres away', () => {
    assert.equal(haversineMeters(44.4268, 26.1025, 44.4268, 26.1025), 0);
});

test('one degree of latitude is about 111.2 km', () => {
    assert.ok(Math.abs(haversineMeters(45, 25, 46, 25) - 111195) < 1);
});

test('Bucharest to Cluj-Napoca is about 324 km', () => {
    const meters = haversineMeters(44.4268, 26.1025, 46.7712, 23.6236);
    assert.ok(meters > 320_000 && meters < 330_000, `got ${meters}`);
});

test('the distance is symmetric', () => {
    assert.equal(
        haversineMeters(44.4268, 26.1025, 46.7712, 23.6236),
        haversineMeters(46.7712, 23.6236, 44.4268, 26.1025),
    );
});
