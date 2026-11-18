/**
 * Readable colours for transit lines. Feeds often give a line the colour FFFFFF without a text colour
 * (white text on a white badge) or a text colour that barely contrasts; these helpers keep the feed's
 * colours when they read well and pick black or white text otherwise.
 */

const HEX = /^#?([0-9a-f]{6})$/i;

/** '#rrggbb' for a GTFS/CSS colour ('FFD400', '#ffd400'), otherwise null. */
export function normalizeHex(value) {
    const match = HEX.exec(String(value ?? '').trim());
    return match ? `#${match[1].toLowerCase()}` : null;
}

/** WCAG relative luminance of '#rrggbb' (0 black … 1 white). */
export function luminance(hex) {
    const value = normalizeHex(hex);
    if (!value) return 0;
    const channel = (offset) => {
        const c = parseInt(value.slice(offset, offset + 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
}

/** WCAG contrast ratio between two colours (1 … 21). */
export function contrastRatio(a, b) {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (light + 0.05) / (dark + 0.05);
}

/** Almost white (white, pale yellow…): such a badge needs an outline, such a line is not seen on the map. */
export function isVeryLight(hex) {
    return luminance(hex) > 0.8;
}

/**
 * Badge colours of a line: the feed's background (or the fallback) and its text colour when that reads
 * well (contrast ≥ 4.5), otherwise black or white, whichever contrasts more.
 *
 * @returns {{background: string, text: string, light: boolean}}
 */
export function lineBadgeColors(color, textColor, fallback = '#1769e0') {
    const background = normalizeHex(color) ?? normalizeHex(fallback) ?? '#1769e0';
    const supplied = normalizeHex(textColor);
    const text = supplied && contrastRatio(background, supplied) >= 4.5
        ? supplied
        : (contrastRatio(background, '#000000') >= contrastRatio(background, '#ffffff') ? '#000000' : '#ffffff');

    return { background, text, light: isVeryLight(background) };
}

/** Paints a badge element with lineBadgeColors(); a very light badge gets the "is-light" outline class. */
export function paintLineBadge(element, color, textColor, fallback) {
    if (!element) return;
    const colors = lineBadgeColors(color, textColor, fallback);
    element.style.backgroundColor = colors.background;
    element.style.color = colors.text;
    element.classList.toggle('is-light', colors.light);
}
