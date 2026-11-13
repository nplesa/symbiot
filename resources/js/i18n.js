/**
 * Front-end translations. layouts/app.blade.php puts the current locale's
 * lang/{locale}.json into window.SymbiotI18n before any module runs, so
 * t() uses exactly the same keys as __() in Blade (English text as key).
 */

const config = globalThis.SymbiotI18n ?? {};
const messages = config.messages ?? {};

/** The app locale: 'en', 'ro' or 'it'. */
export const locale = typeof config.locale === 'string' ? config.locale : 'en';

/** BCP 47 locale for Intl / toLocaleString (numbers, dates). */
export const intlLocale = { ro: 'ro-RO', it: 'it-IT', en: 'en-GB' }[locale] ?? 'en-GB';

/**
 * Translates an English key; ':name' placeholders are replaced like Laravel's __().
 * Unknown keys fall back to the key itself, so English always works.
 *
 * @param {string} key
 * @param {Record<string, string|number>} replacements
 */
export function t(key, replacements = {}) {
    let text = typeof messages[key] === 'string' ? messages[key] : key;

    // Longest names first, so ':count' never eats part of ':countries'.
    Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .forEach((name) => {
            text = text.split(`:${name}`).join(String(replacements[name]));
        });

    return text;
}

/**
 * @param {number} value
 * @param {Intl.NumberFormatOptions} options
 */
export function formatNumber(value, options = {}) {
    return new Intl.NumberFormat(intlLocale, options).format(value);
}
