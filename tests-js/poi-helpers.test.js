import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    approximateDistanceMeters,
    assignCategoryColors,
    assignedCategoryColor,
    bearingBetween,
    filterByRadius,
    getPoiCoordinates,
    normalizePoiType,
    pointInCityBoundary,
} from '../resources/js/map/poi-helpers.js';

test('server types and aliases map to the POI categories', () => {
    assert.equal(normalizePoiType('bus_station'), 'bus');
    assert.equal(normalizePoiType('aerodrom'), 'airport');
    assert.equal(normalizePoiType('speed_camera'), 'speed_camera');
    assert.equal(normalizePoiType('something-new'), 'transport');
});

test('categories keep the colour they got first, in selection order', () => {
    assignCategoryColors(['fuel', 'parking']);
    assignCategoryColors(['parking', 'cafe']);
    assert.equal(assignedCategoryColor('fuel'), '#e6194b');
    assert.equal(assignedCategoryColor('parking'), '#3cb44b');
    assert.equal(assignedCategoryColor('cafe'), '#4363d8');
    assert.equal(assignedCategoryColor('never-selected'), undefined);
});

test('POI coordinates: nested or flat, as numbers or strings; missing or out of range is null', () => {
    assert.deepEqual(getPoiCoordinates({ coordinates: { lat: '45.6', lon: 25.5 } }), { lat: 45.6, lon: 25.5 });
    assert.deepEqual(getPoiCoordinates({ lat: 44.4, lon: '26.1' }), { lat: 44.4, lon: 26.1 });
    assert.equal(getPoiCoordinates({ lat: '', lon: 26 }), null);
    assert.equal(getPoiCoordinates({ lat: 95, lon: 26 }), null);
    assert.equal(getPoiCoordinates(null), null);
});

const square = { type: 'Polygon', coordinates: [[[25, 45], [26, 45], [26, 46], [25, 46], [25, 45]], [[25.4, 45.4], [25.6, 45.4], [25.6, 45.6], [25.4, 45.6], [25.4, 45.4]]] };

test('a point is in the city when inside the outer ring and outside its holes', () => {
    assert.equal(pointInCityBoundary(25.2, 45.2, square), true);
    assert.equal(pointInCityBoundary(25.5, 45.5, square), false, 'in the hole');
    assert.equal(pointInCityBoundary(27, 45.5, square), false);
    assert.equal(pointInCityBoundary(25.2, 45.2, { type: 'MultiPolygon', coordinates: [square.coordinates] }), true);
    assert.equal(pointInCityBoundary(25.2, 45.2, null), false);
});

test('POIs are kept within the radius, or inside the city boundary when one is given', () => {
    const pois = [
        { id: 'near', lat: 45.001, lon: 25.001 },
        { id: 'far', lat: 45.2, lon: 25.2 },
        { id: 'broken', lat: null, lon: 25 },
    ];
    assert.deepEqual(filterByRadius(pois, 45, 25, 1000).map((p) => p.id), ['near']);
    assert.deepEqual(filterByRadius(pois, 45, 25, 1000, square).map((p) => p.id), ['near', 'far']);
});

test('direction of travel and short distances', () => {
    assert.ok(Math.abs(bearingBetween({ lat: 45, lon: 25 }, { lat: 45.001, lon: 25 })) < 1e-9, 'north is 0');
    assert.ok(Math.abs(bearingBetween({ lat: 45, lon: 25 }, { lat: 45, lon: 25.001 }) - Math.PI / 2) < 1e-6, 'east is π/2');
    assert.ok(Math.abs(approximateDistanceMeters({ lat: 45, lon: 25 }, { lat: 45.001, lon: 25 }) - 111.32) < 0.01);
});
