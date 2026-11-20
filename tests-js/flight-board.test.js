import test from 'node:test';
import assert from 'node:assert/strict';
import { flightStatus, upcomingFlights } from '../resources/js/flight-board.js';

test('cancelled and diverted flights say so first', () => {
    assert.deepEqual(flightStatus({ cancelled: true, estimated_time: '2026-11-20T10:30:00Z', scheduled_time: '2026-11-20T10:00:00Z' }), { state: 'cancelled', minutes: 0 });
    assert.deepEqual(flightStatus({ diverted: true }), { state: 'diverted', minutes: 0 });
});

test('the delay is the estimate against the schedule', () => {
    assert.deepEqual(flightStatus({ scheduled_time: '2026-11-20T10:00:00Z', estimated_time: '2026-11-20T10:15:00Z' }), { state: 'late', minutes: 15 });
    assert.deepEqual(flightStatus({ scheduled_time: '2026-11-20T10:00:00Z', estimated_time: '2026-11-20T09:52:00Z' }), { state: 'early', minutes: 8 });
    assert.deepEqual(flightStatus({ scheduled_time: '2026-11-20T10:00:00Z', estimated_time: '2026-11-20T10:00:00Z' }), { state: 'on_time', minutes: 0 });
    // A landed flight: its actual time.
    assert.deepEqual(flightStatus({ scheduled_time: '2026-11-20T10:00:00Z', actual_time: '2026-11-20T10:05:00Z' }), { state: 'late', minutes: 5 });
});

test('without an estimate: the source status, else scheduled', () => {
    assert.deepEqual(flightStatus({ scheduled_time: '2026-11-20T10:00:00Z', status: 'Boarding' }), { state: 'status', minutes: 0, text: 'Boarding' });
    assert.deepEqual(flightStatus({ scheduled_time: '2026-11-20T10:00:00Z' }), { state: 'scheduled', minutes: 0 });
});

test('upcoming flights: from now on, the soonest first, the estimate counting', () => {
    const now = Date.parse('2026-11-20T10:00:00Z');
    const flights = [
        { number: 'late', scheduled_time: '2026-11-20T09:50:00Z', estimated_time: '2026-11-20T10:20:00Z' },
        { number: 'gone', scheduled_time: '2026-11-20T09:30:00Z' },
        { number: 'soon', scheduled_time: '2026-11-20T10:10:00Z' },
        { number: 'no time' },
    ];
    assert.deepEqual(upcomingFlights(flights, now).map((flight) => flight.number), ['soon', 'late']);
    assert.deepEqual(upcomingFlights(null, now), []);
});
