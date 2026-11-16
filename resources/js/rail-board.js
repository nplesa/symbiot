/**
 * Formatting helpers for the live station board (/api/transit/rail-board).
 * Kept free of DOM and translations so they can be unit-tested.
 */

/**
 * "HH:MM" of an ISO time in the station's own time zone (a Berlin board shows Berlin time).
 *
 * @param {string|null} iso
 * @param {string|null} timeZone IANA zone of the station, e.g. "Europe/Berlin"
 * @param {string} locale
 * @returns {string}
 */
export function boardTime(iso, timeZone, locale) {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';

    const options = { hour: '2-digit', minute: '2-digit', hour12: false };
    try {
        return new Intl.DateTimeFormat(locale, timeZone ? { ...options, timeZone } : options).format(date);
    } catch {
        return new Intl.DateTimeFormat(locale, options).format(date);
    }
}

/**
 * What the status column shows for a train.
 *
 * @param {{cancelled?: boolean, realtime?: boolean, delay_minutes?: number|null}} entry
 * @returns {{state: 'cancelled'|'late'|'early'|'on_time'|'scheduled', minutes: number}}
 */
export function delayStatus(entry) {
    if (entry.cancelled) return { state: 'cancelled', minutes: 0 };
    if (!entry.realtime || entry.delay_minutes === null || entry.delay_minutes === undefined) {
        return { state: 'scheduled', minutes: 0 };
    }

    const minutes = Math.round(Number(entry.delay_minutes));
    if (minutes > 0) return { state: 'late', minutes };
    if (minutes < 0) return { state: 'early', minutes: -minutes };

    return { state: 'on_time', minutes: 0 };
}

/**
 * Train name with its number, without repeating it ("ICE 856", not "ICE 856 856";
 * "FEX (21891)" for number "021891").
 *
 * @param {{train?: string, number?: string|null}} entry
 * @returns {string}
 */
export function trainLabel(entry) {
    const train = String(entry.train || '').trim();
    const number = String(entry.number || '').trim().replace(/^0+(?=\d)/, '');
    if (!number || train.includes(number)) return train || number;

    return train ? `${train} ${number}` : number;
}
