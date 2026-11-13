import Feature from 'ol/Feature.js';
import VectorLayer from 'ol/layer/Vector.js';
import VectorSource from 'ol/source/Vector.js';
import Cluster from 'ol/source/Cluster.js';
import LineString from 'ol/geom/LineString.js';
import Point from 'ol/geom/Point.js';
import CircleStyle from 'ol/style/Circle.js';
import Fill from 'ol/style/Fill.js';
import Stroke from 'ol/style/Stroke.js';
import Style from 'ol/style/Style.js';
import Text from 'ol/style/Text.js';
import { fromLonLat } from 'ol/proj.js';
import { movedFarEnough } from '../vehicle-center.js';
import { t } from '../i18n.js';

const ROUTE_PALETTE = ['#e6194b', '#3cb44b', '#0d6efd', '#f58231', '#911eb4', '#008080', '#f032e6', '#9a6324', '#800000', '#808000', '#000075', '#e6a800'];

function fetchJson(url) {
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))));
}

/**
 * Draws GTFS stops stored locally and shows the lines serving a clicked stop.
 * Stops come from /api/transit/stops (database only, no external requests).
 */
export function createTransitLayer(map) {
    const stopSource = new VectorSource();
    const stopClusterSource = new Cluster({ distance: 36, minDistance: 12, source: stopSource });
    const updateStopClusterDistance = () => {
        const individualStopsZoom = (map.getView().getZoom() ?? 0) >= 16;
        stopClusterSource.setDistance(individualStopsZoom ? 0 : 36);
        stopClusterSource.setMinDistance(individualStopsZoom ? 0 : 12);
    };
    map.getView().on('change:resolution', updateStopClusterDistance);
    const shapeSource = new VectorSource();

    const shapeLayer = new VectorLayer({
        source: shapeSource,
        zIndex: 40,
        style: (feature) => new Style({
            stroke: new Stroke({ color: feature.get('color'), width: 5, lineCap: 'round', lineJoin: 'round' }),
        }),
    });
    const stopLayer = new VectorLayer({
        source: stopClusterSource,
        zIndex: 41,
        style: (feature) => {
            const stops = feature.get('features') || [];
            const count = stops.length;
            const showIndividualStops = (map.getView().getZoom() ?? 0) >= 16;
            return new Style({
                image: new CircleStyle({
                    radius: showIndividualStops ? 5 : count > 1 ? Math.min(18, 9 + Math.log2(count)) : 5,
                    fill: new Fill({ color: count > 1 ? '#0759c7' : '#0d6efd' }),
                    stroke: new Stroke({ color: '#fff', width: count > 1 ? 2 : 1.5 }),
                }),
                text: count > 1 && !showIndividualStops ? new Text({
                    text: String(count),
                    font: 'bold 11px sans-serif',
                    fill: new Fill({ color: '#fff' }),
                }) : undefined,
            });
        },
    });
    const vehicleSource = new VectorSource();
    const vehicleLayer = new VectorLayer({
        source: vehicleSource,
        zIndex: 42,
        minZoom: 11.5,
        style: (feature) => new Style({
            image: new CircleStyle({
                radius: 11,
                fill: new Fill({ color: feature.get('color') ? `#${feature.get('color')}` : '#dc3545' }),
                stroke: new Stroke({ color: '#fff', width: 2 }),
            }),
            text: new Text({
                text: String(feature.get('routeName') || ''),
                font: 'bold 10px sans-serif',
                fill: new Fill({ color: feature.get('textColor') ? `#${feature.get('textColor')}` : '#fff' }),
            }),
        }),
    });
    [shapeLayer, stopLayer, vehicleLayer].forEach((layer) => {
        layer.setVisible(false);
        map.addLayer(layer);
    });

    let selection = 0;
    // Routes currently drawn, keyed by "feed:route", each with its own colour.
    const activeRoutes = new Map();

    function routeKey(feed, route) {
        return `${feed}:${route.route_id}`;
    }

    function routeColor(route, key) {
        const supplied = String(route.color ?? '').replace(/^#/, '');
        if (/^[0-9a-f]{6}$/i.test(supplied)) return `#${supplied}`;

        let hash = 0;
        for (let index = 0; index < key.length; index++) {
            hash = ((hash << 5) - hash + key.charCodeAt(index)) | 0;
        }
        return ROUTE_PALETTE[Math.abs(hash) % ROUTE_PALETTE.length];
    }

    function paintChip(button, color) {
        button.style.cssText = color
            ? `background:${color};border:2px solid ${color};color:#fff;`
            : 'background:#fff;border:2px solid #6c757d;color:#212529;';
    }

    async function toggleRoute(feed, stopId, stopName, route, button, schedule) {
        const key = routeKey(feed, route);
        const active = activeRoutes.get(key);
        if (active) {
            active.features.forEach((line) => shapeSource.removeFeature(line));
            activeRoutes.delete(key);
            paintChip(button, null);
            schedule.hidden = true;
            window.dispatchEvent(new CustomEvent('symbiot:transit-route-selected', {
                detail: { selected: false },
            }));

            return;
        }

        // Keep a single highlighted route at a time.
        if (activeRoutes.size > 0) {
            clearRoutes();
        }

        const color = routeColor(route, key);
        const entry = { color, features: [] };
        activeRoutes.set(key, entry);
        paintChip(button, color);
        const routeDetail = {
            selected: true,
            feed,
            stopId,
            stopName,
            route,
            color,
        };
        window.dispatchEvent(new CustomEvent('symbiot:transit-route-selected', { detail: routeDetail }));
        schedule.textContent = t('Searching for the next scheduled departure…');
        schedule.hidden = false;
        fetchJson(`/api/transit/stops/${encodeURIComponent(feed)}/${encodeURIComponent(stopId)}/next-departure?route_id=${encodeURIComponent(route.route_id)}`)
            .then(({ departure, reason }) => {
                if (activeRoutes.get(key) !== entry) return;
                if (!departure) {
                    schedule.textContent = reason === 'schedule_unavailable'
                        ? t('The timetable is not available for this route.')
                        : t('No departure is scheduled in the coming days.');
                    window.dispatchEvent(new CustomEvent('symbiot:transit-route-schedule', {
                        detail: { ...routeDetail, departure: null, reason },
                    }));
                    return;
                }
                const countdown = departure.minutes_until === 0 ? t('now') : t('in :minutes min', { minutes: departure.minutes_until });
                schedule.textContent = departure.headsign
                    ? t('Next departure: :time (:countdown) to :headsign · scheduled timetable', { time: departure.time, countdown, headsign: departure.headsign })
                    : t('Next departure: :time (:countdown) · scheduled timetable', { time: departure.time, countdown });
                window.dispatchEvent(new CustomEvent('symbiot:transit-route-schedule', {
                    detail: { ...routeDetail, departure },
                }));
            })
            .catch(() => {
                if (activeRoutes.get(key) === entry) {
                    schedule.textContent = t('The timetable is not available.');
                    window.dispatchEvent(new CustomEvent('symbiot:transit-route-schedule', {
                        detail: { ...routeDetail, departure: null, reason: 'schedule_unavailable' },
                    }));
                }
            });

        try {
            const data = await fetchJson(`/api/transit/routes/${encodeURIComponent(feed)}/${encodeURIComponent(route.route_id)}/shape?stop_id=${encodeURIComponent(stopId)}`);
            if (activeRoutes.get(key) !== entry) {
                return;
            }
            data.shapes.forEach((points) => {
                const line = new Feature(new LineString(points.map(([lon, lat]) => fromLonLat([lon, lat]))));
                line.set('color', color);
                entry.features.push(line);
                shapeSource.addFeature(line);
            });
            window.dispatchEvent(new CustomEvent('symbiot:transit-route-shape', {
                detail: { ...routeDetail, shapes: data.shapes },
            }));
        } catch {
            activeRoutes.delete(key);
            paintChip(button, null);
            window.dispatchEvent(new CustomEvent('symbiot:transit-route-selected', {
                detail: { selected: false },
            }));
        }
    }

    function clearRoutes() {
        activeRoutes.clear();
        shapeSource.clear();
        window.dispatchEvent(new CustomEvent('symbiot:transit-route-selected', {
            detail: { selected: false },
        }));
    }

    const resetTransitMap = () => {
        // Invalidate pending requests and remove features from the previous city.
        loadRun++;
        clearInterval(vehicleTimer);
        vehicleTimer = null;
        vehicleRun++;
        stopLayer.setVisible(false);
        vehicleLayer.setVisible(false);
        shapeLayer.setVisible(false);
        stopSource.clear();
        vehicleSource.clear();
        clearRoutes();
    };
    window.addEventListener('symbiot:location-changing', resetTransitMap);
    window.addEventListener('symbiot:location-set', resetTransitMap);

    window.addEventListener('symbiot:fit-transit-route', () => {
        const extent = shapeSource.getExtent();
        if (extent.every(Number.isFinite)) {
            map.getView().fit(extent, {
                padding: [48, 48, 48, 48],
                maxZoom: 16,
                duration: 350,
            });
        }
    });

    window.addEventListener('symbiot:transit-route-toggle', (event) => {
        const { feed, stopId, stopName, route } = event.detail ?? {};
        if (!feed || !stopId || !route) return;
        const button = document.createElement('button');
        const schedule = document.createElement('span');
        toggleRoute(feed, stopId, stopName, route, button, schedule);
    });

    async function showStop(feature) {
        const current = ++selection;
        const feed = feature.get('feedId');
        const stopId = feature.get('stopId');
        const stopName = feature.get('name');
        window.dispatchEvent(new CustomEvent('symbiot:transit-stop-routes', {
            detail: { feed, stopId, stopName, routes: [], loading: true },
        }));

        try {
            const data = await fetchJson(`/api/transit/stops/${encodeURIComponent(feed)}/${encodeURIComponent(stopId)}/routes`);
            if (current !== selection) {
                return;
            }
            window.dispatchEvent(new CustomEvent('symbiot:transit-stop-routes', {
                detail: {
                    feed,
                    stopId,
                    stopName,
                    routes: data.routes,
                },
            }));
        } catch {
            if (current !== selection) return;
            window.dispatchEvent(new CustomEvent('symbiot:transit-stop-routes', {
                detail: {
                    feed,
                    stopId,
                    stopName,
                    routes: [],
                    error: true,
                },
            }));
        }
    }

    map.on('singleclick', (event) => {
        let feature;
        let nearestDistance = 10;
        stopSource.getFeatures().forEach((candidate) => {
            const coordinates = candidate.getGeometry()?.getCoordinates();
            if (!Array.isArray(coordinates)) return;

            const candidatePixel = map.getPixelFromCoordinate(coordinates);
            const distance = Math.hypot(candidatePixel[0] - event.pixel[0], candidatePixel[1] - event.pixel[1]);
            if (distance <= nearestDistance) {
                feature = candidate;
                nearestDistance = distance;
            }
        });

        if (!feature) {
            selection++;
            return;
        }

        showStop(feature);
    });

    let loadRun = 0;
    let vehicleTimer = null;
    let vehicleRun = 0;
    // Where live vehicles are searched; follows the user in GPS mode (moveVehicleCenter).
    let vehicleCenter = null;

    async function refreshVehicles(run) {
        if (document.hidden || !vehicleCenter) {
            return;
        }
        const { lat, lon, radius } = vehicleCenter;
        try {
            const data = await fetchJson(`/api/transit/vehicles?lat=${lat}&lon=${lon}&radius=${radius}`);
            if (run !== vehicleRun) {
                return;
            }
            vehicleSource.clear();
            vehicleSource.addFeatures(data.vehicles.map((vehicle) => {
                const feature = new Feature(new Point(fromLonLat([vehicle.lon, vehicle.lat])));
                feature.setProperties({ routeName: vehicle.route_name, color: vehicle.color, textColor: vehicle.text_color });

                return feature;
            }));
        } catch {
            // The next tick retries; live data is optional.
        }
    }

    function startVehicleTimer() {
        clearInterval(vehicleTimer);
        vehicleSource.clear();
        const run = ++vehicleRun;
        refreshVehicles(run);
        vehicleTimer = setInterval(() => refreshVehicles(run), 10000);
    }

    return {
        hasStopAtPixel(pixel, tolerance = 12) {
            if (!stopLayer.getVisible()) return false;
            return stopSource.getFeatures().some((feature) => {
                const coordinates = feature.getGeometry()?.getCoordinates();
                if (!Array.isArray(coordinates)) return false;
                const point = map.getPixelFromCoordinate(coordinates);
                return Math.hypot(point[0] - pixel[0], point[1] - pixel[1]) <= tolerance;
            });
        },

        watchVehicles(lat, lon, radius = 3000) {
            vehicleCenter = { lat, lon, radius };
            startVehicleTimer();
        },

        /** GPS mode: keep live vehicles around the user instead of the first position. */
        moveVehicleCenter(lat, lon) {
            if (!vehicleCenter) return;
            const refreshNow = movedFarEnough(vehicleCenter, lat, lon);
            vehicleCenter = { ...vehicleCenter, lat, lon };
            if (refreshNow && vehicleTimer !== null) {
                refreshVehicles(vehicleRun);
            }
        },

        setVisible(visible) {
            stopLayer.setVisible(visible);
            vehicleLayer.setVisible(visible);
            shapeLayer.setVisible(visible);

            // Re-enabling the bus filter resumes live vehicles for the current area.
            if (visible && vehicleTimer === null && vehicleCenter) {
                startVehicleTimer();
            }

            if (!visible) {
                clearInterval(vehicleTimer);
                vehicleTimer = null;
                vehicleRun++;

                vehicleSource.clear();
                selection++;

                clearRoutes();
            }
        },

        async load(lat, lon, radius = 1500) {
            const run = ++loadRun;
            selection++;
            clearRoutes();
            const data = await fetchJson(`/api/transit/stops?lat=${lat}&lon=${lon}&radius=${radius}`);
            if (run !== loadRun) {
                return false;
            }
            stopSource.clear();
            stopSource.addFeatures(data.stops.map((stop) => {
                const feature = new Feature(new Point(fromLonLat([stop.lon, stop.lat])));
                feature.setProperties({ name: stop.name, feedId: stop.feed_id, stopId: stop.stop_id });

                return feature;
            }));

            updateStopClusterDistance();
            if (data.stops.length > 0 && (map.getView().getZoom() ?? 0) < 16) {
                map.getView().animate({ zoom: 16, duration: 450 });
            }

            window.dispatchEvent(new CustomEvent('symbiot:nearby-transit-stops', {
                detail: { stops: data.stops, lat, lon },
            }));

            return data.stops.length > 0;
        },
    };
}
