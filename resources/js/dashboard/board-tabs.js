/**
 * The "Departures / Arrivals" tabs with a refresh button, shared by the station board (trains) and the
 * airport board (flights).
 */
import { t } from '../i18n.js';

/**
 * @param {{tabs: Array<[string, string]>, onSelect: (key: string) => void, onRefresh: (key: string) => void}} options
 *   tabs: [key, label] in order (the first one is the default)
 * @returns {{element: HTMLElement, select: (key: string) => void, selected: () => string}}
 */
export function createBoardTabs({ tabs, onSelect, onRefresh }) {
    const toolbar = document.createElement('div');
    toolbar.className = 'dashboard-rail-tabs';
    toolbar.setAttribute('role', 'tablist');

    const buttons = new Map();
    tabs.forEach(([key, label]) => {
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'dashboard-rail-tab';
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', 'false');
        tab.textContent = label;
        tab.addEventListener('click', () => onSelect(key));
        buttons.set(key, tab);
        toolbar.append(tab);
    });

    const selected = () => [...buttons].find(([, tab]) => tab.getAttribute('aria-selected') === 'true')?.[0] ?? tabs[0][0];

    const refresh = document.createElement('button');
    refresh.type = 'button';
    refresh.className = 'dashboard-rail-refresh';
    refresh.setAttribute('aria-label', t('Refresh'));
    refresh.title = t('Refresh');
    const refreshIcon = document.createElement('i');
    refreshIcon.className = 'fa-solid fa-rotate-right';
    refreshIcon.setAttribute('aria-hidden', 'true');
    refresh.append(refreshIcon);
    refresh.addEventListener('click', () => onRefresh(selected()));
    toolbar.append(refresh);

    return {
        element: toolbar,
        select: (key) => buttons.forEach((tab, tabKey) => tab.setAttribute('aria-selected', String(tabKey === key))),
        selected,
    };
}
