import { t } from '../i18n.js';

// An import taking longer than this shows a progress bar: the real percentage while the file uploads,
// then an animated bar with the elapsed time while the server processes the route (no percentage
// exists for that step; it usually takes a couple of seconds).
const PROGRESS_AFTER_MS = 20_000;

function createImportProgress() {
    let startedAt = 0;
    let timer = null;
    let phase = 'upload';
    let percent = 0;

    const elapsed = () => {
        const seconds = Math.floor((performance.now() - startedAt) / 1000);
        return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
    };
    // The elements are looked up each time: Livewire may re-render the form around them.
    const render = () => {
        const box = document.getElementById('route-file-import-progress');
        const bar = document.getElementById('route-file-import-progress-bar');
        const text = document.getElementById('route-file-import-progress-text');
        if (!box || !bar || timer === null || performance.now() - startedAt < PROGRESS_AFTER_MS) return;

        box.hidden = false;
        const progress = bar.parentElement;
        if (phase === 'upload') {
            bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            bar.style.width = `${percent}%`;
            progress?.setAttribute('aria-valuenow', String(percent));
            if (text) text.textContent = t('Uploading the file: :percent% · :time', { percent, time: elapsed() });
        } else {
            bar.classList.add('progress-bar-striped', 'progress-bar-animated');
            bar.style.width = '100%';
            progress?.removeAttribute('aria-valuenow');
            if (text) text.textContent = t('Importing the route… :time', { time: elapsed() });
        }
    };

    return {
        start() {
            window.clearInterval(timer);
            startedAt = performance.now();
            phase = 'upload';
            percent = 0;
            timer = window.setInterval(render, 500);
        },
        uploading(value) {
            percent = value;
            render();
        },
        importing() {
            phase = 'import';
            render();
        },
        stop() {
            window.clearInterval(timer);
            timer = null;
            const box = document.getElementById('route-file-import-progress');
            const bar = document.getElementById('route-file-import-progress-bar');
            if (box) box.hidden = true;
            if (bar) {
                bar.style.width = '0%';
                bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            }
        },
    };
}

const importProgress = createImportProgress();

