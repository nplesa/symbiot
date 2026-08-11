import Map from 'ol/Map';
import View from 'ol/View';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import XYZ from 'ol/source/XYZ';
import VectorSource from 'ol/source/Vector';
import GeoJSON from 'ol/format/GeoJSON';
import Style from 'ol/style/Style';
import Stroke from 'ol/style/Stroke';
import CircleStyle from 'ol/style/Circle';
import Fill from 'ol/style/Fill';
import Point from 'ol/geom/Point';
import Feature from 'ol/Feature';

let routeMap = null;
let refreshTimer = null;
let pendingGeometry = null;
let initialized = false;
const LOG_PREFIX = '[traseu-preview]';
function getContainer() { return document.getElementById('traseu_map_container'); }
function getTarget() {
    const target = document.getElementById('traseu_map');
    if (target) {
        target.style.display = 'grid';
        target.style.width = '100%';
        target.style.height = '100%';
        target.style.minHeight = '420px';
    }
    return target;
}
function parseJson(raw) {
    if (!raw || raw === 'null' || raw === 'undefined') return null;
    try { return typeof raw === 'string' ? JSON.parse(raw) : raw; }
    catch (error) { console.error(`${LOG_PREFIX} JSON invalid`, error, raw); return null; }
}
function getGeometryFromContainer() {
    const container = getContainer();
    return container ? parseJson(container.getAttribute('data-route-geometry')) : null;
}
function destroyMap() { if (routeMap) { routeMap.setTarget(null); routeMap = null; } }
function buildMap(target) {
    const source = new VectorSource();
    const vectorLayer = new VectorLayer({ source, style: new Style({ stroke: new Stroke({ color: '#0d6efd', width: 5 }) }) });
    const markerSource = new VectorSource();
    const markerLayer = new VectorLayer({
        source: markerSource,
        style: (feature) => new Style({
            image: new CircleStyle({
                radius: 7,
                fill: new Fill({ color: feature.get('routeMarker') === 'start' ? '#198754' : '#dc3545' }),
                stroke: new Stroke({ color: '#fff', width: 2 }),
            }),
        }),
    });
    const map = new Map({
        target,
        layers: [
            new TileLayer({ source: new XYZ({
                url: '/map/tiles/{z}/{x}/{y}',
                crossOrigin: 'anonymous',
                maxZoom: 22,
            }) }),
            vectorLayer,
            markerLayer,
        ],
        view: new View({ center: [0, 0], zoom: 2 }),
    });
    return { map, source, markerSource };
}
function readFeatures(geometry) {
    if (!geometry || typeof geometry !== 'object') return [];
    const format = new GeoJSON();
    const options = { featureProjection: 'EPSG:3857' };
    if (geometry.type === 'FeatureCollection' || geometry.type === 'Feature') return format.readFeatures(geometry, options);
    if (geometry.type === 'LineString' || geometry.type === 'MultiLineString') return format.readFeatures({ type: 'Feature', geometry }, options);
    return [];
}
function drawRoute(geometry) {
    const target = getTarget();
    if (!target) { destroyMap(); return; }
    destroyMap();
    const { map, source, markerSource } = buildMap(target);
    routeMap = map;
    const features = readFeatures(geometry);
    if (!features.length) { console.warn(`${LOG_PREFIX} traseul nu are geometrie validă`, geometry); map.updateSize(); return; }
    source.addFeatures(features);



    const lineCoordinates = [];
    features.forEach(feature => {
        const geometryObject = feature.getGeometry();
        if (!geometryObject) return;
        if (geometryObject.getType() === 'LineString') {
            lineCoordinates.push(...geometryObject.getCoordinates());
        } else if (geometryObject.getType() === 'MultiLineString') {
            geometryObject.getCoordinates().forEach(line => lineCoordinates.push(...line));
        }
    });
    if (lineCoordinates.length >= 2) {
        const start = new Point(lineCoordinates[0]);
        const end = new Point(lineCoordinates[lineCoordinates.length - 1]);
        const startFeature = new Feature({ geometry: start, routeMarker: 'start' });
        const endFeature = new Feature({ geometry: end, routeMarker: 'end' });
        markerSource.addFeatures([startFeature, endFeature]);
    }

    const fit = () => {
        if (!routeMap) return;
        routeMap.updateSize();
        const extent = source.getExtent();
        if (!extent.every(Number.isFinite)) return;
        routeMap.getView().fit(extent, { padding: [45, 45, 45, 45], maxZoom: 16, duration: 250 });
        routeMap.renderSync();
    };
    requestAnimationFrame(fit);
    window.setTimeout(fit, 120);
    window.setTimeout(fit, 400);
}
function refreshPreview() {
    const container = getContainer();
    const geometry = pendingGeometry ?? getGeometryFromContainer();
    pendingGeometry = null;
    if (!container || !getTarget() || !geometry) { destroyMap(); return; }
    drawRoute(geometry);
}
function scheduleRefresh(delay = 30) { window.clearTimeout(refreshTimer); refreshTimer = window.setTimeout(refreshPreview, delay); }
function geometryFromRouteButton(button) { return button instanceof HTMLElement ? parseJson(button.getAttribute('data-route-geometry')) : null; }
function bindRouteClicks() {
    if (window.__traseuPreviewClicksBound) return;
    window.__traseuPreviewClicksBound = true;
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.trasee-item[data-route-geometry]');
        if (!button) return;
        pendingGeometry = geometryFromRouteButton(button);
        scheduleRefresh(0);
    });
}
function bindLivewire() {
    if (!window.Livewire || window.__traseuPreviewLivewireBound) return;
    window.__traseuPreviewLivewireBound = true;
    const redraw = (payload = null) => {
        const route = payload?.route ?? payload?.detail?.route ?? null;
        if (route?.geometry) pendingGeometry = route.geometry;
        scheduleRefresh(50);
    };
    Livewire.on('route-selected', redraw);
    // Importul nu selectează automat traseul. Preview-ul apare doar după click pe un traseu.
    // Pentru importurile Google, selectedRouteId este setat de componentă, iar morph-ul
    // va reîmprospăta preview-ul pe baza containerului randat.
    if (typeof Livewire.hook === 'function') Livewire.hook('morphed', () => scheduleRefresh(20));
}
function initialize() {
    if (!document.querySelector('.trasee-page')) return;
    bindRouteClicks();
    if (window.Livewire) bindLivewire();
    else document.addEventListener('livewire:init', bindLivewire, { once: true });
    scheduleRefresh(0);
    initialized = true;
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
else initialize();
window.addEventListener('pageshow', initialize);
window.addEventListener('resize', () => { if (routeMap) routeMap.updateSize(); });
window.TraseuPreview = { refresh: refreshPreview, show: (geometry) => { pendingGeometry = geometry; refreshPreview(); } };
