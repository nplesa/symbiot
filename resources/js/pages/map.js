import 'ol/ol.css';

import Map from 'ol/Map.js';
import View from 'ol/View.js';

import TileLayer from 'ol/layer/Tile.js';
import VectorLayer from 'ol/layer/Vector.js';

import OSM from 'ol/source/OSM.js';
import VectorSource from 'ol/source/Vector.js';
import Cluster from 'ol/source/Cluster.js';

import Feature from 'ol/Feature.js';

import Point from 'ol/geom/Point.js';
import LineString from 'ol/geom/LineString.js';

import { fromLonLat } from 'ol/proj.js';

import Style from 'ol/style/Style.js';
import Icon from 'ol/style/Icon.js';
import Fill from 'ol/style/Fill.js';
import Stroke from 'ol/style/Stroke.js';
import CircleStyle from 'ol/style/Circle.js';
import Text from 'ol/style/Text.js';

import Circle from 'ol/geom/Circle.js';

import Polygon from 'ol/geom/Polygon.js';
import { circular } from 'ol/geom/Polygon.js';

// =====================================================
// STATE
// =====================================================

let tracking = false;
let watchId = null;

let userFeature = null;
let trackFeature = null;

let lastCoords = null;
let lastPosition = null;

let heading = 0;
let hasCentered = false;

let lastSent = 0;

let currentUserLocation = null;

const radiusEl = document.getElementById('radius');

const app_unit =
    radiusEl?.dataset?.unit?.toLowerCase() ?? 'm';

let app_radius =
    Number(radiusEl?.value ?? 1000);

if(app_unit === 'km') {
    app_radius *= 1000;
}

let radiusFeature = null;


const state = {
    radiusFeature: null,
    userFeature: null,
    trackFeature: null
};

const users = new globalThis.Map();

const activePoiFilters = new Set();

let allPois = null;

let allPoisRaw = [];

const categoryColorMap = new Map();

const POI_CATEGORIES = {    
    airport:  { color: '#0d6efd' },
    bus:      { color: '#198754' },
    train:    { color: '#cddc39' },
    subway:   { color: '#7c3aed' },
    taxi:     { color: '#f59e0b' },
    hospital: { color: '#dc3545' },
    pharmacy: { color: '#db9999' },
    police:   { color: '#2234a9' },
    fire:     { color: '#0a0a0a' },
    tourism:  { color: '#2196f3' },
    transport:{ color: '#6c757d' }
};

function normalizePoiType(type) {
    const map = {
        bus: 'bus',
        bus_station: 'bus',
        train: 'train',
        train_station: 'train',
        subway: 'subway',
        taxi: 'taxi',
        airport: 'airport',
        aerodrom: 'airport',
        airfield: 'airport',
        aeroport: 'airport',
        hospital: 'hospital',
        pharmacy: 'pharmacy',
        police: 'police',
        fire: 'fire',
        tourism: 'tourism',
        transport: 'transport'
    };

    return map[type] || 'transport';
}

function getColorForType(type) {
    return POI_CATEGORIES[type]?.color || '#666';
}

function getDistinctColor(index, total) {
    const goldenAngle = 137.508; // distribuție vizual optimă
    const hue = (index * goldenAngle) % 360;

    return `hsl(${hue}, 75%, 45%)`;
}

function hashColor(str) {
    return getColorForType(str);
}

function getTextColorForBackground(hexColor) {

    hexColor = hexColor.replace('#', '');

    if (hexColor.length === 3) {
        hexColor = hexColor.split('').map(c => c + c).join('');
    }

    const r = parseInt(hexColor.substring(0, 2), 16);
    const g = parseInt(hexColor.substring(2, 4), 16);
    const b = parseInt(hexColor.substring(4, 6), 16);

    const luminance =
        (0.299 * r + 0.587 * g + 0.114 * b);

    return luminance > 186 ? '#000000' : '#ffffff';
}

