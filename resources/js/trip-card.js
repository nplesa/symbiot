/**
 * Content of the trip details card (right panel): what kind of transport, where it comes from and goes to,
 * when it is at the stop, mode-specific details and where to buy a ticket. Free of DOM so it can be
 * unit-tested; pages/dashboard.js renders the returned model.
 *
 * Ticket links are third-party pages: only http(s) URLs (safeExternalUrl) are passed on.
 */
import { t } from './i18n.js';
import { safeExternalUrl } from './safe-url.js';
import { delayStatus, trainLabel } from './rail-board.js';

/**
 * @typedef {{label: string, value: string}} CardRow
 * @typedef {{text: string, url: string, label: string}} CardTicket
 * @typedef {{
 *   mode: string, icon: string, modeLabel: string,
 *   badge: string, title: string, subtitle: string,
 *   from: string|null, to: string|null,
 *   status: {text: string, state: string}|null,
 *   rows: CardRow[], tickets: CardTicket[], notes: string[], emptyTickets: string,
 * }} TripCard
 */

const MODES = {
    plane: { icon: 'fa-plane', label: () => t('Plane') },
    train: { icon: 'fa-train', label: () => t('Train') },
    bus: { icon: 'fa-bus', label: () => t('Bus') },
    trolleybus: { icon: 'fa-bus-simple', label: () => t('Trolleybus') },
    tram: { icon: 'fa-train-tram', label: () => t('Tram') },
    metro: { icon: 'fa-train-subway', label: () => t('Metro') },
    ferry: { icon: 'fa-ferry', label: () => t('Ferry') },
    transit: { icon: 'fa-route', label: () => t('Public transport') },
};

/** Transport mode of a GTFS route_type (basic and extended types). */
export function gtfsMode(routeType) {
    const type = Number(routeType);
    if (type === 0 || type === 5 || (type >= 900 && type < 1000)) return 'tram';
    if (type === 1 || (type >= 400 && type < 500)) return 'metro';
    if (type === 2 || (type >= 100 && type < 200)) return 'train';
    if (type === 3 || (type >= 200 && type < 300) || (type >= 700 && type < 800)) return 'bus';
    if (type === 11 || type === 800) return 'trolleybus';
    if (type === 4 || type === 1200) return 'ferry';
    if (type >= 1100 && type < 1200) return 'plane';

    return 'transit';
}

/** Icon and translated label of a mode (shown in capitals by the card). */
export function modeInfo(mode) {
    const info = MODES[mode] ?? MODES.transit;

    return { icon: info.icon, label: info.label() };
}

/**
 * A ticket link, or null when the URL is not a plain web address.
 *
 * @param {string|null|undefined} url
 * @param {string|null|undefined} operator
 * @param {boolean} farePage true when the URL is the operator's own ticket page
 * @returns {CardTicket|null}
 */
export function ticketLink(url, operator, farePage = false) {
    const href = safeExternalUrl(url);
    if (!href) return null;
    const name = String(operator ?? '').trim();
    const text = farePage || !name
        ? (name ? t('Buy ticket – :operator', { operator: name }) : t('Buy ticket'))
        : t('Tickets on the :operator website', { operator: name });

    return { text, url: href, label: t('Opens the site of :operator in a new tab', { operator: name || href }) };
}

/**
 * Ticket links and notes of a GTFS line: its own operator when the line names one, else every
 * operator of the stop (routes.txt may leave agency_id empty when a feed has a single agency).
 *
 * @param {{operators?: Array<{agency_id?: string|null, name: string, url: string, fare_page?: boolean}>, contactless?: boolean, apps?: string[]}|null} tickets
 * @param {string|null|undefined} agencyId
 * @returns {{tickets: CardTicket[], notes: string[]}}
 */
export function gtfsTickets(tickets, agencyId) {
    const operators = Array.isArray(tickets?.operators) ? tickets.operators : [];
    const own = agencyId ? operators.filter((operator) => operator.agency_id === agencyId) : [];
    const links = (own.length > 0 ? own : operators)
        .map((operator) => ticketLink(operator.url, operator.name, Boolean(operator.fare_page)))
        .filter(Boolean);
    const notes = [
        tickets?.contactless ? t('You can pay with a contactless bank card.') : null,
        ...(Array.isArray(tickets?.apps) ? tickets.apps : []).map((app) => t('Tickets in the :app app', { app })),
    ].filter(Boolean);

    return { tickets: links, notes };
}

const RAIL_CATEGORIES = {
    HIGHSPEED_RAIL: () => t('High-speed train'),
    LONG_DISTANCE: () => t('Long-distance train'),
    NIGHT_RAIL: () => t('Night train'),
    REGIONAL_FAST_RAIL: () => t('Fast regional train'),
    REGIONAL_RAIL: () => t('Regional train'),
};

