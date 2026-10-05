export function groupTransitRoutes(routes) {
    const groups = new Map();

    routes.forEach(route => {
        if (!route || typeof route !== 'object' || typeof route.label !== 'string') return;

        const label = route.label.trim();
        if (!label) return;

        const key = label.toLocaleLowerCase('ro-RO');
        let group = groups.get(key);
        if (!group) {
            group = { key, label, routes: [] };
            groups.set(key, group);
        }
        group.routes.push(route);
    });

    return Array.from(groups.values());
}

export function splitTransitRouteDirections(groups) {
    return groups.flatMap(group => {
        const directions = new Map();
        group.routes.forEach(route => {
            const direction = typeof route.direction === 'string' ? route.direction.trim() : '';
            if (!direction) return;

            const directionKey = direction.toLocaleLowerCase('ro-RO');
            if (!directions.has(directionKey)) {
                directions.set(directionKey, { label: direction, routes: [] });
            }
            directions.get(directionKey).routes.push(route);
        });

        if (directions.size < 2) return [group];

        return [...directions.entries()].map(([directionKey, direction]) => ({
            ...group,
            key: `${group.key}::${directionKey}`,
            directionLabel: direction.label,
            routes: direction.routes,
        }));
    });
}

export function getPoiMarkerIcon(type, provider = null) {
    if (provider === 'Waze') return '🚔';
    if (['police', 'speed_limit', 'control', 'traffic_sign', 'locality', 'speed_camera', 'vignette_control'].includes(type)) {
        return '👮';
    }

    return null;
}

export function buildPoiCategoryRequests(selectedTypes, {
    transitEndpoints,
    categoryEndpoints,
    wazeEndpoint,
    categoryLabels,
    lat,
    lon,
    radius,
}) {
    const categoryForToken = token => {
        if (Object.hasOwn(transitEndpoints, token) || Object.hasOwn(categoryEndpoints, token)) return token;
        const policeSubcategory = token.match(/^subcategory:police:(speed_limit|control|traffic_sign|locality|speed_camera|vignette_control)$/);
        if (policeSubcategory) return policeSubcategory[1];
        return token.match(/^subcategory:([^:]+):/)?.[1] ?? null;
    };
    const selectedCategories = [...new Set(selectedTypes.map(categoryForToken).filter(Boolean))];
    const requests = selectedCategories
        .filter(category => Object.hasOwn(transitEndpoints, category))
        .map(category => {
            const params = new URLSearchParams({
                lat: String(lat),
                lon: String(lon),
                radius: String(radius),
            });
            if (category === 'train') params.set('include_routes', 'false');

            return {
                category,
                label: categoryLabels.get(category) || category,
                url: `${transitEndpoints[category]}?${params.toString()}`,
            };
        });
    const poiFilters = selectedTypes.filter(token => !Object.hasOwn(transitEndpoints, categoryForToken(token)));

    if (poiFilters.length > 0) {
        const labels = [...new Set(poiFilters.map(token => {
            const parent = token.match(/^subcategory:([^:]+):/)?.[1];
            const category = parent || categoryForToken(token);

            return categoryLabels.get(category) || category;
        }))];
        const params = new URLSearchParams({
            lat: String(lat),
            lon: String(lon),
            radius: String(radius),
            types: poiFilters.join(','),
        });
        requests.push({
            category: 'poi',
            label: labels.join(', '),
            url: `/api/transport-nearby?${params.toString()}`,
        });
    }

    if (selectedTypes.includes('subcategory:police:traffic_filters')) {
        const params = new URLSearchParams({
            lat: String(lat),
            lon: String(lon),
            radius: String(radius),
        });
        requests.push({
            category: 'waze-traffic',
            label: 'Filtre în trafic (Waze)',
            url: `${wazeEndpoint}?${params.toString()}`,
        });
    }

    return requests;
}

