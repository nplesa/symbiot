import assert from 'node:assert/strict';
import test from 'node:test';

import { MAX_POLL_DURATION_MS, pollDelayMs } from '../resources/js/transit-polling.js';

test('polls every 2 s in the first minute so progress feels live', () => {
    assert.equal(pollDelayMs(0), 2000);
    assert.equal(pollDelayMs(59_999), 2000);
});

test('slows down for long imports', () => {
    assert.equal(pollDelayMs(60_000), 5000);
    assert.equal(pollDelayMs(5 * 60_000 - 1), 5000);
    assert.equal(pollDelayMs(5 * 60_000), 15000);
    assert.equal(pollDelayMs(MAX_POLL_DURATION_MS - 1), 15000);
});

test('stops after 30 minutes instead of polling forever', () => {
    assert.equal(MAX_POLL_DURATION_MS, 30 * 60_000);
    assert.equal(pollDelayMs(MAX_POLL_DURATION_MS), null);
    assert.equal(pollDelayMs(24 * 60 * 60_000), null);
});

test('a 30 minute import makes far fewer requests than the old fixed 2 s interval', () => {
    let elapsed = 0;
    let polls = 0;
    for (let delay = pollDelayMs(elapsed); delay !== null; delay = pollDelayMs(elapsed)) {
        polls++;
        elapsed += delay;
    }

    // Old behaviour: 30 min / 2 s = 900 polls (and then it kept going).
    assert.ok(polls < 200, `expected fewer than 200 polls, got ${polls}`);
});
