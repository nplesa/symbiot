/**
 * The airport board (departures / arrivals), as the station board does it for trains: which flights are
 * still to come and what the status column says.
 */

/** When the flight leaves or lands as known now: the estimate, else the schedule, else the actual time. */
export function flightMoment(flight) {
    return Date.parse(flight?.estimated_time || flight?.scheduled_time || flight?.actual_time || '');
}

/**
 * The flights from now on, the soonest first.
 *
 * @param {Array<object>} flights
 * @param {number} now milliseconds
 */
export function upcomingFlights(flights, now = Date.now()) {
    return (Array.isArray(flights) ? flights : [])
        .filter((flight) => {
            const moment = flightMoment(flight);
            return Number.isFinite(moment) && moment >= now;
        })
        .sort((a, b) => flightMoment(a) - flightMoment(b));
}

/**
 * The status column: cancelled / diverted, or the delay of the estimate on the schedule (late, early,
 * on time), else the source's own status ("Landed"), else scheduled.
 *
 * @returns {{state: 'cancelled'|'diverted'|'late'|'early'|'on_time'|'status'|'scheduled', minutes: number, text?: string}}
 */
export function flightStatus(flight) {
    if (flight?.cancelled) return { state: 'cancelled', minutes: 0 };
    if (flight?.diverted) return { state: 'diverted', minutes: 0 };

    const scheduled = Date.parse(flight?.scheduled_time || '');
    const expected = Date.parse(flight?.estimated_time || flight?.actual_time || '');
    if (Number.isFinite(scheduled) && Number.isFinite(expected)) {
        const minutes = Math.round((expected - scheduled) / 60000);
        if (minutes > 0) return { state: 'late', minutes };
        if (minutes < 0) return { state: 'early', minutes: -minutes };
        return { state: 'on_time', minutes: 0 };
    }

    const text = String(flight?.status ?? '').trim();
    if (text) return { state: 'status', minutes: 0, text };

    return { state: 'scheduled', minutes: 0 };
}
