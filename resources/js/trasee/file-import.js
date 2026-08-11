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
                if (status) { status.textContent = 'Completează numele traseului înainte de import.'; status.className = 'text-danger small'; }
                nameInput?.focus();
                return;
            }
            if (!file) {
                window.__traseeFileImportInFlight = false;
                if (status) { status.textContent = 'Selectează mai întâi un fișier GPX, KML, KMZ, GeoJSON sau CSV.'; status.className = 'text-danger small'; }
                input?.focus();
                return;
            }
            if (!window.Livewire) {
                window.__traseeFileImportInFlight = false;
                if (status) { status.textContent = 'Componenta Livewire nu a fost găsită. Reîncarcă pagina.'; status.className = 'text-danger small'; }
                return;
            }
            const componentRoot = form.closest('[wire\\:id]');
            const componentId = componentRoot?.getAttribute('wire:id');
            if (!componentId) {
                window.__traseeFileImportInFlight = false;
                if (status) { status.textContent = 'Componenta Livewire nu a fost găsită. Reîncarcă pagina.'; status.className = 'text-danger small'; }
                return;
            }
            const component = window.Livewire.find(componentId);
            if (!component || typeof component.upload !== 'function') {
                window.__traseeFileImportInFlight = false;
                if (status) { status.textContent = 'Upload-ul Livewire nu este disponibil. Reîncarcă pagina.'; status.className = 'text-danger small'; }
                return;
            }
            window.__traseeRouteImportFailedDuringCall = false;
            if (button) button.disabled = true;
            if (status) { status.textContent = 'Se încarcă fișierul...'; status.className = 'text-secondary small'; }
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
                try {
                    if (status) { status.textContent = 'Fișier încărcat. Se importă traseul...'; status.className = 'text-secondary small'; }
                    if (component.$wire && typeof component.$wire.importRoute === 'function') {
                        await component.$wire.importRoute(importToken);
                    } else if (typeof component.call === 'function') {
                        await component.call('importRoute', importToken);
                    } else {
                        throw new Error('API-ul Livewire pentru import nu este disponibil. Reîncarcă pagina.');
                    }
                    if (status && !window.__traseeRouteImportFailedDuringCall) {
                        status.textContent = 'Importul a fost procesat. Se actualizează lista...';
                        status.className = 'text-secondary small';
                    }
                } catch (error) {
                    console.error('[trasee] file import', error);
                    if (status) { status.textContent = error?.message || 'Importul traseului a eșuat.'; status.className = 'text-danger small'; }
                } finally {
                    window.__traseeFileImportInFlight = false;
                    if (button) button.disabled = false;
                }
            }, (error) => {
                window.__traseeFileImportInFlight = false;
                console.error('[trasee] file upload', error);
                if (status) { status.textContent = 'Încărcarea fișierului a eșuat.'; status.className = 'text-danger small'; }
                if (button) button.disabled = false;
            }, (event) => {
                const percent = Math.round((event.loaded / Math.max(event.total || 1, 1)) * 100);
                if (status) status.textContent = `Se încarcă fișierul... ${percent}%`;
            });
        });
    };
    if (!window.__traseeRouteImportStatusEventsBound) {
        window.__traseeRouteImportStatusEventsBound = true;
        window.addEventListener('route-import-failed', (event) => {
            window.__traseeRouteImportFailedDuringCall = true;
            const status = document.getElementById('route-file-import-status');
            if (!status) return;
            const message = event?.detail?.message || 'Fișierul nu a putut fi importat.';
            status.textContent = message;
            status.className = 'text-danger small';
        });
        window.addEventListener('route-imported', (event) => {
            if (event?.detail?.source !== 'file') return;
            window.__traseeRouteImportFailedDuringCall = false;
            const status = document.getElementById('route-file-import-status');
            if (!status) return;
            status.textContent = 'Traseul a fost importat.';
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
