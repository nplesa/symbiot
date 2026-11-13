import { pollDelayMs } from '../transit-polling.js';
import { t } from '../i18n.js';

// English keys; translated with t() where the label is shown.
const STAGES = {
    queued: 'Waiting in the queue…',
    retrying: 'Retrying the import…',
    downloading: 'Downloading the transit data…',
    preparing: 'Preparing the import…',
    importing: 'Importing into the database',
    done: 'Done',
    failed: 'The import failed',
};

let activeRun = 0;

/** Resolves after `ms`, but never while the tab is hidden: a hidden tab waits until it is shown again. */
function waitForNextPoll(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms)).then(() => {
        if (!document.hidden) return undefined;

        return new Promise((resolve) => {
            const onVisible = () => {
                if (document.hidden) return;
                document.removeEventListener('visibilitychange', onVisible);
                resolve();
            };
            document.addEventListener('visibilitychange', onVisible);
        });
    });
}

window.addEventListener('symbiot:location-changing', () => {
    activeRun++;
    document.getElementById('transit-progress')?.remove();
});

function panel(anchor) {
    let el = document.getElementById('transit-progress');
    const host = document.getElementById('dashboard-transit-progress-host')
        || document.getElementById('dashboard-search-status')
        || anchor;
    if (!el) {
        el = document.createElement('div');
        el.id = 'transit-progress';
        el.className = 'transit-progress-panel small w-100';
    }
    if (el.parentElement !== host) host.append(el);
    el.hidden = false;

    return el;
}

function render(el, feeds, preparing = false) {
    el.replaceChildren();
    const visibleFeeds = feeds.filter((feed) => feed.progress);
    if (visibleFeeds.length === 0 && preparing) {
        const text = document.createElement('div');
        text.textContent = t('Checking and preparing the GTFS data…');
        const bar = document.createElement('div');
        bar.className = 'progress';
        bar.style.height = '6px';
        const fill = document.createElement('div');
        fill.className = 'progress-bar progress-bar-striped progress-bar-animated';
        fill.style.width = '100%';
        bar.append(fill);
        el.append(text, bar);
    }
    visibleFeeds.forEach((feed) => {
        const { stage, percent, detail } = feed.progress;
        const label = STAGES[stage] ? t(STAGES[stage]) : stage;
        const wrap = document.createElement('div');
        wrap.className = 'mb-2';
        const text = document.createElement('div');
        text.textContent = `${feed.name}: ${label}${detail ? ` (${detail})` : ''} — ${percent}%`;
        const bar = document.createElement('div');
        bar.className = 'progress';
        bar.style.height = '6px';
        const fill = document.createElement('div');
        fill.className = `progress-bar${['done', 'failed'].includes(stage) ? '' : ' progress-bar-striped progress-bar-animated'}${stage === 'failed' ? ' bg-danger' : stage === 'done' ? ' bg-success' : ''}`;
        fill.style.width = `${percent}%`;
        bar.append(fill);
        wrap.append(text, bar);
        el.append(wrap);
    });
}

/**
 * Asks the server which transit feeds cover a point. Missing data is imported
 * in the background; progress is polled until everything is stored locally.
 */
export async function watchTransitCoverage(lat, lon, anchor, onReady = () => {}, radius = 1500) {
    const run = ++activeRun;
    const el = panel(anchor);
    render(el, [], true);
    const startedAt = Date.now();

    while (run === activeRun) {
        let data;
        try {
            const response = await fetch(`/api/transit/coverage?lat=${lat}&lon=${lon}&radius=${radius}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                if (run !== activeRun) return;
                el.textContent = t('Could not check the GTFS download. POI categories are available.');
                onReady([]);
                return;
            }
            data = await response.json();
        } catch {
            if (run !== activeRun) return;
            el.textContent = t('Could not check the GTFS download. POI categories are available.');
            onReady([]);
            return;
        }

        if (run !== activeRun) {
            return;
        }
        render(el, data.feeds, data.preparing);
        if (!data.preparing) {
            if (data.import_limited) {
                const note = document.createElement('div');
                note.className = 'text-warning';
                note.textContent = t('The import limit for new areas has been reached. Try again later.');
                el.append(note);
            } else if (data.feeds.length === 0) {
                el.textContent = t('No GTFS feed is available for this area.');
            }
            if (run !== activeRun) {
                return;
            }
            onReady(data.feeds);
            const allFeedsReady = data.feeds.length > 0
                && data.feeds.every((feed) => feed.progress?.stage === 'done');
            if (allFeedsReady) {
                window.setTimeout(() => {
                    if (run === activeRun) el.remove();
                }, 2500);
            }
            return;
        }

        const delay = pollDelayMs(Date.now() - startedAt);
        if (delay === null) {
            // A stuck queue or a very long import: stop polling, keep the map usable.
            const note = document.createElement('div');
            note.className = 'text-warning';
            note.textContent = t('The import is taking longer than usual. Reload the page later for the transit data.');
            el.append(note);
            onReady(data.feeds);
            return;
        }
        await waitForNextPoll(delay);
    }
}
