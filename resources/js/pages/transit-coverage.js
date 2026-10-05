const STAGES = {
    queued: 'În a?teptare la coada…',
    downloading: 'Descarc datele de transport…',
    preparing: 'Pregatesc importul…',
    importing: 'Import în baza de date',
    done: 'Gata',
    failed: 'Importul a e?uat',
};

const POLL_MS = 2000;
let activeRun = 0;

function panel(anchor) {
    let el = document.getElementById('transit-progress');
    if (!el) {
        el = document.createElement('div');
        el.id = 'transit-progress';
        el.className = 'col-12 small';
        anchor.insertAdjacentElement('afterend', el);
    }

    return el;
}

function render(el, feeds) {
    const busy = feeds.filter((f) => f.progress && !['done', 'failed'].includes(f.progress.stage));
    el.replaceChildren();
    busy.forEach((feed) => {
        const { stage, percent, detail } = feed.progress;
        const label = STAGES[stage] || stage;
        const wrap = document.createElement('div');
        wrap.className = 'mb-2';
        const text = document.createElement('div');
        text.textContent = `${feed.name}: ${label}${stage === 'importing' && detail ? ` (${detail})` : ''} — ${percent}%`;
        const bar = document.createElement('div');
        bar.className = 'progress';
        bar.style.height = '6px';
        const fill = document.createElement('div');
        fill.className = 'progress-bar progress-bar-striped progress-bar-animated';
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
export async function watchTransitCoverage(lat, lon, anchor, onReady = () => {}) {
    const run = ++activeRun;
    const el = panel(anchor);

    while (run === activeRun) {
        let data;
        try {
            const response = await fetch(`/api/transit/coverage?lat=${lat}&lon=${lon}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                break;
            }
            data = await response.json();
        } catch {
            break;
        }

        if (run !== activeRun) {
            return;
        }
        render(el, data.feeds);
        if (!data.preparing) {
            el.replaceChildren();
            onReady(data.feeds);
            return;
        }
        await new Promise((resolve) => setTimeout(resolve, POLL_MS));
    }
}