const row = (label, value) => (value === null || value === undefined || value === '' ? null : { label, value: String(value) });

function card(mode, fields) {
    const info = modeInfo(mode);

    return {
        mode,
        icon: info.icon,
        modeLabel: info.label,
        badge: '',
        title: '',
        subtitle: '',
        from: null,
        to: null,
        status: null,
        notes: [],
        tickets: [],
        emptyTickets: t('No ticket link is published for this trip.'),
        ...fields,
        rows: (fields.rows ?? []).filter(Boolean),
    };
}

/**
 * A train of the live station board (Transitous).
 *
 * @param {object} entry board entry from /api/transit/rail-board
 * @param {'departures'|'arrivals'} type
 * @param {string} stationName
 * @param {(iso: string|null) => string} timeOf formats a time in the station's time zone
 * @returns {TripCard}
 */
export function trainCard(entry, type, stationName, timeOf) {
    const arrivals = type === 'arrivals';
    const status = delayStatus(entry);
    const estimatedDiffers = entry.realtime && entry.time && entry.time !== entry.scheduled_time;
    const trackChanged = entry.track && entry.scheduled_track && entry.track !== entry.scheduled_track;
    const ticket = ticketLink(entry.operator_url, entry.operator);

    return card('train', {
        badge: entry.number || trainLabel(entry) || t('Train'),
        title: trainLabel(entry) || t('Train'),
        subtitle: entry.operator || '',
        from: arrivals ? entry.place : stationName,
        to: arrivals ? stationName : entry.place,
        status: statusText(status, timeOf(entry.time)),
        rows: [
            row(arrivals ? t('Scheduled arrival') : t('Scheduled departure'), timeOf(entry.scheduled_time || entry.time)),
            estimatedDiffers ? row(arrivals ? t('Estimated arrival') : t('Estimated departure'), timeOf(entry.time)) : null,
            status.state === 'late' ? row(t('Delay'), t('+:minutes min', { minutes: status.minutes })) : null,
            row(t('Track'), trackChanged ? t(':track (changed from :old)', { track: entry.track, old: entry.scheduled_track }) : entry.track),
            row(t('Train type'), RAIL_CATEGORIES[entry.category]?.() ?? null),
            row(t('Train number'), entry.number),
            row(t('Operator'), entry.operator),
            row(t('Data'), entry.realtime ? t('Real time') : t('Scheduled timetable')),
        ],
        tickets: ticket ? [ticket] : [],
    });
}

/**
 * A train of the published Romanian annual timetable (data.gov.ro).
 *
 * @param {object} service
 * @param {string} stationName
 * @returns {TripCard}
 */
export function annualTrainCard(service, stationName) {
    const name = `${service.category || t('Train')} ${service.number || ''}`.trim();
    const ticket = ticketLink(service.ticket_url, service.operator, true);

    return card('train', {
        badge: service.category || t('Train'),
        title: name,
        subtitle: service.operator || '',
        from: service.origin || null,
        to: service.destination || service.direction || null,
        status: { text: t('Scheduled timetable'), state: 'scheduled' },
        rows: [
            row(t('Station'), stationName),
            row(t('Arrival in the station'), service.arrival),
            row(t('Departure from the station'), service.departure),
            row(t('Train number'), service.number),
            row(t('Runs on'), service.service_days),
            row(t('Operator'), service.operator),
        ],
        tickets: ticket ? [ticket] : [],
    });
}

/**
 * A flight of the airport board.
 *
 * @param {object} flight normalized FlightAware flight
 * @param {'arrival'|'departure'} direction
 * @param {string} airportName
 * @param {(iso: string|null) => string} timeOf formats a time in the airport's time zone
 * @returns {TripCard}
 */