(() => {
    if (window.__routeFileImportBoundV4) return;
    window.__routeFileImportBoundV4 = true;
    const bind = () => {
        const form = document.getElementById('route-file-import-form');
        if (!form || form.dataset.bound === '1') return;
        form.dataset.bound = '1';
        const input = document.getElementById('route-file');
        const button = document.getElementById('route-file-import-submit');
        const status = document.getElementById('route-file-import-status');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            event.stopPropagation();

            // Protecție împotriva submit-urilor duplicate/concurente.
            // În anumite combinații Livewire + morph/upload, același submit poate ajunge
            // de mai multe ori la componentă. Un singur import trebuie să fie activ.
            if (window.__traseeFileImportInFlight) return;
            window.__traseeFileImportInFlight = true;
            const importToken = `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
            const nameInput = document.getElementById('route-name');
            const routeName = nameInput?.value?.trim() || '';
            const file = input?.files?.[0] || null;
            if (!routeName) {
                window.__traseeFileImportInFlight = false;
                if (status) { status.textContent = t('Fill in the route name before importing.'); status.className = 'text-danger small'; }
                nameInput?.focus();
                return;
            }
            if (!file) {
                window.__traseeFileImportInFlight = false;
                if (status) { status.textContent = t('First select a GPX, KML, KMZ, GeoJSON or CSV file.'); status.className = 'text-danger small'; }
                input?.focus();
                return;
            }
            if (!window.Livewire) {
                window.__traseeFileImportInFlight = false;
                if (status) { status.textContent = t('The Livewire component was not found. Reload the page.'); status.className = 'text-danger small'; }
                return;
            }
            const componentRoot = form.closest('[wire\\:id]');
            const componentId = componentRoot?.getAttribute('wire:id');
            if (!componentId) {
                window.__traseeFileImportInFlight = false;
                if (status) { status.textContent = t('The Livewire component was not found. Reload the page.'); status.className = 'text-danger small'; }
                return;
            }
            const component = window.Livewire.find(componentId);
            if (!component || typeof component.upload !== 'function') {
                window.__traseeFileImportInFlight = false;
                if (status) { status.textContent = t('Livewire upload is not available. Reload the page.'); status.className = 'text-danger small'; }
                return;
            }
            window.__traseeRouteImportFailedDuringCall = false;
            if (button) button.disabled = true;
            if (status) { status.textContent = t('Uploading file...'); status.className = 'text-secondary small'; }
            importProgress.start();
            // Livewire 4 expune setter-ul public ca $set().
            // Nu folosim component.$wire.set('name', ...) aici: `name` este
            // și proprietate read-only a funcției Proxy folosite de $wire.
            if (component.$wire && typeof component.$wire.$set === 'function') {
                await component.$wire.$set('name', routeName);
            } else {
                // Fallback: wire:model="name" de pe input sincronizează valoarea.
                nameInput?.dispatchEvent(new Event('input', { bubbles: true }));
                nameInput?.dispatchEvent(new Event('change', { bubbles: true }));
            }
            component.upload('file', file, async () => {
                importProgress.importing();
                try {
                    if (status) { status.textContent = t('File uploaded. Importing the route...'); status.className = 'text-secondary small'; }
                    if (component.$wire && typeof component.$wire.importRoute === 'function') {
                        await component.$wire.importRoute(importToken);
                    } else if (typeof component.call === 'function') {
                        await component.call('importRoute', importToken);
                    } else {
                        throw new Error(t('The Livewire import API is not available. Reload the page.'));
                    }
                    if (status && !window.__traseeRouteImportFailedDuringCall) {
                        status.textContent = t('Import processed. Updating the list...');
                        status.className = 'text-secondary small';
                    }
                } catch (error) {
                    console.error('[trasee] file import', error);
                    if (status) { status.textContent = error?.message || t('Route import failed.'); status.className = 'text-danger small'; }
                } finally {
                    importProgress.stop();
                    window.__traseeFileImportInFlight = false;
                    if (button) button.disabled = false;
                }
            }, (error) => {
                importProgress.stop();
                window.__traseeFileImportInFlight = false;
                console.error('[trasee] file upload', error);
                if (status) { status.textContent = t('File upload failed.'); status.className = 'text-danger small'; }
                if (button) button.disabled = false;
            }, (event) => {
                const percent = Math.round((event.loaded / Math.max(event.total || 1, 1)) * 100);
                if (status) status.textContent = t('Uploading file... :percent%', { percent });
                importProgress.uploading(percent);
            });
        });
    };
    if (!window.__traseeRouteImportStatusEventsBound) {
        window.__traseeRouteImportStatusEventsBound = true;
        window.addEventListener('route-import-failed', (event) => {
            window.__traseeRouteImportFailedDuringCall = true;
            const status = document.getElementById('route-file-import-status');
            if (!status) return;
            const message = event?.detail?.message || t('The file could not be imported.');
            status.textContent = message;
            status.className = 'text-danger small';
        });
        window.addEventListener('route-imported', (event) => {
            if (event?.detail?.source !== 'file') return;
            window.__traseeRouteImportFailedDuringCall = false;
            const status = document.getElementById('route-file-import-status');
            if (!status) return;
            status.textContent = t('The route was imported.');
            status.className = 'text-success small';
        });
    }

    bind();
    document.addEventListener('livewire:navigated', bind);
    document.addEventListener('livewire:initialized', bind);

    const bindAfterMorph = () => window.setTimeout(bind, 0);
    const bindToLivewire = () => {
        if (!window.Livewire || window.__traseeFileImportMorphBound) return;
        window.__traseeFileImportMorphBound = true;
        if (typeof window.Livewire.hook === 'function') {
            window.Livewire.hook('morphed', bindAfterMorph);
        }
    };
    if (window.Livewire) bindToLivewire();
    else document.addEventListener('livewire:initialized', bindToLivewire, { once: true });
})();