export async function fetchPoiJsonResponse(url, {
    timeoutMs = 35_000,
    fetchImpl = fetch,
} = {}) {
    const controller = new AbortController();
    let timedOut = false;
    const timeout = setTimeout(() => {
        timedOut = true;
        controller.abort();
    }, timeoutMs);

    try {
        const response = await fetchImpl(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        return await readPoiJsonResponse(response);
    } catch (error) {
        if (timedOut) {
            throw new Error(`Cererea POI a expirat la limita de ${Math.ceil(timeoutMs / 1000)} secunde.`);
        }

        throw error;
    } finally {
        clearTimeout(timeout);
    }
}

export async function readPoiJsonResponse(response) {
    const responseUrl = response.url ? new URL(response.url, 'http://localhost') : null;
    if (response.redirected && responseUrl?.pathname.endsWith('/login')) {
        throw new Error('Sesiunea a expirat. Autentifică-te din nou și reîncarcă categoriile.');
    }

    const contentType = response.headers.get('content-type') || '';
    if (!/\bapplication\/(?:[\w.+-]*\+)?json\b/i.test(contentType)) {
        const endpoint = responseUrl?.pathname || 'endpoint-ul POI';
        throw new Error(
            `Serverul a returnat un răspuns non-JSON pentru ${endpoint} (HTTP ${response.status}). Verifică autentificarea sau configurația rutei API.`
        );
    }

    let payload;
    try {
        payload = await response.json();
    } catch {
        throw new Error('Serverul a returnat JSON invalid pentru categoria POI solicitată.');
    }

    if (!response.ok) {
        const validationErrors = Object.values(payload?.errors || {}).flat();
        throw new Error(
            payload?.error
            || validationErrors[0]
            || payload?.message
            || `Cererea API a eșuat (HTTP ${response.status}).`
        );
    }
    if (!Array.isArray(payload)) {
        throw new Error('Serverul a returnat un format neașteptat pentru lista de puncte de interes.');
    }

    return payload;
}

export function getTransitRouteColor(routeKey, assignedColors) {
    if (assignedColors.has(routeKey)) return assignedColors.get(routeKey);

    let hash = 0;
    for (const character of routeKey) {
        hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
    }
    let hue = hash % 360;
    let color = hslToHex(hue);
    const usedColors = new Set(assignedColors.values());
    while (usedColors.has(color)) {
        hue = (hue + 37) % 360;
        color = hslToHex(hue);
    }

    assignedColors.set(routeKey, color);
    return color;
}

export function assignTransitRouteColors(groups, assignedColors, manuallySelectedKeys = new Set()) {
    const routeKeys = new Set(groups.map(group => group.key));
    const usedHues = [...manuallySelectedKeys]
        .filter(key => routeKeys.has(key) && assignedColors.has(key))
        .map(key => hexToHue(assignedColors.get(key)));

    groups.forEach(group => {
        if (manuallySelectedKeys.has(group.key)) return;

        let hue;
        if (usedHues.length === 0) {
            let hash = 0;
            for (const character of group.key) {
                hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
            }
            hue = hash % 360;
        } else {
            let greatestDistance = -1;
            for (let candidate = 0; candidate < 360; candidate += 1) {
                const distance = Math.min(...usedHues.map(usedHue => {
                    const difference = Math.abs(candidate - usedHue);
                    return Math.min(difference, 360 - difference);
                }));
                if (distance > greatestDistance) {
                    greatestDistance = distance;
                    hue = candidate;
                }
            }
        }

        assignedColors.set(group.key, hslToHex(hue));
        usedHues.push(hue);
    });
}

export function getInfoferStationUrl(stationName, date = new Date()) {
    const normalizedName = stationName
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('ro-RO')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
    const stationAliases = new Map([
        ['gara de nord', 'Bucuresti-Nord'],
        ['gara bucuresti nord', 'Bucuresti-Nord'],
        ['bucuresti gara de nord', 'Bucuresti-Nord'],
        ['bucuresti nord gara', 'Bucuresti-Nord'],
    ]);
    const generatedSlug = stationName
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
    const slug = stationAliases.get(normalizedName)
        || generatedSlug;
    const dateParts = new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: 'Europe/Bucharest',
    }).formatToParts(date);
    const part = type => dateParts.find(item => item.type === type)?.value;
    const day = part('day');
    const month = part('month');
    const year = part('year');

    return `https://mersultrenurilor.infofer.ro/ro-RO/Statie/${encodeURIComponent(slug)}?Date=${day}.${month}.${year}`;
}

export function getGoogleMapsPlaceUrl(name, coordinates) {
    const lat = Number(coordinates?.lat);
    const lon = Number(coordinates?.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon) || lat < -90 || lat > 90 || lon < -180 || lon > 180) {
        return null;
    }

    const query = [name, lat, lon]
        .filter(value => value !== null && value !== undefined && String(value).trim() !== '')
        .join(', ');
    const params = new URLSearchParams({
        api: '1',
        query,
    });

    return `https://www.google.com/maps/search/?${params.toString()}`;
}