export function flightCard(flight, direction, airportName, timeOf) {
    const arrival = direction === 'arrival';
    const place = (name, code) => (name && code ? `${name} (${code})` : name || code || null);
    const scheduled = Date.parse(flight.scheduled_time || '');
    const estimated = Date.parse(flight.estimated_time || '');
    const delay = Number.isFinite(scheduled) && Number.isFinite(estimated) ? Math.round((estimated - scheduled) / 60000) : 0;
    const airline = flight.operator_name || flight.operator || '';
    const ticket = ticketLink(flight.operator_url, airline);

    let status = { text: flight.status || (flight.estimated_time ? t('Estimated') : t('Scheduled')), state: 'scheduled' };
    if (flight.cancelled) status = { text: t('Cancelled'), state: 'cancelled' };
    else if (flight.diverted) status = { text: t('Diverted'), state: 'late' };
    else if (delay > 0) status = { text: `${status.text} · ${t('+:minutes min', { minutes: delay })}`, state: 'late' };

    return card('plane', {
        badge: flight.number || t('Flight'),
        title: t('Flight :number', { number: flight.number || '—' }),
        subtitle: airline,
        from: arrival ? place(flight.origin_name, flight.origin) : airportName,
        to: arrival ? airportName : place(flight.destination_name, flight.destination),
        status,
        rows: [
            row(arrival ? t('Scheduled arrival') : t('Scheduled departure'), flight.scheduled_time ? timeOf(flight.scheduled_time) : null),
            flight.estimated_time && flight.estimated_time !== flight.scheduled_time
                ? row(arrival ? t('Estimated arrival') : t('Estimated departure'), timeOf(flight.estimated_time)) : null,
            row(arrival ? t('Landed at') : t('Took off at'), flight.actual_time ? timeOf(flight.actual_time) : null),
            row(t('Terminal'), flight.terminal),
            row(t('Gate'), flight.gate),
            arrival ? row(t('Baggage belt'), flight.baggage_claim) : null,
            row(t('Airline'), airline),
        ],
        tickets: ticket ? [ticket] : [],
    });
}

/** Transport mode of an OSM route relation ("route" tag), or of its label when an older payload lacks the tag. */
export function osmMode(route) {
    const byTag = {
        bus: 'bus', trolleybus: 'trolleybus', subway: 'metro', monorail: 'metro', train: 'train', light_rail: 'train',
        tram: 'tram', airline: 'plane', ferry: 'ferry',
    }[route?.mode ?? ''];
    if (byTag) return byTag;

    const label = String(route?.label ?? '').toLowerCase();
    const prefixes = [['troleibuz', 'trolleybus'], ['autobuz', 'bus'], ['metrou', 'metro'], ['tramvai', 'tram'], ['tren', 'train'], ['companie aerian', 'plane']];

    return prefixes.find(([prefix]) => label.startsWith(prefix))?.[1] ?? 'transit';
}

/**
 * A line of an OpenStreetMap-only stop: route relations have no timetable, only what the map
 * lists (ends, operator, frequency, hours). A group may hold both directions of the line.
 *
 * @param {{label: string, directionLabel?: string, routes: object[]}} group
 * @param {string} stopName
 * @returns {TripCard}
 */
export function osmRouteCard(group, stopName) {
    const routes = Array.isArray(group?.routes) ? group.routes : [];
    const first = routes[0] ?? {};
    const pick = (key) => routes.map((route) => route[key]).find((value) => typeof value === 'string' && value.trim() !== '') ?? null;
    const ref = pick('ref') || String(group?.label ?? '').replace(/^\S+\s+/, '') || t('Line');
    const directions = [...new Set(routes.map((route) => route.direction).filter(Boolean))];
    const updated = routes.map((route) => route.updated_at).filter(Boolean).sort().pop() ?? null;
    const inactive = routes.some((route) => route.status === 'possibly_inactive');
    const website = pick('website');
    const operator = pick('operator');
    const ticket = ticketLink(website, operator || pick('network'));

    return card(osmMode(first), {
        badge: ref,
        title: t('Line :line', { line: ref }),
        subtitle: [operator, pick('network')].filter(Boolean).filter((value, index, all) => all.indexOf(value) === index).join(' · '),
        from: first.from ?? null,
        to: first.to ?? null,
        status: inactive
            ? { text: t('The line may no longer run (marked so in OpenStreetMap).'), state: 'late' }
            : { text: t('No timetable: the line is known from OpenStreetMap.'), state: 'scheduled' },
        rows: [
            row(t('Stop'), stopName),
            row(t('Route name'), pick('name')),
            directions.length > 1 ? row(t('Directions'), directions.join(' · ')) : null,
            row(t('Operator'), operator),
            row(t('Network'), pick('network')),
            row(t('Frequency'), pick('interval')),
            row(t('Operating hours'), pick('opening_hours')),
            row(t('Data'), 'OpenStreetMap'),
            row(t('Last updated'), updated ? String(updated).slice(0, 10) : null),
        ],
        tickets: ticket ? [ticket] : [],
        emptyTickets: t('OpenStreetMap does not list a ticket link for this line.'),
    });
}

function statusText(status, liveTime) {
    switch (status.state) {
        case 'cancelled': return { text: t('Cancelled'), state: 'cancelled' };
        case 'late': return { text: t('+:minutes min, :time', { minutes: status.minutes, time: liveTime }), state: 'late' };
        case 'early': return { text: t(':minutes min early', { minutes: status.minutes }), state: 'early' };
        case 'on_time': return { text: t('On time'), state: 'on_time' };
        default: return { text: t('Scheduled'), state: 'scheduled' };
    }
}
