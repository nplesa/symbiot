/** The "Loading map… n / total" bar of the base map tiles (#map-tile-progress). */
import { t } from '../i18n.js';

/** Follows the tile loads of an OpenLayers tile source and shows their progress. */
export function watchTileProgress(mapTileSource) {
    const mapTileProgress = document.getElementById('map-tile-progress');
    const mapTileProgressLabel = document.getElementById('map-tile-progress-label');
    const mapTileProgressCount = document.getElementById('map-tile-progress-count');
    const mapTileProgressBar = document.getElementById('map-tile-progress-bar');
    let requestedMapTiles = new Set();
    let completedMapTiles = new Set();
    let failedMapTiles = new Set();
    let tileProgressHideTimer = null;

    function renderMapTileProgress() {
        const total = requestedMapTiles.size;
        window.clearTimeout(tileProgressHideTimer);
        if (total === 0) {
            mapTileProgress.classList.add('d-none');
            return;
        }

        const completed = completedMapTiles.size;
        const failed = failedMapTiles.size;
        const percent = Math.min(100, Math.round((completed / total) * 100));
        const settled = completed + failed;
        const isLoading = settled < total;

        mapTileProgress.classList.remove('d-none');
        mapTileProgressLabel.textContent = isLoading
            ? t('Loading map...')
            : failed > 0
                ? t('Map loaded; :count tiles could not be fetched.', { count: failed })
                : t('Map loaded');
        mapTileProgressCount.textContent = `${completed} / ${total} (${percent}%)`;
        mapTileProgressBar.style.width = `${percent}%`;
        mapTileProgressBar.parentElement.setAttribute('aria-valuenow', String(percent));
        mapTileProgressBar.classList.toggle('bg-warning', failed > 0 && !isLoading);
        mapTileProgressBar.classList.toggle('progress-bar-animated', isLoading);

        if (!isLoading) {
            tileProgressHideTimer = window.setTimeout(() => {
                if (completedMapTiles.size + failedMapTiles.size >= requestedMapTiles.size) {
                    mapTileProgress.classList.add('d-none');
                }
            }, failed > 0 ? 5000 : 1200);
        }
    }

    mapTileSource.on('tileloadstart', event => {
        const key = event.tile.getTileCoord().join('/');
        if (completedMapTiles.size + failedMapTiles.size >= requestedMapTiles.size) {
            requestedMapTiles = new Set();
            completedMapTiles = new Set();
            failedMapTiles = new Set();
        }
        requestedMapTiles.add(key);
        completedMapTiles.delete(key);
        failedMapTiles.delete(key);
        renderMapTileProgress();
    });
    mapTileSource.on('tileloadend', event => {
        const key = event.tile.getTileCoord().join('/');
        requestedMapTiles.add(key);
        completedMapTiles.add(key);
        failedMapTiles.delete(key);
        renderMapTileProgress();
    });
    mapTileSource.on('tileloaderror', event => {
        const key = event.tile.getTileCoord().join('/');
        requestedMapTiles.add(key);
        failedMapTiles.add(key);
        completedMapTiles.delete(key);
        renderMapTileProgress();
    });
}
