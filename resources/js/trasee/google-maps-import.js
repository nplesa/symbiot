import { t } from '../i18n.js';

(() => {
    if (window.__googleMapsKmlBackendBoundV1) return;
    window.__googleMapsKmlBackendBoundV1 = true;

    const setStatus = (message = '', type = 'muted') => {
        const status = document.getElementById('google-maps-import-status');
        if (!status) return;
        status.textContent = message;
        status.className = `form-text mt-2 text-${type}`;
    };

    const importFromGoogleMaps = async (event) => {
        const form = event?.target instanceof HTMLFormElement ? event.target : null;
        if (!form) return;
        event.preventDefault();

        const input = form.querySelector('#google-maps-url, [name="googleMapsUrl"]');
        const nameInput = document.getElementById('route-name');
        const submit = form.querySelector('#generate-google-maps-kml-submit, button[type="submit"]');
        const rawUrl = String(input?.value ?? '').trim();

        if (!rawUrl) {
            setStatus(t('First paste the link copied from Google Maps.'), 'danger');
            return;
        }

        if (submit) {
            submit.disabled = true;
            submit.classList.add('disabled');
        }

        setStatus(t('Converting the Google Maps link to KML...'), 'secondary');

        try {
            const response = await fetch('/api/trasee/google-maps-kml', {
                method: 'POST',
                headers: {
                    'Accept': 'application/vnd.google-earth.kml+xml, application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    url: rawUrl,
                    name: nameInput?.value?.trim() || 'traseu-google-maps',
                    travel_mode: 'driving',
                }),
            });

            if (!response.ok) {
                const data = await response.json().catch(() => null);
                throw new Error(data?.message || t('Could not convert the Google Maps link to KML.'));
            }

            const blob = await response.blob();
            const disposition = response.headers.get('Content-Disposition') || '';
            const filenameMatch = disposition.match(/filename="?([^";]+)"?/i);
            const filename = filenameMatch?.[1] || 'traseu-google-maps.kml';
            const objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download = filename;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);

            setStatus(
                t('The KML was generated and downloaded. You can now upload it with "Import route".'),
                'success'
            );
        } catch (error) {
            console.error('[trasee] Google Maps → KML', error);
            setStatus(error?.message || t('Google Maps → KML conversion failed.'), 'danger');
        } finally {
            if (submit) {
                submit.disabled = false;
                submit.classList.remove('disabled');
            }
        }
    };

    const bindGoogleMapsImportForm = () => {
        const form = document.getElementById('google-maps-import-form');
        if (!form || form.dataset.googleMapsBound === '1') return;
        form.dataset.googleMapsBound = '1';
        form.addEventListener('submit', importFromGoogleMaps);

        const pasteButton = form.querySelector('#paste-google-maps-link');
        if (pasteButton && pasteButton.dataset.pasteBound !== '1') {
            pasteButton.dataset.pasteBound = '1';
            pasteButton.addEventListener('click', async () => {
                const input = form.querySelector('#google-maps-url, [name=\"googleMapsUrl\"]');
                if (!input) return;

                try {
                    if (navigator.clipboard?.readText) {
                        const text = (await navigator.clipboard.readText()).trim();
                        if (!text) throw new Error('Clipboardul este gol.');
                        input.value = text;
                    } else {
                        throw new Error('Clipboardul nu este disponibil în acest browser.');
                    }
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    input.focus();
                    setStatus(t('Google Maps link pasted.'), 'success');
                } catch (error) {
                    console.error('[trasee] clipboard paste', error);
                    input.focus();
                    setStatus(t('Cannot read the clipboard. Use Ctrl+V in the field above.'), 'warning');
                }
            });
        }
    };

    const bindAfterMorph = () => window.setTimeout(bindGoogleMapsImportForm, 0);

    bindGoogleMapsImportForm();
    document.addEventListener('livewire:navigated', bindGoogleMapsImportForm);
    document.addEventListener('livewire:initialized', bindGoogleMapsImportForm);

    const bindToLivewire = () => {
        if (!window.Livewire || window.__traseeGoogleImportMorphBound) return;
        window.__traseeGoogleImportMorphBound = true;
        if (typeof window.Livewire.hook === 'function') {
            window.Livewire.hook('morphed', bindAfterMorph);
        }
    };

    if (window.Livewire) {
        bindToLivewire();
    } else {
        document.addEventListener('livewire:initialized', bindToLivewire, { once: true });
    }
})();
