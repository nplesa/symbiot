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
        text.textContent = 'Se verifică și se pregătesc datele GTFS…';
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
    render(el, [], true);

    while (run === activeRun) {
        let data;
        try {
            const response = await fetch(`/api/transit/coverage?lat=${lat}&lon=${lon}&radius=${radius}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                if (run !== activeRun) return;
                el.textContent = 'Nu am putut verifica descărcarea GTFS. Categoriile POI sunt disponibile.';
                onReady([]);
                return;
            }
            data = await response.json();
        } catch {
            if (run !== activeRun) return;
            el.textContent = 'Nu am putut verifica descărcarea GTFS. Categoriile POI sunt disponibile.';
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
                note.textContent = 'Limita de importuri pentru zone noi a fost atinsă. Încearcă din nou mai târziu.';
                el.append(note);
            } else if (data.feeds.length === 0) {
                el.textContent = 'Nu a fost găsit un feed GTFS disponibil pentru această zonă.';
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
        await new Promise((resolve) => setTimeout(resolve, POLL_MS));
    }
}
