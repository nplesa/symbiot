import assert from 'node:assert/strict';
import test from 'node:test';

import { movedFarEnough, VEHICLE_RECENTER_METERS } from '../resources/js/vehicle-center.js';

const center = { lat: 45.65, lon: 25.6 };
const north = (meters) => 45.65 + meters / 111320;

test('without a previous center the vehicles are always queried', () => {
    assert.equal(movedFarEnough(null, 45.65, 25.6), true);
});

test('small GPS movements wait for the regular 10 s refresh', () => {
    assert.equal(movedFarEnough(center, north(50), 25.6), false);
    assert.equal(movedFarEnough(center, north(VEHICLE_RECENTER_METERS - 10), 25.6), false);
});

test('moving 300 m or more refreshes the live vehicles right away', () => {
    assert.equal(movedFarEnough(center, north(VEHICLE_RECENTER_METERS + 10), 25.6), true);
    assert.equal(movedFarEnough(center, north(5000), 25.6), true);
});
