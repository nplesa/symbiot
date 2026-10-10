const STAGES = {
    queued: 'În așteptare la coadă…',
    retrying: 'Reîncerc importul…',
    downloading: 'Descarc datele de transport…',
    preparing: 'Pregătesc importul…',
    importing: 'Import în baza de date',
    done: 'Gata',
    failed: 'Importul a eșuat',
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
    el.replaceChildren();
    feeds.filter((feed) => feed.progress).forEach((feed) => {
        const { stage, percent, detail } = feed.progress;
        const label = STAGES[stage] || stage;
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

    while (run === activeRun) {
        let data;
        try {
            const response = await fetch(`/api/transit/coverage?lat=${lat}&lon=${lon}&radius=${radius}`, {
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
            if (data.feeds.some((feed) => feed.progress && ['done', 'failed'].includes(feed.progress.stage))) {
                await new Promise((resolve) => setTimeout(resolve, 1500));
            }
            if (run !== activeRun) {
                return;
            }
            el.replaceChildren();
            onReady(data.feeds);
            return;
        }
        await new Promise((resolve) => setTimeout(resolve, POLL_MS));
    }
}