function filterByRadius(data, lat, lon, radiusMeters) {

    return data.filter(item => {

        const itemLat = item.coordinates?.lat;
        const itemLon = item.coordinates?.lon;

        if (typeof itemLat !== 'number' || typeof itemLon !== 'number') {
            return false;
        }

        const d = getDistanceMeters(lat, lon, itemLat, itemLon);

        return d <= radiusMeters;
    });
}

// =====================================================
// SOURCES
// =====================================================

const vectorSource = new VectorSource();

const userSource = new VectorSource();

const poiSource = new VectorSource();

const clusterSource = new Cluster({
    distance: 40,
    source: userSource,
});


// =====================================================
// STYLES
// =====================================================

const userStyle = new Style({
    image: new Icon({
        src: '/images/user.png',
        scale: 0.05,
        anchor: [0.5, 1]
    })
});

const trackStyle = new Style({
    stroke: new Stroke({
        color: 'rgba(255,0,0,0.8)',
        width: 3
    })
});

const radiusStyle = new Style({

    fill: new Fill({
        color: 'rgba(13,110,253,0.15)'
    }),

    stroke: new Stroke({
        color: '#0d6efd',
        width: 2
    })
});
// =====================================================
// LAYERS
// =====================================================

const baseLayer = new TileLayer({
    source: new OSM()
});

const clusterLayer = new VectorLayer({

    source: clusterSource,

    style: (feature) => {

        const size = feature.get('features').length;

        const type = feature.get('type');

        if (
            activePoiFilters.size > 0 &&
            !activePoiFilters.has(type)
        ) {
            return null;
        }

        const color = getColorForType(type);

        return new Style({

            image: new CircleStyle({

                radius: Math.min(10 + size, 25),

                fill: new Fill({
                    color: 'rgba(0,150,255,0.7)'
                }),

                stroke: new Stroke({
                    color: '#fff',
                    width: 2
                })
            }),

            text: new Text({

                text: size.toString(),

                fill: new Fill({
                    color: '#fff'
                })
            })
        });
    }
});

const poiLayer = new VectorLayer({
    source: poiSource,
    style: (feature) => {

        const type = feature.get('type');

        if (!activePoiFilters.has(type)) {
            return null;
        }

        const color = hashColor(type);

        return new Style({
            image: new CircleStyle({
                radius: 7,
                fill: new Fill({ color }),
                stroke: new Stroke({ color: '#fff', width: 2 })
            }),
            text: new Text({
                text: feature.get('name') || '',
                offsetY: -15,
                fill: new Fill({ color: '#111' }),
                stroke: new Stroke({ color: '#fff', width: 3 })
            })
        });
    }
});

const vectorLayer = new VectorLayer({
    source: vectorSource
});


// =====================================================
// MAP
// =====================================================

const map = new Map({

    target: 'map',

    layers: [
        baseLayer,
        clusterLayer,
        poiLayer,
        vectorLayer
    ],

    view: new View({
        center: fromLonLat([25.6, 45.65]),
        zoom: 12
    })
});


// =====================================================
// CSRF
// =====================================================

const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content');


// =====================================================
// INIT LOCAL FEATURES
// =====================================================

function initLocalFeatures() {

    if (userFeature) return;

    const center = currentUserLocation
        ? fromLonLat([currentUserLocation.lon, currentUserLocation.lat])
        : fromLonLat([25.6, 45.65]);

    userFeature = new Feature({
        geometry: new Point(center)
    });

    userFeature.setStyle(userStyle);

    trackFeature = new Feature({
        geometry: new LineString([])
    });

    trackFeature.setStyle(trackStyle);

    radiusFeature = new Feature({
        geometry: new Circle(
            center,
            Number(app_radius)
        )
    });

    radiusFeature.setGeometry(
        circular(
            fromLonLat([currentUserLocation.lon, currentUserLocation.lat], 'EPSG:4326'),
            Number(app_radius),
            64
        ).transform('EPSG:4326', 'EPSG:3857')
    );

    radiusFeature.setStyle(radiusStyle);

    vectorSource.addFeature(radiusFeature);
    vectorSource.addFeature(trackFeature);
    vectorSource.addFeature(userFeature);
}

