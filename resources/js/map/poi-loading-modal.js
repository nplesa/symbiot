/**
 * The "Loading points of interest" modal (#poiLoadingModal) with its elapsed time. Show and hide may
 * come in any order while Bootstrap's fade runs: a hide requested during the fade-in waits for it.
 */
import { t } from '../i18n.js';

const deps = { onCancel: () => {} };

/** onCancel: what the modal's Cancel button stops (the running POI load). */
export function configurePoiLoadingModal(options) {
    Object.assign(deps, options);
}

let poiLoadingTimer = null;
let poiLoadingStartedAt = null;
let poiLoadingModalEventsBound = false;
let poiLoadingModalShown = false;
let poiLoadingModalHidePending = false;
let poiLoadingModalShowing = false;
let poiLoadingModalHidePromise = null;

export function showPOIModal() {
    const modalEl = document.getElementById('poiLoadingModal');
    if (!modalEl) return;

    if (!poiLoadingModalEventsBound) {
        modalEl.addEventListener('shown.bs.modal', () => {
            poiLoadingModalShown = true;
            poiLoadingModalShowing = false;
            startPoiElapsedTimer();
            if (poiLoadingModalHidePending) {
                poiLoadingModalHidePending = false;
                hidePOIModal();
            }
        });
        modalEl.addEventListener('hidden.bs.modal', () => {
            poiLoadingModalShown = false;
            poiLoadingModalShowing = false;
            poiLoadingModalHidePending = false;
            stopPoiElapsedTimer();
            poiLoadingModalHidePromise = null;
        });
        modalEl.querySelector('#poiLoadingCancel')?.addEventListener('click', () => deps.onCancel());
        poiLoadingModalEventsBound = true;
    }

    poiLoadingModalHidePending = false;
    startPoiElapsedTimer();

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    if (!poiLoadingModalShown) poiLoadingModalShowing = true;
    modal.show();
}

function startPoiElapsedTimer() {
    if (poiLoadingTimer !== null) return;

    const modalEl = document.getElementById('poiLoadingModal');
    const elapsed = modalEl?.querySelector('#poi-loading-elapsed');
    if (!elapsed) return;

    poiLoadingStartedAt = performance.now();

    const updateElapsed = () => {
        const elapsedElement = document
            .getElementById('poiLoadingModal')
            ?.querySelector('#poi-loading-elapsed');
        if (!elapsedElement || poiLoadingStartedAt === null) return;

        const seconds = Math.floor((performance.now() - poiLoadingStartedAt) / 1000);
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        elapsedElement.textContent = t('Elapsed time: :time', { time: `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}` });
    };

    updateElapsed();
    poiLoadingTimer = window.setInterval(updateElapsed, 250);
}

function stopPoiElapsedTimer() {
    if (poiLoadingTimer !== null) {
        window.clearInterval(poiLoadingTimer);
    }
    poiLoadingTimer = null;
    poiLoadingStartedAt = null;
}

export function hidePOIModal() {
    stopPoiElapsedTimer();

    const modalEl = document.getElementById('poiLoadingModal');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getInstance(modalEl);
    if (!modal) return Promise.resolve();

    if (!poiLoadingModalShown) {
        // show() was already requested but the fade-in has not finished: hide as soon as it does.
        if (!poiLoadingModalShowing && !modalEl.classList.contains('show') && modalEl.style.display === 'none') {
            return Promise.resolve();
        }
        poiLoadingModalHidePending = true;
        if (!poiLoadingModalHidePromise) {
            poiLoadingModalHidePromise = new Promise(resolve => {
                modalEl.addEventListener('hidden.bs.modal', resolve, { once: true });
            });
        }
        return poiLoadingModalHidePromise;
    }

    if (!poiLoadingModalHidePromise) {
        poiLoadingModalHidePromise = new Promise(resolve => {
            modalEl.addEventListener('hidden.bs.modal', resolve, { once: true });
        });
    }

    if (modalEl.contains(document.activeElement)) {
        document.activeElement.blur();
    }
    modal.hide();
    return poiLoadingModalHidePromise;
}
