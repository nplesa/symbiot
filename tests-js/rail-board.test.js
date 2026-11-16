import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boardTime, delayStatus, trainLabel } from '../resources/js/rail-board.js';

test('times are shown in the station time zone', () => {
    // 13:38 UTC is 14:38 in Berlin and 15:38 in Bucharest (winter time).
    assert.equal(boardTime('2026-11-16T13:38:00Z', 'Europe/Berlin', 'en-GB'), '14:38');
    assert.equal(boardTime('2026-11-16T13:38:00Z', 'Europe/Bucharest', 'en-GB'), '15:38');
});

test('a missing, invalid or unknown time zone does not break the board', () => {
    assert.equal(boardTime(null, 'Europe/Berlin', 'en-GB'), '—');
    assert.equal(boardTime('not a date', 'Europe/Berlin', 'en-GB'), '—');
    assert.match(boardTime('2026-11-16T13:38:00Z', 'Not/AZone', 'en-GB'), /^\d{2}:\d{2}$/);
});

test('delay status: cancelled, late, early, on time and scheduled-only trains', () => {
    assert.deepEqual(delayStatus({ cancelled: true, realtime: true, delay_minutes: 5 }), { state: 'cancelled', minutes: 0 });
    assert.deepEqual(delayStatus({ realtime: true, delay_minutes: 5 }), { state: 'late', minutes: 5 });
    assert.deepEqual(delayStatus({ realtime: true, delay_minutes: -2 }), { state: 'early', minutes: 2 });
    assert.deepEqual(delayStatus({ realtime: true, delay_minutes: 0 }), { state: 'on_time', minutes: 0 });
    assert.deepEqual(delayStatus({ realtime: false, delay_minutes: null }), { state: 'scheduled', minutes: 0 });
});

test('train labels do not repeat the train number', () => {
    assert.equal(trainLabel({ train: 'ICE 856', number: '856' }), 'ICE 856');
    assert.equal(trainLabel({ train: 'FEX (21891)', number: '021891' }), 'FEX (21891)');
    assert.equal(trainLabel({ train: 'R', number: '11240' }), 'R 11240');
    assert.equal(trainLabel({ train: 'RE3', number: null }), 'RE3');
    assert.equal(trainLabel({ train: '', number: '4513' }), '4513');
});
