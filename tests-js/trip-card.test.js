import { test } from 'node:test';
import assert from 'node:assert/strict';
import { annualTrainCard, flightCard, gtfsMode, gtfsTickets, osmMode, osmRouteCard, ticketLink, trainCard } from '../resources/js/trip-card.js';

const hhmm = (iso) => (iso ? new Date(iso).toISOString().slice(11, 16) : '—');
const fact = (card, label) => card.rows.find((row) => row.label === label)?.value;

test('GTFS route types map to the transport mode shown in the card', () => {
    assert.equal(gtfsMode(3), 'bus');
    assert.equal(gtfsMode(0), 'tram');
    assert.equal(gtfsMode(1), 'metro');
    assert.equal(gtfsMode(11), 'trolleybus');
    assert.equal(gtfsMode(2), 'train');
    assert.equal(gtfsMode(109), 'train');
    assert.equal(gtfsMode(700), 'bus');
    assert.equal(gtfsMode(900), 'tram');
    assert.equal(gtfsMode(401), 'metro');
    assert.equal(gtfsMode(7), 'transit');
});

test('ticket links accept only web addresses', () => {
    assert.equal(ticketLink('javascript:alert(1)', 'X'), null);
    assert.equal(ticketLink(null, 'X'), null);
    assert.deepEqual(ticketLink('https://stb.test/bilete', 'STB', true), {
        text: 'Buy ticket – STB', url: 'https://stb.test/bilete', label: 'Opens the site of STB in a new tab',
    });
    assert.equal(ticketLink('https://www.bahn.de', 'DB').text, 'Tickets on the DB website');
});

test('a GTFS line shows the tickets of its own operator, else of every operator at the stop', () => {
    const tickets = {
        operators: [
            { agency_id: 'STB', name: 'STB', url: 'https://stb.test/bilete', fare_page: true },
            { agency_id: 'MET', name: 'Metrorex', url: 'https://metrorex.test', fare_page: false },
        ],
        contactless: true,
        apps: ['24pay'],
    };

    assert.deepEqual(gtfsTickets(tickets, 'MET').tickets.map((ticket) => ticket.url), ['https://metrorex.test/']);
    assert.equal(gtfsTickets(tickets, null).tickets.length, 2);
    assert.deepEqual(gtfsTickets(tickets, 'MET').notes, ['You can pay with a contactless bank card.', 'Tickets in the :app app'.replace(':app', '24pay')]);
    assert.deepEqual(gtfsTickets(null, 'MET'), { tickets: [], notes: [] });
});

test('a late live train: route, delay, changed track and the operator ticket link', () => {
    const card = trainCard({
        time: '2026-11-16T13:43:00Z', scheduled_time: '2026-11-16T13:38:00Z', delay_minutes: 5, realtime: true, cancelled: false,
        train: 'ICE 856', number: '856', category: 'HIGHSPEED_RAIL', place: 'Köln Hbf', track: '8', scheduled_track: '7',
        operator: 'DB Fernverkehr AG', operator_url: 'https://www.bahn.de',
    }, 'departures', 'Berlin Hbf', hhmm);

    assert.equal(card.mode, 'train');
    assert.equal(card.modeLabel, 'Train');
    assert.equal(card.from, 'Berlin Hbf');
    assert.equal(card.to, 'Köln Hbf');
    assert.equal(card.status.state, 'late');
    assert.equal(fact(card, 'Scheduled departure'), '13:38');
    assert.equal(fact(card, 'Estimated departure'), '13:43');
    assert.equal(fact(card, 'Delay'), '+5 min');
    assert.equal(fact(card, 'Track'), '8 (changed from 7)');
    assert.equal(fact(card, 'Train type'), 'High-speed train');
    assert.equal(card.tickets[0].url, 'https://www.bahn.de/');
});

test('an arriving train comes from its origin to the station; no link means no ticket', () => {
    const card = trainCard({ time: null, scheduled_time: '2026-11-16T13:29:00Z', realtime: false, train: 'RE3', place: 'Wittenberg', operator_url: null }, 'arrivals', 'Berlin Hbf', hhmm);

    assert.equal(card.from, 'Wittenberg');
    assert.equal(card.to, 'Berlin Hbf');
    assert.equal(fact(card, 'Scheduled arrival'), '13:29');
    assert.equal(fact(card, 'Estimated arrival'), undefined);
    assert.equal(fact(card, 'Data'), 'Scheduled timetable');
    assert.deepEqual(card.tickets, []);
});

test('an annual-timetable train keeps the CFR ticket link and its running days', () => {
    const card = annualTrainCard({
        category: 'IR', number: '1745', origin: 'București Nord', destination: 'Brașov', arrival: '10:02', departure: '10:05',
        operator: 'CFR Călători', service_days: 'zilnic', ticket_url: 'https://bilete.cfrcalatori.ro/',
    }, 'Ploiești Vest');

    assert.equal(card.title, 'IR 1745');
    assert.equal(card.from, 'București Nord');
    assert.equal(card.to, 'Brașov');
    assert.equal(fact(card, 'Station'), 'Ploiești Vest');
    assert.equal(fact(card, 'Runs on'), 'zilnic');
    assert.equal(card.tickets[0].text, 'Buy ticket – CFR Călători');
});

