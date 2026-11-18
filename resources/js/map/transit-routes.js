/**
 * Transit lines drawn on the map: ticking a line (tooltip, transit lines modal or the stop panel) loads
 * its OpenStreetMap relation geometry and draws it in the line's colour; colours can be changed.
 */
import Feature from 'ol/Feature.js';
import MultiLineString from 'ol/geom/MultiLineString.js';
import VectorLayer from 'ol/layer/Vector.js';
import { fromLonLat } from 'ol/proj.js';
import VectorSource from 'ol/source/Vector.js';
import Stroke from 'ol/style/Stroke.js';
import Style from 'ol/style/Style.js';
import { fetchJson, HttpError } from '../http.js';
import { t } from '../i18n.js';
import { assignTransitRouteColors, getTransitRouteColor } from './poi-map-interactions.js';
import { loadBusArrival, updateMetroArrivalEstimate } from './poi-tooltip.js';
import {
    activeTransitRoutes,
    manuallySelectedTransitRouteColors,
    mapState,
    transitRouteColors,
    visibleTransitRoutes,
} from './state.js';

const transitRouteSource = new VectorSource();
const unavailableTransitRouteGeometry = new Set();

/** The layer of the drawn lines, added to the map by the page. */
export const transitRouteLayer = new VectorLayer({
    source: transitRouteSource,
    style: feature => new Style({
        stroke: new Stroke({
            color: feature.get('routeColor') || '#7c3aed',
            width: 5,
            lineCap: 'round',
            lineJoin: 'round',
        }),
    }),
});

document.addEventListener('change', async event => {
    const colorPicker = event.target.closest('.poi-route-color');
    if (colorPicker) {
        const routeKey = colorPicker.dataset.routeKey;
        transitRouteColors.set(routeKey, colorPicker.value);
        manuallySelectedTransitRouteColors.add(routeKey);
        const routeFeature = transitRouteSource.getFeatureById(`transit-route:${routeKey}`);
        routeFeature?.set('routeColor', colorPicker.value);
        return;
    }

    const checkbox = event.target.closest('.poi-route-checkbox');
    if (!checkbox) return;

    if (checkbox.dataset.geometryUnavailable === 'true') {
        checkbox.checked = false;
        return;
    }

    updateMetroArrivalEstimate();

    const routeKey = checkbox.dataset.routeKey;
    const routeGroup = activeTransitRoutes.get(routeKey);
    if (!routeGroup) return;

    const featureId = `transit-route:${routeKey}`;
    let routeFeature = transitRouteSource.getFeatureById(featureId);

    if (checkbox.checked) {
        if (!routeFeature) {
            const geometryRun = ++mapState.transitRouteGeometryRun;
            checkbox.disabled = true;
            try {
                const routeGeometries = await Promise.all(
                    routeGroup.routes.map(async route => {
                        const match = /^relation\/(\d+)$/.exec(route.id);
                        if (!match) return [];
                        if (unavailableTransitRouteGeometry.has(route.id)) return [];

                        let data;
                        try {
                            data = await fetchJson(`/api/transit-route/${match[1]}`);
                        } catch (error) {
                            if (error instanceof HttpError && error.status === 404) {
                                unavailableTransitRouteGeometry.add(route.id);
                                return [];
                            }
                            throw error;
                        }

                        const segments = Array.isArray(data.segments)
                            ? data.segments
                                .filter(segment => Array.isArray(segment) && segment.length >= 2)
                                .map(segment => segment
                                    .filter(coordinate => Array.isArray(coordinate) && coordinate.length >= 2)
                                    .map(([lon, lat]) => fromLonLat([lon, lat]))
                                )
                            : [];
                        if (segments.length === 0) unavailableTransitRouteGeometry.add(route.id);

                        return segments;
                    })
                );
                const lineCoordinates = routeGeometries.flat()
                    .filter(coordinates => coordinates.length >= 2);

                if (geometryRun !== mapState.transitRouteGeometryRun || !checkbox.checked) return;

                if (lineCoordinates.length === 0) {
                    throw new Error(t('OpenStreetMap did not return the route geometry.'));
                }

                routeFeature = new Feature({
                    geometry: new MultiLineString(lineCoordinates),
                });
                routeFeature.setId(featureId);
                routeFeature.set('routeKey', routeKey);
                routeFeature.set('routeColor', transitRouteColors.get(routeKey));
                transitRouteSource.addFeature(routeFeature);
                visibleTransitRoutes.add(routeKey);
            } catch (error) {
                if (geometryRun !== mapState.transitRouteGeometryRun || !checkbox.checked) return;
                checkbox.checked = false;
                if (checkbox._routeButton) {
                    checkbox.dataset.geometryUnavailable = 'true';
                    checkbox._routeButton.title = t('The timetable can be viewed; the route geometry is not available in OpenStreetMap.');
                    checkbox._dashboardRouteButton?.classList.remove('is-selected');
                    if (checkbox._dashboardRouteButton) {
                        const status = checkbox._dashboardRouteButton.parentElement?.querySelector('.dashboard-stop-route-status');
                        if (status) {
                            status.textContent = t('The route geometry is not available in OpenStreetMap.');
                            status.hidden = false;
                        }
                    }
                } else {
                    console.error('Transit route geometry could not be loaded:', error);
                    Swal.fire({
                        icon: 'error',
                        title: t('The route could not be loaded'),
                        text: error.message,
                    });
                }
            } finally {
                checkbox.disabled = false;
            }
        } else {
            visibleTransitRoutes.add(routeKey);
        }
    } else {
        if (routeFeature) {
            transitRouteSource.removeFeature(routeFeature);
        }
        visibleTransitRoutes.delete(routeKey);
    }
});

window.addEventListener('symbiot:osm-route-toggle', (event) => {
    const { group, feature, checkbox, button, status } = event.detail ?? {};
    if (!group || !feature || !checkbox || !button) return;

    if (checkbox.checked || visibleTransitRoutes.has(group.key)) {
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        button.classList.remove('is-selected');
        status.hidden = true;
        window.dispatchEvent(new CustomEvent('symbiot:osm-route-state', {
            detail: { routeKey: group.key, selected: false },
        }));
        return;
    }

    clearVisibleTransitRoutes();
    activeTransitRoutes.set(group.key, group);
    assignTransitRouteColors([group], transitRouteColors, manuallySelectedTransitRouteColors);
    checkbox._routeButton = button;
    button.classList.add('is-selected');
    button.querySelector('.dashboard-stop-route-badge')?.style.setProperty('--transit-route-color', getTransitRouteColor(group.key, transitRouteColors));
    button.querySelector('.dashboard-stop-route-badge')?.style.setProperty('background-color', getTransitRouteColor(group.key, transitRouteColors));
    checkbox.checked = true;
    status.textContent = t('Loading route…');
    status.hidden = false;
    window.dispatchEvent(new CustomEvent('symbiot:osm-route-state', {
        detail: { routeKey: group.key, selected: true, color: getTransitRouteColor(group.key, transitRouteColors) },
    }));
    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
    loadBusArrival(feature, group, status);
});

/** Removes every drawn line (a new stop, location or POI selection). */
export function clearVisibleTransitRoutes() {
    mapState.transitRouteGeometryRun++;
    visibleTransitRoutes.clear();
    activeTransitRoutes.clear();
    transitRouteSource.clear();
    window.dispatchEvent(new CustomEvent('symbiot:osm-route-state', { detail: { clear: true } }));
}
