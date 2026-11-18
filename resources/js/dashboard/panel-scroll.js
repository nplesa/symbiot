/** The empty block at the end of the panel that scrollPanelTo() sizes. */
function panelSpacer(panel) {
    let spacer = panel.querySelector(':scope > .dashboard-panel-spacer');
    if (!spacer) {
        spacer = document.createElement('div');
        spacer.className = 'dashboard-panel-spacer';
        spacer.setAttribute('aria-hidden', 'true');
        panel.append(spacer);
    }
    return spacer;
}

/**
 * Scrolls the right panel (.dashboard-route-panel) so that one of its sections is in view.
 *
 * Only the panel scrolls: Element.scrollIntoView() also scrolls every ancestor, even those with
 * overflow: hidden, which on phones shifted the whole page up (search bar gone, gap at the bottom).
 * It runs two frames later, so a panel being opened in the same click (phones) is already laid out,
 * and once more when the animations above the section are over (the POI accordion closes at the same
 * time and moves the section up), unless the user has scrolled the panel meanwhile.
 *
 * @param {Element|null} element a section inside the panel
 * @param {'start'|'center'} block where the section lands
 */
export function scrollPanelTo(element, block = 'start') {
    const panel = element?.closest('.dashboard-route-panel');
    if (!panel) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const align = (behavior) => {
        if (!element.isConnected || element.getClientRects().length === 0) return;
        const panelBox = panel.getBoundingClientRect();
        const box = element.getBoundingClientRect();
        // The panel's sticky toolbar covers its top.
        const toolbar = panel.querySelector('.dashboard-panel-toolbar');
        const covered = toolbar ? toolbar.getBoundingClientRect().height : 0;
        const top = block === 'center'
            ? panel.scrollTop + box.top - panelBox.top - (panel.clientHeight - box.height) / 2
            : panel.scrollTop + box.top - panelBox.top - covered - 8;
        // A short section at the end (a line's card under a short list) cannot reach the top on a tall
        // screen: the list would stay visible above it. A spacer at the end of the panel gives the room.
        const spacer = panelSpacer(panel);
        const missing = Math.ceil(top - (panel.scrollHeight - spacer.offsetHeight - panel.clientHeight));
        spacer.style.height = `${Math.max(0, missing)}px`;
        if (Math.abs(top - panel.scrollTop) < 2) return;
        panel.scrollTo({ top: Math.max(0, top), behavior: reduceMotion ? 'auto' : behavior });
    };

    // A touch, wheel or key in the panel means the user scrolls it: no correction after that.
    let userScrolled = false;
    const stop = () => { userScrolled = true; };
    const events = ['wheel', 'touchstart', 'pointerdown', 'keydown'];
    events.forEach((name) => panel.addEventListener(name, stop, { passive: true, once: true }));

    requestAnimationFrame(() => requestAnimationFrame(() => align('smooth')));
    setTimeout(() => {
        events.forEach((name) => panel.removeEventListener(name, stop));
        if (!userScrolled) align('smooth');
    }, 450);
}