export function getMetroArrivalWindow(now = new Date(), intervalMinutes = 5) {
    const formatTime = date => new Intl.DateTimeFormat('ro-RO', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Bucharest',
    }).format(date);

    return {
        from: formatTime(now),
        until: formatTime(new Date(now.getTime() + intervalMinutes * 60_000)),
    };
}

function hslToHex(hue) {
    const saturation = 0.75;
    const lightness = 0.45;
    const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation;
    const hueSector = hue / 60;
    const secondary = chroma * (1 - Math.abs((hueSector % 2) - 1));
    const [red, green, blue] = hueSector < 1
        ? [chroma, secondary, 0]
        : hueSector < 2
            ? [secondary, chroma, 0]
            : hueSector < 3
                ? [0, chroma, secondary]
                : hueSector < 4
                    ? [0, secondary, chroma]
                    : hueSector < 5
                        ? [secondary, 0, chroma]
                        : [chroma, 0, secondary];
    const offset = lightness - chroma / 2;
    return `#${[red, green, blue]
        .map(channel => Math.round((channel + offset) * 255).toString(16).padStart(2, '0'))
        .join('')}`;
}

function hexToHue(color) {
    const channels = color.match(/^#?([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i);
    if (!channels) return 0;

    const [red, green, blue] = channels.slice(1).map(channel => Number.parseInt(channel, 16) / 255);
    const maximum = Math.max(red, green, blue);
    const minimum = Math.min(red, green, blue);
    const difference = maximum - minimum;
    if (difference === 0) return 0;

    const hue = maximum === red
        ? ((green - blue) / difference) % 6
        : maximum === green
            ? (blue - red) / difference + 2
            : (red - green) / difference + 4;

    return Math.round(hue * 60 + 360) % 360;
}

// OpenLayers reads hit-detection canvases back on every pointer move; this hint keeps them in CPU memory.
export function withReadbackCanvas(callback) {
    if (typeof HTMLCanvasElement === 'undefined') return callback();

    const original = HTMLCanvasElement.prototype.getContext;
    HTMLCanvasElement.prototype.getContext = function (type, options) {
        return original.call(this, type, type === '2d' ? { willReadFrequently: true, ...options } : options);
    };
    try {
        return callback();
    } finally {
        HTMLCanvasElement.prototype.getContext = original;
    }
}

export function poiFeatureAtPixel(map, poiSource, poiLayer, pixel) {
    const hitFeature = withReadbackCanvas(() => map.forEachFeatureAtPixel(pixel, feature => feature, {
        hitTolerance: 12,
        layerFilter: layer => layer === poiLayer,
    }));
    if (hitFeature) return hitFeature;

    let nearestFeature;
    let nearestDistance = 32;
    poiSource.getFeatures().forEach(feature => {
        const coordinates = feature.getGeometry()?.getCoordinates();
        if (!Array.isArray(coordinates) || coordinates.length < 2) return;

        const featurePixel = map.getPixelFromCoordinate(coordinates);
        const distance = Math.hypot(
            featurePixel[0] - pixel[0],
            featurePixel[1] - pixel[1]
        );
        if (distance <= nearestDistance) {
            nearestFeature = feature;
            nearestDistance = distance;
        }
    });

    return nearestFeature;
}

export function bindPoiMapClick({
    target,
    map,
    poiSource,
    poiLayer,
    tooltipElement,
    showTooltip,
    hideTooltip,
}) {
    const handleClick = event => {
        const viewport = map.getViewport();
        const viewportRect = viewport.getBoundingClientRect();
        if (
            event.clientX < viewportRect.left
            || event.clientX > viewportRect.right
            || event.clientY < viewportRect.top
            || event.clientY > viewportRect.bottom
        ) return;
        if (event.target.closest?.('.modal, .modal-backdrop')) return;
        if (tooltipElement.contains(event.target)) return;

        const pixel = map.getEventPixel(event);
        const feature = poiFeatureAtPixel(map, poiSource, poiLayer, pixel);
        if (feature) {
            showTooltip(feature, map.getCoordinateFromPixel(pixel));
        } else {
            hideTooltip();
        }
    };

    target.addEventListener('click', handleClick, { capture: true });

    return () => target.removeEventListener('click', handleClick, { capture: true });
}
