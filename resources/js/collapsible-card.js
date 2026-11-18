/**
 * A card of the right panel that opens and closes from its heading button (weather, search radius).
 * Collapsed by default; the user's choice is remembered in this browser under storageKey.
 *
 * @param {{card: HTMLElement|null, toggle: HTMLElement|null, body: HTMLElement|null, storageKey: string}} options
 */
export function bindCollapsibleCard({ card, toggle, body, storageKey }) {
    if (!card || !toggle || !body) return;

    const setOpen = (open, remember) => {
        card.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        body.hidden = !open;
        if (remember) {
            try { localStorage.setItem(storageKey, open ? '1' : '0'); } catch { /* the choice is just not kept */ }
        }
    };

    let open = false;
    try { open = localStorage.getItem(storageKey) === '1'; } catch { /* collapsed */ }
    setOpen(open, false);
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true', true));
}
