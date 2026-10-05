import Feature from 'ol/Feature.js';
import Overlay from 'ol/Overlay.js';
import VectorLayer from 'ol/layer/Vector.js';
import VectorSource from 'ol/source/Vector.js';
import LineString from 'ol/geom/LineString.js';
import Point from 'ol/geom/Point.js';
import CircleStyle from 'ol/style/Circle.js';
import Fill from 'ol/style/Fill.js';
import Stroke from 'ol/style/Stroke.js';
import Style from 'ol/style/Style.js';
import Text from 'ol/style/Text.js';
import { fromLonLat } from 'ol/proj.js';

const ROUTE_PALETTE = ['#e6194b', '#3cb44b', '#0d6efd', '#f58231', '#911eb4', '#008080', '#f032e6', '#9a6324', '#800000', '#808000', '#000075', '#e6a800'];

const MODE_LABELS = { 0: 'Tramvai', 1: 'Metrou', 2: 'Tren', 3: 'Autobuz', 11: 'Troleibuz' };

function modeLabel(type) {
    if (MODE_LABELS[type]) {
        return MODE_LABELS[type];
    }

    return type >= 100 && type < 200 ? 'Tren' : 'Transport';
}

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
    const shapeSource = new VectorSource();

    const shapeLayer = new VectorLayer({
        source: shapeSource,
        zIndex: 40,
        style: (feature) => new Style({
            stroke: new Stroke({ color: feature.get('color'), width: 5, lineCap: 'round', lineJoin: 'round' }),
        }),
    });
    const stopLayer = new VectorLayer({
        source: stopSource,
        zIndex: 41,
        // minZoom is exclusive, so 12.5 keeps the stops visible at the default city zoom of 13.
        minZoom: 12.5,
        style: new Style({
            image: new CircleStyle({
                radius: 5,
                fill: new Fill({ color: '#0d6efd' }),
                stroke: new Stroke({ color: '#fff', width: 2 }),
            }),
        }),
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

    const popupElement = document.createElement('div');
    popupElement.className = 'card shadow-sm p-2 small';
    popupElement.style.cssText = 'min-width:200px;max-width:280px;';
    const popup = new Overlay({
        element: popupElement,
        offset: [0, -10],
        positioning: 'bottom-center',
        stopEvent: true,
    });
    map.addOverlay(popup);

    let selection = 0;
    // Routes currently drawn, keyed by "feed:route", each with its own colour.
    const activeRoutes = new Map();

    function routeKey(feed, route) {
        return `${feed}:${route.route_id}`;
    }

    function nextColor() {
        const used = new Set(Array.from(activeRoutes.values()).map((entry) => entry.color));

        return ROUTE_PALETTE.find((color) => !used.has(color)) ?? ROUTE_PALETTE[activeRoutes.size % ROUTE_PALETTE.length];
    }

    function paintChip(button, color) {
        button.style.cssText = color
            ? `background:${color};border:2px solid ${color};color:#fff;`
            : 'background:#fff;border:2px solid #6c757d;color:#212529;';
    }

    function chip(feed, route) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm me-1 mb-1';
        button.textContent = route.short_name || route.long_name || route.route_id;
        button.title = `${modeLabel(route.route_type)}${route.long_name ? `: ${route.long_name}` : ''}`;
        paintChip(button, activeRoutes.get(routeKey(feed, route))?.color);
        button.addEventListener('click', () => toggleRoute(feed, route, button));

        return button;
    }

    async function toggleRoute(feed, route, button) {
        const key = routeKey(feed, route);
        const active = activeRoutes.get(key);
        if (active) {
            active.features.forEach((line) => shapeSource.removeFeature(line));
            activeRoutes.delete(key);
            paintChip(button, null);

            return;
        }

        const color = nextColor();
        const entry = { color, features: [] };
        activeRoutes.set(key, entry);
        paintChip(button, color);
        try {
            const data = await fetchJson(`/api/transit/routes/${feed}/${encodeURIComponent(route.route_id)}/shape`);
            if (activeRoutes.get(key) !== entry) {
                return;
            }
            data.shapes.forEach((points) => {
                const line = new Feature(new LineString(points.map(([lon, lat]) => fromLonLat([lon, lat]))));
                line.set('color', color);
                entry.features.push(line);
                shapeSource.addFeature(line);
            });
        } catch {
            activeRoutes.delete(key);
            paintChip(button, null);
        }
    }

    function clearRoutes() {
        activeRoutes.clear();
        shapeSource.clear();
    }

    async function showStop(feature, coordinate) {
        const current = ++selection;
        popupElement.replaceChildren();
        const title = document.createElement('strong');
        title.textContent = feature.get('name');
        const body = document.createElement('div');
        body.className = 'mt-1 text-muted';
        body.textContent = 'Se încarcă liniile…';
        popupElement.append(title, body);
        popup.setPosition(coordinate);

        try {
            const feed = feature.get('feedId');
            const data = await fetchJson(`/api/transit/stops/${feed}/${encodeURIComponent(feature.get('stopId'))}/routes`);
            if (current !== selection) {
                return;
            }
            body.className = 'mt-1';
            body.replaceChildren();
            if (data.routes.length === 0) {
                body.className = 'mt-1 text-muted';
                body.textContent = 'Nicio linie cu orar la această stație.';
            }
            data.routes.forEach((route) => body.append(chip(feed, route)));
        } catch {
            body.textContent = 'Nu am putut încărca liniile.';
        }
    }

    map.on('singleclick', (event) => {
        const feature = map.forEachFeatureAtPixel(event.pixel, (f) => f, { layerFilter: (layer) => layer === stopLayer });
        if (feature) {
            showStop(feature, feature.getGeometry().getCoordinates());
        } else {
            popup.setPosition(undefined);
        }
    });

    let loadRun = 0;
    let vehicleTimer = null;
    let vehicleRun = 0;

    async function refreshVehicles(lat, lon, radius, run) {
        if (document.hidden) {
            return;
        }
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

    return {
        /** True when imported transit data already covers this point, so legacy bus POIs would duplicate it. */
        async covers(lat, lon) {
            try {
                const data = await fetchJson(`/api/transit/stops?lat=${lat}&lon=${lon}&radius=1500`);

                return data.stops.length > 0;
            } catch {
                return false;
            }
        },

        watchVehicles(lat, lon, radius = 3000) {
            clearInterval(vehicleTimer);
            vehicleSource.clear();
            const run = ++vehicleRun;
            refreshVehicles(lat, lon, radius, run);
            vehicleTimer = setInterval(() => refreshVehicles(lat, lon, radius, run), 10000);
        },

        /** Stops, lines and vehicles are drawn only while the bus category is selected. */
        setVisible(visible) {
            stopLayer.setVisible(visible);
            vehicleLayer.setVisible(visible);
            shapeLayer.setVisible(visible);
            if (!visible) {
                popup.setPosition(undefined);
                clearRoutes();
            }
        },

        async load(lat, lon, radius = 1500) {
            const run = ++loadRun;
            clearRoutes();
            popup.setPosition(undefined);
            const data = await fetchJson(`/api/transit/stops?lat=${lat}&lon=${lon}&radius=${radius}`);
            if (run !== loadRun) {
                return;
            }
            stopSource.clear();
            stopSource.addFeatures(data.stops.map((stop) => {
                const feature = new Feature(new Point(fromLonLat([stop.lon, stop.lat])));
                feature.setProperties({ name: stop.name, feedId: stop.feed_id, stopId: stop.stop_id });

                return feature;
            }));
        },
    };
}