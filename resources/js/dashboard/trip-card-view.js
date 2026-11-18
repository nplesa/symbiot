/**
 * The trip details card of the right panel (#dashboard-trip-card): renders a model of trip-card.js in
 * place of the list it was picked from, with "Back to the list". Also the shared pieces of the GTFS line
 * panel: mode chip, from → to, ticket links.
 */
import { t } from '../i18n.js';
import { modeInfo } from '../trip-card.js';
import { scrollPanelTo } from './panel-scroll.js';

/**
 * @param {{stopRoutesPanel: HTMLElement, routeDetails: HTMLElement}} panels the stop list and the GTFS line panel
 */
export function createTripCardView({ stopRoutesPanel, routeDetails }) {
    const tripCard = document.getElementById('dashboard-trip-card');
    const tripCardBody = document.getElementById('dashboard-trip-body');
    // The list row that opened the trip card: focus goes back to it.
    let tripCardTrigger = null;
    // Latest next-departure text per line (OSM lines): the lookup starts with the line and answers later.
    const departures = new Map();

    // A new location: the departures of the previous one's lines are dropped.
    window.addEventListener('symbiot:location-changing', () => departures.clear());
    window.addEventListener('symbiot:location-set', () => departures.clear());

    window.addEventListener('symbiot:osm-route-departure', (event) => {
        const { routeKey, text } = event.detail ?? {};
        if (!routeKey || typeof text !== 'string') return;
        departures.set(routeKey, text);
        tripCard?.querySelectorAll('.dashboard-trip-departure').forEach((section) => {
            if (section.dataset.routeKey !== routeKey) return;
            const paragraph = section.querySelector('p');
            if (paragraph) paragraph.textContent = text;
        });
    });

    const renderModeChip = (element, mode) => {
        if (!element) return;
        const info = modeInfo(mode);
        const icon = document.createElement('i');
        icon.className = `fa-solid ${info.icon}`;
        icon.setAttribute('aria-hidden', 'true');
        const label = document.createElement('span');
        label.textContent = info.label;
        element.dataset.mode = mode;
        element.replaceChildren(icon, label);
    };

    /** From → to; for a line listed for both directions, its two ends (⇄) without a direction. */
    const renderItinerary = (container, from, to, bidirectional = false) => {
        if (!container) return;
        container.replaceChildren();
        container.hidden = !from && !to;
        if (container.hidden) return;
        const end = (label, place) => {
            const wrap = document.createElement('div');
            wrap.className = 'dashboard-trip-end';
            const caption = document.createElement('small');
            caption.textContent = label;
            const name = document.createElement('strong');
            name.textContent = place || '—';
            wrap.append(caption, name);
            return wrap;
        };
        const arrow = document.createElement('i');
        arrow.className = bidirectional ? 'fa-solid fa-arrows-left-right' : 'fa-solid fa-arrow-right';
        arrow.setAttribute('aria-hidden', 'true');
        container.append(
            end(bidirectional ? t('Terminus') : t('From'), from),
            arrow,
            end(bidirectional ? t('Terminus') : t('To'), to),
        );
    };

    /** Ticket links (third-party pages, new tab) and payment notes; the app never sells tickets. */
    const renderTickets = (container, tickets, notes, emptyText) => {
        if (!container) return;
        container.replaceChildren();
        const heading = document.createElement('h3');
        const icon = document.createElement('i');
        icon.className = 'fa-solid fa-ticket';
        icon.setAttribute('aria-hidden', 'true');
        heading.append(icon, ` ${t('Tickets')}`);
        container.append(heading);
        tickets.forEach((ticket) => {
            const link = document.createElement('a');
            link.className = 'dashboard-trip-ticket';
            link.href = ticket.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = ticket.text;
            const external = document.createElement('i');
            external.className = 'fa-solid fa-arrow-up-right-from-square';
            external.setAttribute('aria-hidden', 'true');
            link.append(external);
            link.setAttribute('aria-label', ticket.label);
            container.append(link);
        });
        notes.forEach((text) => {
            const note = document.createElement('small');
            note.className = 'dashboard-trip-ticket-note';
            note.textContent = text;
            container.append(note);
        });
        if (tickets.length === 0 && notes.length === 0) {
            const empty = document.createElement('small');
            empty.className = 'dashboard-trip-ticket-note';
            empty.textContent = emptyText;
            container.append(empty);
        }
        container.hidden = false;
    };

    /** Shows the details card of a trip (see trip-card.js) in place of the list it was picked from. */
    const openTripCard = (model, trigger = null) => {
        if (!tripCard || !tripCardBody) return;
        tripCardTrigger = trigger;
        const mode = document.createElement('span');
        mode.className = 'dashboard-trip-mode';
        renderModeChip(mode, model.mode);

        const titleRow = document.createElement('div');
        titleRow.className = 'dashboard-route-title-row';
        const heading = document.createElement('div');
        const title = document.createElement('h2');
        title.id = 'dashboard-trip-title';
        title.textContent = model.title;
        const subtitle = document.createElement('p');
        subtitle.textContent = model.subtitle;
        heading.append(title, subtitle);
        titleRow.append(heading);

        const itinerary = document.createElement('div');
        itinerary.className = 'dashboard-trip-itinerary';
        renderItinerary(itinerary, model.from, model.to, model.bidirectional);

        const parts = [mode, titleRow, itinerary];
        if (model.status) {
            const status = document.createElement('p');
            status.className = `dashboard-trip-status is-${model.status.state}`;
            status.textContent = model.status.text;
            parts.push(status);
        }
        if (model.departure) {
            // Same look as the next departure of a GTFS line.
            const section = document.createElement('section');
            section.className = 'dashboard-next-trip dashboard-trip-departure';
            section.dataset.routeKey = model.departure.routeKey ?? '';
            section.setAttribute('aria-live', 'polite');
            const heading = document.createElement('h3');
            const icon = document.createElement('i');
            icon.className = 'fa-solid fa-clock';
            icon.setAttribute('aria-hidden', 'true');
            heading.append(icon, ` ${t('Next departure')}`);
            const text = document.createElement('p');
            text.textContent = departures.get(model.departure.routeKey) ?? model.departure.text;
            section.append(heading, text);
            parts.push(section);
        }
        if (model.rows.length > 0) {
            const facts = document.createElement('dl');
            facts.className = 'dashboard-trip-facts';
            model.rows.forEach(({ label, value }) => {
                const fact = document.createElement('div');
                const term = document.createElement('dt');
                term.textContent = label;
                const detail = document.createElement('dd');
                detail.textContent = value;
                fact.append(term, detail);
                facts.append(fact);
            });
            parts.push(facts);
        }
        const tickets = document.createElement('section');
        tickets.className = 'dashboard-trip-tickets';
        tickets.setAttribute('aria-label', t('Tickets'));
        renderTickets(tickets, model.tickets, model.notes, model.emptyTickets);
        parts.push(tickets);

        tripCardBody.replaceChildren(...parts);
        stopRoutesPanel.hidden = true;
        routeDetails.hidden = true;
        tripCard.hidden = false;
        scrollPanelTo(tripCard);
        requestAnimationFrame(() => tripCard.focus({ preventScroll: true }));
    };

    const closeTripCard = () => {
        if (!tripCard || tripCard.hidden) return;
        tripCard.hidden = true;
        tripCardBody.replaceChildren();
    };

    document.getElementById('dashboard-trip-back')?.addEventListener('click', () => {
        closeTripCard();
        stopRoutesPanel.hidden = false;
        const trigger = tripCardTrigger;
        tripCardTrigger = null;
        if (trigger?.isConnected) {
            scrollPanelTo(trigger, 'center');
            requestAnimationFrame(() => trigger.focus({ preventScroll: true }));
        } else {
            scrollPanelTo(stopRoutesPanel);
        }
    });

    /** A list row that opens the trip card: a real button, with an arrow that says so. */
    const tripRow = (className, openCard) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = className;
        item.addEventListener('click', () => openTripCard(openCard(), item));
        return item;
    };
    const tripRowArrow = () => {
        const arrow = document.createElement('i');
        arrow.className = 'fa-solid fa-chevron-right dashboard-trip-open';
        arrow.setAttribute('aria-hidden', 'true');
        return arrow;
    };

    return { renderModeChip, renderItinerary, renderTickets, openTripCard, closeTripCard, tripRow, tripRowArrow };
}