// =====================================================
// UPDATE USERS
// =====================================================

function updateUserOnMap(userId, lat, lon) {

    const coords = fromLonLat([lon, lat]);

    let feature = users.get(userId);

    if (!feature) {

        feature = new Feature({
            geometry: new Point(coords)
        });

        userSource.addFeature(feature);

        users.set(userId, feature);

    } else {
        feature
            .getGeometry()
            .setCoordinates(coords);
    }
}


// =====================================================
// RENDER POI
// =====================================================

function renderPOI(data = [], userLat, userLon) {

    poiSource.clear();

    if (!Array.isArray(data)) return;

    const features = [];

    for (const item of data) {

        if (!item?.coordinates) continue;

        const lat = item.coordinates.lat;
        const lon = item.coordinates.lon;

        if (typeof lat !== 'number' || typeof lon !== 'number') continue;

        const distance = getDistanceMeters(userLat, userLon, lat, lon);

        if (distance > Number(app_radius)) continue;

        const coords = fromLonLat([lon, lat]);

        const feature = new Feature({
            geometry: new Point(coords)
        });

        feature.setProperties({
            id: item.id,
            name: item.name,
            type: normalizePoiType(item.type),
            distance
        });

        features.push(feature);
    }

    poiSource.addFeatures(features);
}

// =====================================================
// LOCATION
// =====================================================

function getLocation() {

    return new Promise((resolve, reject) => {

        navigator.geolocation.getCurrentPosition(

            (pos) => resolve({
                lat: pos.coords.latitude,
                lon: pos.coords.longitude
            }),

            (err) => {

                console.warn(
                    'High accuracy failed, retrying low accuracy...',
                    err
                );

                navigator.geolocation.getCurrentPosition(

                    (pos2) => resolve({
                        lat: pos2.coords.latitude,
                        lon: pos2.coords.longitude
                    }),

                    (err2) => reject(err2),

                    {
                        enableHighAccuracy: false,
                        timeout: 60000,
                        maximumAge: 60000
                    }
                );
            },

            {
                enableHighAccuracy: true,
                timeout: 20000,
                maximumAge: 0
            }
        );
    });
}

// =====================================================
// TURISM POI
// =====================================================

async function loadTourismPOI() {

    let cl = document.getElementById('current_location');
    const lat = parseFloat(cl.dataset.lat);
    const lon = parseFloat(cl.dataset.lon);


    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        console.warn('Invalid coordinates');
        hidePOIModal();
        return;
    }

    const url =
        `/api/transport-nearby?lat=${lat}&lon=${lon}&radius=${app_radius}&types=tourism`;

    const res = await fetch(url);
    const data = await res.json();

    showResultData(data, lat, lon);
    addUserEvents();
}


// =====================================================
// SHARE LOCATION
// =====================================================

function getGoogleMapsUrl() {

    if (!currentUserLocation) {
        return null;
    }

    return `https://www.google.com/maps?q=${currentUserLocation.lat},${currentUserLocation.lon}`;
}

async function shareLocationWhatsApp() {

    const googleMapsUrl = getGoogleMapsUrl();

    if (!googleMapsUrl) {

        Swal.fire({
            title: 'GPS inactive',
            text: 'Activate location first',
            icon: 'warning'
        });

        return;
    }

    try {

        await navigator.clipboard.writeText(
            googleMapsUrl
        );

    } catch (error) {

        console.warn(
            'Clipboard failed',
            error
        );
    }

    const text = encodeURIComponent(
        `📍 My current location:\n${googleMapsUrl}`
    );

    const whatsappUrl =
        `https://wa.me/?text=${text}`;

    window.open(
        whatsappUrl,
        '_blank'
    );
}

// =====================================================
// SHARE LOCATION
// =====================================================

