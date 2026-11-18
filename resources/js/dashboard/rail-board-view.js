/**
 * The trains of a railway station in the right panel: live departures/arrivals (Transitous) and the
 * published Romanian annual timetable as fallback. Each row opens the trip details card.
 */
import { fetchJson } from '../http.js';
import { intlLocale, t } from '../i18n.js';
import { boardTime, delayStatus, trainLabel } from '../rail-board.js';
import { safeExternalUrl } from '../safe-url.js';
import { annualTrainCard, trainCard } from '../trip-card.js';

/**
 * @param {{list: HTMLElement, tripRow: Function, tripRowArrow: Function}} view the stop list and the row helpers of the trip card
 */
export function createRailBoardView({ list, tripRow, tripRowArrow }) {
    /** Published Romanian annual timetable (data.gov.ro): the fallback of the live station board. */
    const renderAnnualTimetable = (container, timetable, stationName = '') => {
        const services = Array.isArray(timetable?.services) ? timetable.services : [];
        if (services.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'dashboard-stop-routes-empty';
            empty.textContent = timetable?.available === false
                ? t('Railway timetables could not be loaded from data.gov.ro.')
                : t('No more departures scheduled today after the current time in the annual timetable.');
            container.append(empty);
            return;
        }

        const heading = document.createElement('p');
        heading.className = 'dashboard-stop-routes-stop dashboard-train-services-heading';
        heading.textContent = t('Remaining departures today');
        container.append(heading);

        services.forEach((service) => {
            const item = tripRow('dashboard-stop-route-option dashboard-train-service', () => annualTrainCard(service, stationName));
            const badge = document.createElement('span');
            badge.className = 'dashboard-stop-route-badge';
            badge.textContent = service.category || t('Train');
            const copy = document.createElement('span');
            copy.className = 'dashboard-stop-route-copy';
            const name = document.createElement('strong');
            name.textContent = t('Line :line', { line: `${service.category || t('Train')} ${service.number || ''}`.trim() });
            const details = [
                service.origin ? t('from :place', { place: service.origin }) : null,
                service.destination ? t('to :place', { place: service.destination }) : service.direction ? t('to :place', { place: service.direction }) : null,
                service.arrival ? t('arrival :time', { time: service.arrival }) : null,
                service.departure ? t('departure :time', { time: service.departure }) : null,
                service.operator || null,
                service.service_days || null,
            ].filter(Boolean);
            const description = document.createElement('small');
            description.textContent = details.join(' · ') || t('Scheduled rail service');
            copy.append(name, description);
            item.append(badge, copy);
            item.append(tripRowArrow());
            container.append(item);
        });

        const source = document.createElement('small');
        source.className = 'dashboard-train-services-source';
        source.textContent = `${t('Source: :source · annual timetable', { source: timetable.source || 'data.gov.ro' })}${timetable.valid_until ? `, ${t('valid until :date', { date: timetable.valid_until })}` : ''}${timetable.available === false ? ` · ${t('some timetables could not be loaded')}` : ''}`;
        container.append(source);
    };

    const renderLiveTrains = (container, live, type) => {
        const timeZone = live.station?.timezone || null;
        live.items.forEach((entry) => {
            const item = tripRow('dashboard-stop-route-option dashboard-train-service dashboard-rail-entry', () => trainCard(
                entry, type, live.station?.name || '', (iso) => boardTime(iso, timeZone, intlLocale),
            ));
            const status = delayStatus(entry);
            if (status.state === 'cancelled') item.classList.add('is-cancelled');

            const badge = document.createElement('span');
            badge.className = 'dashboard-stop-route-badge';
            badge.textContent = boardTime(entry.scheduled_time || entry.time, timeZone, intlLocale);

            const copy = document.createElement('span');
            copy.className = 'dashboard-stop-route-copy';
            const name = document.createElement('strong');
            name.textContent = trainLabel(entry) || t('Train');
            const description = document.createElement('small');
            description.textContent = [
                entry.place ? t(type === 'arrivals' ? 'from :place' : 'to :place', { place: entry.place }) : null,
                entry.track ? t('Track :track', { track: entry.track }) : null,
                entry.operator || null,
            ].filter(Boolean).join(' · ');
            copy.append(name, description);

            const state = document.createElement('small');
            state.className = `dashboard-rail-status is-${status.state}`;
            state.textContent = status.state === 'cancelled' ? t('Cancelled')
                : status.state === 'late' ? t('+:minutes min, :time', { minutes: status.minutes, time: boardTime(entry.time, timeZone, intlLocale) })
                    : status.state === 'early' ? t(':minutes min early', { minutes: status.minutes })
                        : status.state === 'on_time' ? t('On time')
                            : t('Scheduled');

            item.append(badge, copy, state, tripRowArrow());
            container.append(item);
        });
    };

    let railBoardRun = 0;

    /** Departures/arrivals tabs of a railway station, loaded from /api/transit/rail-board. */
    const renderRailBoard = (stationName, coordinates) => {
        const lat = Number(coordinates?.lat);
        const lon = Number(coordinates?.lon);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

        const board = document.createElement('section');
        board.className = 'dashboard-rail-board';
        board.setAttribute('aria-label', t('Trains at :station', { station: stationName || t('the station') }));

        const toolbar = document.createElement('div');
        toolbar.className = 'dashboard-rail-tabs';
        toolbar.setAttribute('role', 'tablist');
        const results = document.createElement('div');
        results.className = 'dashboard-rail-results';
        results.setAttribute('aria-live', 'polite');

        const tabs = {};
        const load = async (type) => {
            const run = ++railBoardRun;
            Object.entries(tabs).forEach(([key, tab]) => tab.setAttribute('aria-selected', String(key === type)));
            const loading = document.createElement('p');
            loading.className = 'dashboard-stop-routes-empty';
            loading.textContent = t('Loading trains...');
            results.replaceChildren(loading);

            let data;
            try {
                const query = new URLSearchParams({ lat: String(lat), lon: String(lon), type });
                if (stationName) query.set('name', stationName);
                data = await fetchJson(`/api/transit/rail-board?${query}`);
            } catch (error) {
                if (run !== railBoardRun) return;
                const failed = document.createElement('p');
                failed.className = 'dashboard-stop-routes-empty';
                failed.textContent = error?.message || t('The train data could not be loaded.');
                results.replaceChildren(failed);
                return;
            }
            if (run !== railBoardRun || !board.isConnected) return;

            results.replaceChildren();
            const live = data?.live;
            if (live && Array.isArray(live.items) && live.items.length > 0) {
                renderLiveTrains(results, live, type);
            } else if (live) {
                const none = document.createElement('p');
                none.className = 'dashboard-stop-routes-empty';
                none.textContent = type === 'arrivals' ? t('No train arrivals in the next hours.') : t('No train departures in the next hours.');
                results.append(none);
            } else if (data?.live_failed) {
                const failed = document.createElement('p');
                failed.className = 'dashboard-stop-routes-empty';
                failed.textContent = t('Live train data is not available right now.');
                results.append(failed);
            }

            if (data?.timetable) {
                renderAnnualTimetable(results, data.timetable, stationName);
            } else if (!live && !data?.live_failed) {
                const unknown = document.createElement('p');
                unknown.className = 'dashboard-stop-routes-empty';
                unknown.textContent = t('No train data was found for this station.');
                results.append(unknown);
            }

            if (live) {
                const source = document.createElement('small');
                source.className = 'dashboard-train-services-source';
                source.append(`${t('Live data: :source', { source: live.source })} · `);
                const sources = document.createElement('a');
                const attributionUrl = safeExternalUrl(live.attribution_url);
                if (attributionUrl) {
                    sources.href = attributionUrl;
                    sources.target = '_blank';
                    sources.rel = 'noopener noreferrer';
                    sources.textContent = t('data sources');
                    source.append(sources, ' · © OpenStreetMap');
                }
                results.append(source);
            }
        };

        [['departures', t('Departures')], ['arrivals', t('Arrivals')]].forEach(([type, label]) => {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'dashboard-rail-tab';
            tab.setAttribute('role', 'tab');
            tab.textContent = label;
            tab.addEventListener('click', () => load(type));
            tabs[type] = tab;
            toolbar.append(tab);
        });

        const refresh = document.createElement('button');
        refresh.type = 'button';
        refresh.className = 'dashboard-rail-refresh';
        refresh.setAttribute('aria-label', t('Refresh'));
        refresh.title = t('Refresh');
        const refreshIcon = document.createElement('i');
        refreshIcon.className = 'fa-solid fa-rotate-right';
        refreshIcon.setAttribute('aria-hidden', 'true');
        refresh.append(refreshIcon);
        refresh.addEventListener('click', () => {
            const selected = Object.keys(tabs).find((key) => tabs[key].getAttribute('aria-selected') === 'true') || 'departures';
            load(selected);
        });
        toolbar.append(refresh);

        board.append(toolbar, results);
        list.append(board);
        load('departures');
    };

    return { renderRailBoard };
}
