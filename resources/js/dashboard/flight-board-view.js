/**
 * The flights of an airport in the right panel, laid out like a railway station's board: "Departures /
 * Arrivals" tabs with a refresh button, one list at a time, the time, the flight, where to or from, and a
 * coloured status. Each row opens the trip details card.
 */
import { fetchJson } from '../http.js';
import { intlLocale, t } from '../i18n.js';
import { flightStatus, upcomingFlights } from '../flight-board.js';
import { boardTime } from '../rail-board.js';
import { flightCard } from '../trip-card.js';
import { createBoardTabs } from './board-tabs.js';

/**
 * @param {{list: HTMLElement, tripRow: Function, tripRowArrow: Function}} view the stop list and the row helpers of the trip card
 */
export function createFlightBoardView({ list, tripRow, tripRowArrow }) {
    let flightBoardRun = 0;

    const message = (text) => {
        const paragraph = document.createElement('p');
        paragraph.className = 'dashboard-stop-routes-empty';
        paragraph.textContent = text;
        return paragraph;
    };

    /** One flight row: scheduled time, number, where to/from and details, coloured status. */
    const flightRow = (flight, direction, airportName) => {
        const timeZone = flight.airport_timezone || undefined;
        const time = (iso) => boardTime(iso, timeZone, intlLocale);
        const item = tripRow('dashboard-stop-route-option dashboard-train-service dashboard-rail-entry', () => flightCard(
            flight, direction, airportName || t('Airport'), time,
        ));
        const status = flightStatus(flight);
        if (status.state === 'cancelled') item.classList.add('is-cancelled');

        const badge = document.createElement('span');
        badge.className = 'dashboard-stop-route-badge';
        badge.textContent = time(flight.scheduled_time || flight.estimated_time || flight.actual_time);

        const copy = document.createElement('span');
        copy.className = 'dashboard-stop-route-copy';
        const name = document.createElement('strong');
        name.textContent = flight.number || t('Flight');
        const endpoint = direction === 'arrival'
            ? (flight.origin_name || flight.origin || t('Origin unavailable'))
            : (flight.destination_name || flight.destination || t('Destination unavailable'));
        const description = document.createElement('small');
        description.textContent = [
            t(direction === 'arrival' ? 'from :place' : 'to :place', { place: endpoint }),
            flight.operator_name || flight.operator,
            flight.terminal ? t('Terminal :terminal', { terminal: flight.terminal }) : null,
            flight.gate ? t('Gate :gate', { gate: flight.gate }) : null,
            direction === 'arrival' && flight.baggage_claim ? t('Baggage belt :belt', { belt: flight.baggage_claim }) : null,
        ].filter(Boolean).join(' · ');
        copy.append(name, description);

        const state = document.createElement('small');
        state.className = `dashboard-rail-status is-${status.state}`;
        state.textContent = status.state === 'cancelled' ? t('Cancelled')
            : status.state === 'diverted' ? t('Diverted')
                : status.state === 'late' ? t('+:minutes min, :time', { minutes: status.minutes, time: time(flight.estimated_time || flight.actual_time) })
                    : status.state === 'early' ? t(':minutes min early', { minutes: status.minutes })
                        : status.state === 'on_time' ? t('On time')
                            : status.state === 'status' ? status.text
                                : t('Scheduled');

        item.append(badge, copy, state, tripRowArrow());
        return item;
    };

    /**
     * The airport board under the stop list's heading. flights: the answer already loaded
     * (/api/transport/airport/{code}/flights); the refresh button asks for it again.
     */
    const renderFlightBoard = (flights, airportName, airportCode) => {
        const run = ++flightBoardRun;
        let data = flights;

        const board = document.createElement('section');
        board.className = 'dashboard-rail-board';
        board.setAttribute('aria-label', t('Flights at the airport'));
        const results = document.createElement('div');
        results.className = 'dashboard-rail-results';
        results.setAttribute('aria-live', 'polite');

        const show = (type) => {
            tabs.select(type);
            results.replaceChildren();
            if (!data?.available) {
                results.append(message(data?.message || t('Flight data is not available for this airport.')));
                return;
            }
            const direction = type === 'arrivals' ? 'arrival' : 'departure';
            const upcoming = upcomingFlights(type === 'arrivals' ? data.arrivals : data.departures);
            if (upcoming.length === 0) {
                results.append(message(t('No flights scheduled after the current time in the next 24 hours.')));
            } else {
                upcoming.forEach((flight) => results.append(flightRow(flight, direction, airportName)));
            }
            const source = document.createElement('small');
            source.className = 'dashboard-train-services-source';
            source.textContent = t('Source: :source · updated :time', {
                source: data.source || t('Flight data'),
                time: data.updated_at ? boardTime(data.updated_at, undefined, intlLocale) : t('recently'),
            });
            results.append(source);
        };

        const refresh = async (type) => {
            if (!airportCode) {
                show(type);
                return;
            }
            tabs.select(type);
            results.replaceChildren(message(t('Loading arrivals and departures...')));
            try {
                const answer = await fetchJson(`/api/transport/airport/${encodeURIComponent(airportCode)}/flights`);
                if (run !== flightBoardRun || !board.isConnected) return;
                data = answer;
            } catch {
                if (run !== flightBoardRun || !board.isConnected) return;
                results.replaceChildren(message(t('Could not load the flights for this airport.')));
                return;
            }
            show(type);
        };

        const tabs = createBoardTabs({
            tabs: [['departures', t('Departures')], ['arrivals', t('Arrivals')]],
            onSelect: show,
            onRefresh: refresh,
        });

        board.append(tabs.element, results);
        list.append(board);
        show('departures');
    };

    return { renderFlightBoard };
}