async function shareLocation() {

    if (!currentUserLocation) {

        Swal.fire({
            title: 'GPS inactive',
            text: 'Activate location first',
            icon: 'warning'
        });

        return;
    }

    const { lat, lon } = currentUserLocation;

    const googleMapsUrl =
        `https://www.google.com/maps?q=${lat},${lon}`;

    // MOBILE SHARE API
    if (navigator.share) {

        try {

            await navigator.share({

                title: 'My Location',

                text: '📍 My current location',

                url: googleMapsUrl
            });

            return;

        } catch (error) {

            console.warn(error);
        }
    }

    // WHATSAPP FALLBACK
    const text = encodeURIComponent(
        `📍 My current location:\n${googleMapsUrl}`
    );

    const whatsappUrl =
        `https://wa.me/?text=${text}`;

    window.open(
        whatsappUrl,
        '_blank'
    );
}



// =====================================================
// TURISM BUTTON EVENT
// =====================================================

let turismBtn = document.getElementById('turismLocations')
turismBtn?.addEventListener('click', () => {
    loadTourismPOI();
});

// =====================================================
// SHARE BUTTON EVENT
// =====================================================

const shareBtn =
    document.getElementById('shareLocation');

if (shareBtn) {

    shareBtn.addEventListener(
        'click',
        async () => {

            await shareLocation();
        }
    );
}

function createInputLocationElement(create) {
    if(create) {
        let currentLocation = document.createElement('input');
        currentLocation.setAttribute('id', 'current_location');
        currentLocation.classList.add('d-none');
        document.body.appendChild(currentLocation);        
    }
    else {
        let cl = document.getElementById('current_location');
        if(cl) {
            cl.remove();
        }
    }
}


// =====================================================
// GPS TRACKING
// =====================================================