test('a delayed arriving flight: airports, times, terminal, gate, belt and the airline site', () => {
    const card = flightCard({
        number: 'RO301', operator: 'RO', operator_name: 'TAROM', operator_url: 'https://www.tarom.ro/',
        origin: 'OTP', origin_name: 'București Henri Coandă', scheduled_time: '2026-11-17T12:35:00Z', estimated_time: '2026-11-17T12:50:00Z',
        terminal: '1', gate: '4', baggage_claim: '2', status: 'Scheduled',
    }, 'arrival', 'Aeroportul Brașov', hhmm);

    assert.equal(card.mode, 'plane');
    assert.equal(card.modeLabel, 'Plane');
    assert.equal(card.from, 'București Henri Coandă (OTP)');
    assert.equal(card.to, 'Aeroportul Brașov');
    assert.equal(card.status.state, 'late');
    assert.match(card.status.text, /\+15 min/);
    assert.equal(fact(card, 'Scheduled arrival'), '12:35');
    assert.equal(fact(card, 'Estimated arrival'), '12:50');
    assert.equal(fact(card, 'Baggage belt'), '2');
    assert.equal(fact(card, 'Airline'), 'TAROM');
    assert.equal(card.tickets[0].text, 'Tickets on the TAROM website');
});

test('a cancelled departing flight has no baggage belt row', () => {
    const card = flightCard({ number: 'W63241', destination: 'FCO', cancelled: true, baggage_claim: '9', scheduled_time: '2026-11-17T15:00:00Z' }, 'departure', 'OTP', hhmm);

    assert.equal(card.status.state, 'cancelled');
    assert.equal(card.to, 'FCO');
    assert.equal(fact(card, 'Baggage belt'), undefined);
    assert.deepEqual(card.tickets, []);
});

test('OSM lines: mode from the route tag, else from the label of older payloads', () => {
    assert.equal(osmMode({ mode: 'trolleybus', label: 'Troleibuz 3' }), 'trolleybus');
    assert.equal(osmMode({ mode: 'subway' }), 'metro');
    assert.equal(osmMode({ label: 'Tramvai 41' }), 'tram');
    assert.equal(osmMode({ label: 'Autobuz 5' }), 'bus');
    assert.equal(osmMode({ label: 'Rută X' }), 'transit');
});

test('an OSM line card lists what the map knows, both directions and its next departure', () => {
    const card = osmRouteCard({
        key: 'autobuz 5',
        label: 'Autobuz 5',
        routes: [
            { mode: 'bus', ref: '5', name: 'Bus 5', from: 'Triaj', to: 'Gară', direction: 'Triaj → Gară', operator: 'RATBV', network: 'RATBV', interval: '00:15', opening_hours: 'Mo-Su 05:00-23:00', website: 'https://ratbv.test', updated_at: '2026-05-01T10:00:00Z', status: 'listed_in_osm' },
            { mode: 'bus', ref: '5', direction: 'Gară → Triaj', updated_at: '2026-06-02T08:56:08Z', status: 'listed_in_osm' },
        ],
    }, 'Livada Poștei');

    assert.equal(card.mode, 'bus');
    assert.equal(card.badge, '5');
    assert.equal(card.title, 'Line 5');
    assert.equal(card.subtitle, 'RATBV');
    assert.equal(card.from, 'Triaj');
    assert.equal(card.to, 'Gară');
    // No status line for an active line: the next departure section says what the timetable has.
    assert.equal(card.status, null);
    assert.deepEqual(card.departure, { routeKey: 'autobuz 5', text: 'Searching for the next departure…' });
    assert.equal(fact(card, 'Stop'), 'Livada Poștei');
    assert.equal(fact(card, 'Directions'), 'Triaj → Gară · Gară → Triaj');
    assert.equal(fact(card, 'Frequency'), '00:15');
    assert.equal(fact(card, 'Last updated'), '2026-06-02');
    assert.equal(card.tickets[0].url, 'https://ratbv.test/');
});

test('an OSM line with only a label still gets a card; an inactive one is flagged', () => {
    const card = osmRouteCard({ label: 'Autobuz 22', routes: [{ label: 'Autobuz 22', status: 'possibly_inactive' }] }, 'Stație');

    assert.equal(card.badge, '22');
    assert.equal(card.from, null);
    assert.equal(card.status.state, 'late');
    assert.deepEqual(card.tickets, []);
    assert.equal(card.emptyTickets, 'OpenStreetMap does not list a ticket link for this line.');
});

test('an OSM line takes its ends from a variant that has both, else from its direction', () => {
    const fromVariant = osmRouteCard({ key: 'm2', label: 'Metrou M2', routes: [
        { mode: 'subway', ref: 'M2', to: 'Tudor Arghezi' },
        { mode: 'subway', ref: 'M2', from: 'Pipera', to: 'Tudor Arghezi', direction: 'Pipera → Tudor Arghezi' },
    ] }, 'Universitate');
    assert.equal(fromVariant.from, 'Pipera');
    assert.equal(fromVariant.to, 'Tudor Arghezi');

    const fromDirection = osmRouteCard({ key: 'm2', label: 'Metrou M2', routes: [
        { mode: 'subway', ref: 'M2', direction: 'Berceni → Pipera' },
    ] }, 'Universitate');
    assert.equal(fromDirection.from, 'Berceni');
    assert.equal(fromDirection.to, 'Pipera');
});

test('a line listed for both directions shows its two ends without a direction', () => {
    const card = osmRouteCard({ key: 'metrou m2', label: 'Metrou M2', routes: [
        { mode: 'subway', ref: 'M2', name: 'Magistrala 2 (Pipera → Tudor Arghezi)', to: 'Tudor Arghezi' },
        { mode: 'subway', ref: 'M2', from: 'Tudor Arghezi', to: 'Pipera', direction: 'Tudor Arghezi → Pipera' },
    ] }, 'Universitate');

    assert.equal(card.bidirectional, true);
    assert.deepEqual([card.from, card.to], ['Tudor Arghezi', 'Pipera']);
});