document
    .getElementById('toggleLocation')
    ?.addEventListener('click', () => {

        tracking = !tracking;

        let mapCard =
            document.getElementById('map_card');

        let mobCard =
            document.getElementById('mobility_card');

        let shareBtn = document.getElementById('shareLocation');    
        let turismBtn = document.getElementById('turismLocations');
        let autoDetectLocation = document.getElementById('auto_detect_location');    

        if (!tracking) {

            mapCard.classList.add('d-none');
            mobCard.classList.add('d-none');
            shareBtn.classList.add('d-none');
            turismBtn.classList.add('d-none');
            createInputLocationElement(false);

            autoDetectLocation.setAttribute('disabled', true);
            resetMap();
            return;
        }

        mobCard.querySelector('.row')
            .classList.remove('mt-3');

        mobCard.classList.remove('d-none');
        mapCard.classList.remove('d-none');

        setTimeout(() => {
            map.updateSize();
        }, 100);

        createInputLocationElement(true);

        if (!navigator.geolocation) {
            Swal.fire({
                icon: 'error',
                title: 'Geolocation not supported',
                text: 'Your browser does not support geolocation.'
            });

            tracking = false;
            return;
        }

        watchId =
            navigator.geolocation.watchPosition(

                (position) => {

                    let lon =
                        position.coords.longitude;

                    let lat =
                        position.coords.latitude;

                    if (lastCoords) {

                        lon =
                            lastCoords.lon * 0.7 +
                            lon * 0.3;

                        lat =
                            lastCoords.lat * 0.7 +
                            lat * 0.3;
                    }

                    lastCoords = {
                        lon,
                        lat
                    };

                    currentUserLocation = {
                        lon,
                        lat
                    };

                    document
                        .getElementById('shareLocation')
                        ?.classList.remove('d-none');
                    document
                        .getElementById('turismLocations')
                        ?.classList.remove('d-none');

                    document.getElementById('auto_detect_location')?.removeAttribute('disabled');


                    const coords =
                        fromLonLat([lon, lat]);

                    if (lastPosition) {

                        const dx =
                            lon - lastPosition.lon;

                        const dy =
                            lat - lastPosition.lat;

                        heading =
                            Math.atan2(dy, dx);
                    }

                    lastPosition = {
                        lon,
                        lat
                    };

                    requestAnimationFrame(() => {

                        userFeature
                            .getGeometry()
                            .setCoordinates(coords);                        

                        if (radiusFeature) {

                            radiusFeature.setGeometry(
                                circular(
                                    [lon, lat],
                                    Number(app_radius),
                                    64
                                ).transform('EPSG:4326', 'EPSG:3857')
                            );
                        }                        

                        userStyle
                            .getImage()
                            .setRotation(heading);

                        userFeature.setStyle(
                            userStyle
                        );

                        const geometry =
                            trackFeature.getGeometry();

                        geometry.appendCoordinate(
                            coords
                        );

                        const coordinates =
                            geometry.getCoordinates();

                        if (
                            coordinates.length > 1000
                        ) {

                            coordinates.shift();

                            geometry.setCoordinates(
                                coordinates
                            );
                        }
                    });

                    if (!hasCentered) {

                        map.getView().animate({

                            center: coords,

                            zoom: 17,

                            duration: 500
                        });

                        hasCentered = true;
                    }


                    let shareBtn = document.getElementById('shareLocation');
                    shareBtn.classList.remove('d-none');

                    let currentLocation = document.getElementById('current_location');
                    currentLocation.dataset.lat = lat;    
                    currentLocation.dataset.lon = lon;

                    initLocalFeatures();    

                    const now = Date.now();

                    if (
                        now - lastSent > 5000
                    ) {

                        lastSent = now;

                        fetch(
                            '/location/update',
                            {
                                method: 'POST',

                                headers: {
                                    'Content-Type':
                                        'application/json',

                                    'X-CSRF-TOKEN':
                                        csrfToken
                                },

                                body: JSON.stringify({
                                    lat,
                                    lon,
                                    active: true,
                                    heading
                                })
                            }
                        );
                    }
                },

                console.error,

                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
    });


// =====================================================
// RESET
// =====================================================

function resetMap() {

    if (watchId !== null) {

        navigator.geolocation.clearWatch(
            watchId
        );

        watchId = null;
    }

    tracking = false;

    vectorSource.clear();

    poiSource.clear();
    userSource.clear();
    users.clear();

    userFeature = null;
    trackFeature = null;

    radiusFeature = null;

    lastCoords = null;
    lastPosition = null;

    currentUserLocation = null;

    document
        .getElementById('shareLocation')
        ?.classList.add('d-none');

    heading = 0;
    hasCentered = false;

    map.getView().animate({

        center: fromLonLat([25.6, 45.65]),

        zoom: 12,

        duration: 500
    });

    fetch('/location/update', {

        method: 'POST',

        headers: {
            'Content-Type':
                'application/json',

            'X-CSRF-TOKEN':
                csrfToken
        },

        body: JSON.stringify({
            active: false
        })
    });
}

// =====================================================
// POI BUTTON
// =====================================================

    let poiBtn = document.getElementById('auto_detect_location');
    poiBtn?.addEventListener('click', () => {

        if(!document.getElementById('current_location'))
            return;

        let loc_number = document.getElementById('locations_number');
        if(poiBtn.checked) {
            if(loc_number.classList.contains('d-none')) {
                loc_number.classList.remove('d-none');    
            }
            loadNearby(poiBtn.checked);
        }
        else {
            if(!loc_number.classList.contains('d-none')) {
                loc_number.classList.add('d-none');    
            }

            let container = document.getElementById('mobility_card');
            let cardContainer = container.querySelector('.mobility-cards-container');
            if (!cardContainer.classList.contains('d-none')) {
                cardContainer.classList.add('d-none');
            }

            activateMobilityCards(false);
        }
    });

    function renderPOIToCards(data = []) {

        const items = normalizePOI(data);

        const container = document.getElementById('mobility_card');
        if (!container) return;

        let cardContainer = container.querySelector('.mobility-cards-container');
        if (cardContainer.classList.contains('d-none')) {
            cardContainer.classList.remove('d-none');
        }

        // clear cards
        container.querySelectorAll('.card').forEach(card => {
            const body = card.querySelector('.card-body');
            if (body) body.innerHTML = '';
        });

        // sort SAFE (backend is source of truth)
        const sorted = [...items].sort(
            (a, b) => (a.distance?.meters ?? Infinity) - (b.distance?.meters ?? Infinity)
        );

        // group by type
        const grouped = {};

        sorted.forEach(item => {
            const type = item.type || 'unknown';
            if (!grouped[type]) grouped[type] = [];
            grouped[type].push(item);
        });

        // render
        Object.keys(grouped).forEach(type => {

            const card = document.getElementById(type);

            const header = card?.querySelector('.card-header');

            let color = null;
            if (header) {
                color = getColorForType(type);
                let span = header.querySelector('.category-color');
                span.style.backgroundColor = color;
                // header.style.color = getTextColorForBackground(color);
            }


            if (!card) return;

            const body = card.querySelector('.card-body');
            if (!body) return;

            const list = grouped[type];

            card.closest('.mobility')?.classList.remove('d-none');

            body.innerHTML = '';

            list.forEach(item => {

                const distanceText = item.distance?.formatted ?? 'N/A';

                const row = document.createElement('div');

                row.className =
                    'd-flex justify-content-between align-items-start border-bottom py-2';

                let icon = getIconFromType(item.type);    
                row.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center w-100 py-2 location-element" data-item='` + toBase64(JSON.stringify(item)) + `'>

                        <div class="w-100">

                            <div class="d-flex flex-column min-w-0">

                                <div class="fw-semibold d-flex flex-column">
                                    <span>${item.name || 'Unknown'}</span>
                                    <span class="formatted-address">
                                        ${[item.address.city, item.address.county, item.address.country]
                                            .filter(Boolean)
                                            .join(', ')}
                                    </span>
                                </div>

                                <div class="d-flex flex-row align-items-center justify-content-between text-muted small">
                                    <span class="text-capitalize"><i class="fa-solid mx-2 fa-`+icon+`"></i>${item.type}</span>
                                    <span class="badge rounded-pill bg-primary px-2 py-1">${distanceText}</span>
                                </div>

                            </div>
                        </div>

                    </div>
                `;

                body.appendChild(row);
            });
        });

        setTimeout(function() {
           hidePOIModal(); 
       }, 2000);
    }

    function toBase64(str) {
      return btoa(
        String.fromCharCode(...new TextEncoder().encode(str))
      );
    }

    function fromBase64(base64) {
      const bytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0));
      return new TextDecoder().decode(bytes);
    }

    function getIconFromType(type) {
        let result = null;
        switch(type) {
            case 'airport' : 
                result = 'plane';
                break;
            case 'bus' : 
                result = 'bus';
                break;
            case 'train' : 
                result = 'train';
                break;
            case 'hospital' : 
                result = 'hospital';
                break;
            case 'pharmacy' : 
                result = 'staff-snake';
                break;
            case 'police' : 
                result = 'shield-halved';
                break;
            case 'fire' : 
                result = 'fire-flame-curved';
                break;
            case 'taxi' : 
                result = 'taxi';
                break;
            case 'tourism' : 
                result = 'monument';
                break;
            default:
                result = 'circle-question';    
        }
        return result;
    }
    function normalizePOI(data = []) {

        if (!Array.isArray(data)) return [];

        return data.map(item => ({
            id: item.id ?? null,
            name: item.name ?? 'Unknown',
            type: item.type ?? 'transport',

            coordinates: {
                lat: item.coordinates?.lat ?? null,
                lon: item.coordinates?.lon ?? null,
            },

            distance: {
                meters: item.distance?.meters ?? null,
                km: item.distance?.km ?? null,
                formatted: item.distance?.formatted ?? 'N/A',
            },

            address: item.address ?? {},
            details: item.details ?? {}
        }));
    }



// =====================================================
// LOAD POI
// =====================================================

async function loadNearby(checked) {

    showPOIModal();

    try {

        const cl = document.getElementById('current_location');

        if (!cl) {
            hidePOIModal();
            return;
        }

        const lat = parseFloat(cl.dataset.lat);
        const lon = parseFloat(cl.dataset.lon);

        if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
            hidePOIModal();
            return;
        }

        allPoisRaw = [];
        allPois = [];
        activePoiFilters.clear();

        const response = await fetch(
            `/api/transport-nearby?lat=${lat}&lon=${lon}&radius=${app_radius}`
        );

        if (!response.ok) {
            throw new Error(
                `HTTP ${response.status}`
            );
        }

        const data = await response.json();

        showResultData(data, lat, lon);
        addUserEvents();

    } catch (error) {
        setTimeout(function() {
            hidePOIModal();
        }, 1000);
        
        console.error(error.message);
        activateMobilityCards(false);
        document.getElementById('auto_detect_location').checked = false;
        Swal.fire({
          title: "Error!",
          text: error.message,
          icon: "error"
        });
    }    
}

// =====================================================
// SORT DATA
// =====================================================

// sortare dupa nume ASC
// locations = sortLocations(locations, 'name', 'asc');

// sortare dupa nume DESC
// locations = sortLocations(locations, 'name', 'desc');

// sortare dupa distanta ASC
// locations = sortLocations(locations, 'distance', 'asc');

// sortare dupa distanta DESC
// locations = sortLocations(locations, 'distance', 'desc');

function sortLocations(locations, sortBy = 'name', direction = 'asc') {

    return [...locations].sort((a, b) => {

        let result = 0;

        switch (sortBy) {

            case 'distance':

                result =
                    (a.distance?.meters || 0) -
                    (b.distance?.meters || 0);

                break;

            case 'name':
            default:

                result = (a.name || '').localeCompare(
                    b.name || '',
                    'ro',
                    {
                        sensitivity: 'base',
                        numeric: true,
                    }
                );

                break;
        }

        return direction === 'desc'
            ? result * -1
            : result;
    });
}

// =====================================================
// SHOW DATA
// =====================================================
function applyPOIFilters(catFilters, data) {
    console.log(catFilters, data);

    if (!Array.isArray(data)) return [];

    // dacă nu ai filtre active → returnezi tot
    if (!Array.isArray(catFilters) || catFilters.length === 0) {
        return data;
    }

    return data.filter(poi => {
        return catFilters.includes(poi.type);
    });
}

function updateCounter(data) {
    let countLocations = document.getElementById('locations_number');
    countLocations.innerHTML='Found ' + data.length + ' locations.';
    countLocations.dataset.count = data.length;
    countLocations.closest('.count-locations').classList.remove('d-none');
}

function showResultData(data, lat, lon) {

    const filtered = filterByRadius(data, lat, lon, app_radius);

    const normalized = filtered.map(p => ({
        ...p,
        type: normalizePoiType(p.type)
    }));

    const enriched = enrichPOIWithDistance(normalized, lat, lon);

    enriched.sort(
        (a, b) =>
            a.distance.meters - b.distance.meters
    );


    allPoisRaw = enriched;
    allPois = enriched;

    updateCounter(enriched);
    renderPOI(enriched, lat, lon);
    renderPOIToCards(enriched);
}

function addUserEvents () {
    document.querySelectorAll('.location-element').forEach(button => {
        button.addEventListener('click', () => {

            let item  = JSON.parse(fromBase64(button.dataset.item));
            const locationName = item.name;
            const locationAddress = item.address.formatted;
            const locationLat = item.coordinates.lat;
            const locationLng = item.coordinates.lon;

            Swal.fire({
                title: locationName,
                html: `
                    <div class="text-start">
                        <p><strong>Address:</strong><br> `+ locationAddress.replace(locationName + ',', '') + `</p>

                        <hr>

                        <p>
                            <strong>Coordinates:</strong><br>
                            ${locationLat}, ${locationLng}
                        </p>
                    </div>
                `,
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'GO',
                cancelButtonText: 'CANCEL',
                confirmButtonColor: '#198754',
                cancelButtonColor: '#dc3545',
            }).then((result) => {

                if (result.isConfirmed) {
                    document.getElementById('auto_detect_location').checked = false;
                    document.getElementById('toggleLocation').checked = false;
                    window.location.href =
                        `https://www.google.com/maps?q=${locationLat},${locationLng}`;
                }
            });
        });
    });
    document.querySelectorAll('.location-category').forEach(cb => {
        const type = cb.dataset.type;

        if (cb.checked) {
            activePoiFilters.add(type);
        }

        cb.addEventListener('change', () => {

            let cl = document.getElementById('current_location');
            const lat = parseFloat(cl.dataset.lat);
            const lon = parseFloat(cl.dataset.lon);


            if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
                console.warn('Invalid coordinates');
                hidePOIModal();
                return;
            }

            const t = cb.dataset.type;

            if (cb.checked) {
                activePoiFilters.add(t);
            } else {
                activePoiFilters.delete(t);
            }

            let data = applyPOIFilters(
                Array.from(activePoiFilters),
                allPoisRaw
            );

            const filtered = filterByRadius(data, lat, lon, app_radius);

            const enriched = enrichPOIWithDistance(filtered, lat, lon);

            enriched.sort(
                (a, b) =>
                    a.distance.meters - b.distance.meters
            );

            let pois = allPois;
            pois = enriched;
            updateCounter(enriched);
            
            renderPOI(enriched);
            renderPOIToCards(enriched);

            poiSource.changed(); // re-render map
        });
    });
}

// =====================================================
// ENRICH POI
// =====================================================

function enrichPOIWithDistance(poiList, userLat, userLon) {
    return poiList.map((item) => {
        const lat = item.coordinates?.lat ?? item.lat;
        const lon = item.coordinates?.lon ?? item.lon;

        let distanceMeters = null;

        if (typeof lat === 'number' && typeof lon === 'number') {
            distanceMeters = getDistanceMeters(
                userLat,
                userLon,
                lat,
                lon
            );
        }

        return {
            ...item,
            distance: {
                meters: distanceMeters,
                km: distanceMeters ? distanceMeters / 1000 : null,
                formatted: distanceMeters < 1000
                    ? `${Math.round(distanceMeters)} m`
                    : `${(distanceMeters / 1000).toFixed(1)} km`
            }
        };
    });
}

// =====================================================
// DISTANCE
// =====================================================

function getDistanceMeters(lat1, lon1, lat2, lon2) {

    const R = 6371000;

    const toRad = (deg) =>
        deg * Math.PI / 180;

    const dLat = toRad(lat2 - lat1);

    const dLon = toRad(lon2 - lon1);

    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(lat1)) *
        Math.cos(toRad(lat2)) *
        Math.sin(dLon / 2) ** 2;

    return R * (
        2 * Math.atan2(
            Math.sqrt(a),
            Math.sqrt(1 - a)
        )
    );
}

function showPOIModal() {
    const modalEl = document.getElementById('poiLoadingModal');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

function hidePOIModal() {
    const modalEl = document.getElementById('poiLoadingModal');
    if (!modalEl) return;

    document.activeElement?.blur();

    const modal = bootstrap.Modal.getInstance(modalEl);
    modal?.hide();
}

function activateMobilityCards(checked) {
    let mobCards = document.querySelectorAll('.mobility');
    if(checked) {
        mobCards.forEach(function(item) {
            if(item.classList.contains('d-none')) {
                item.classList.remove('d-none');
            }
        });        
    }
    else {
        mobCards.forEach(function(item) {
            if(!item.classList.contains('d-none')) {
                item.classList.add('d-none');
            }
        });        
    }
}
