
import 'ol/ol.css';

import Map from 'ol/Map.js';
import View from 'ol/View.js';
import TileLayer from 'ol/layer/Tile.js';
import VectorLayer from 'ol/layer/Vector.js';
import XYZ from 'ol/source/XYZ.js';
import VectorSource from 'ol/source/Vector.js';
import Feature from 'ol/Feature.js';
import Point from 'ol/geom/Point.js';
import Circle from 'ol/geom/Circle.js';
import { fromLonLat } from 'ol/proj.js';
import { Style, Fill, Stroke, Circle as CircleStyle } from 'ol/style.js';
import { fromCircle } from 'ol/geom/Polygon.js';
import { fetchJson } from '../http.js';
import { MAP_ATTRIBUTION } from '../map-attribution.js';

const mapElement = document.getElementById('currentPositionMap');

if (mapElement) {
    const positionSource = new VectorSource();
    const accuracySource = new VectorSource();

    const positionFeature = new Feature();
    positionSource.addFeature(positionFeature);

    const positionLayer = new VectorLayer({
        source: positionSource,
        style: new Style({
            image: new CircleStyle({
                radius: 9,
                fill: new Fill({ color: '#2563eb' }),
                stroke: new Stroke({
                    color: '#ffffff',
                    width: 3
                })
            })
        })
    });

    const accuracyLayer = new VectorLayer({
        source: accuracySource,
        style: new Style({
            fill: new Fill({
                color: 'rgba(37, 99, 235, 0.12)'
            }),
            stroke: new Stroke({
                color: 'rgba(37, 99, 235, 0.5)',
                width: 2
            })
        })
    });

    const map = new Map({
        target: mapElement,
        layers: [
            // Cached, rate-limited proxy used by every map; the public OSM tile servers forbid app traffic.
            new TileLayer({
                source: new XYZ({
                    url: '/map/tiles/{z}/{x}/{y}',
                    maxZoom: 20,
                    attributions: MAP_ATTRIBUTION
                })
            }),
            accuracyLayer,
            positionLayer
        ],
        view: new View({
            center: fromLonLat([25, 46]),
            zoom: 5
        })
    });

    const locateButton = document.getElementById('locateMe');
    const statusElement = document.getElementById('locationStatus');

    let locating = false;

    const setStatus = (message, type = 'info') => {
        const icons = {
            info: 'fa-circle-info',
            success: 'fa-circle-check',
            error: 'fa-circle-exclamation',
            loading: 'fa-spinner fa-spin'
        };

        if (!statusElement) return;

        statusElement.className = `current-position-status ${type}`;
        statusElement.innerHTML = `
            <i class="fa-solid ${icons[type]}"></i>
            <span>${message}</span>
        `;
    };

    const formatValue = (value, decimals = 6) => {
        return Number.isFinite(value)
            ? value.toFixed(decimals)
            : '--';
    };

    const setAddressField = (id, value) => {
        const element = document.getElementById(id);

        if (element) {
            element.textContent = value || '--';
        }
    };

    const resetAddress = (message = 'Address not available') => {
        setAddressField('positionFullAddress', message);
        setAddressField('positionStreet', '--');
        setAddressField('positionHouseNumber', '--');
        setAddressField('positionDistrict', '--');
        setAddressField('positionCity', '--');
        setAddressField('positionCounty', '--');
        setAddressField('positionPostcode', '--');
        setAddressField('positionCountry', '--');
    };

    const updateAddress = (address) => {
        if (!address) {
            resetAddress('No address found for this location.');
            return;
        }

        setAddressField(
            'positionFullAddress',
            address.full_address
        );

        setAddressField('positionStreet', address.street);
        setAddressField('positionHouseNumber', address.house_number);
        setAddressField('positionDistrict', address.district);
        setAddressField('positionCity', address.city);
        setAddressField('positionCounty', address.county);
        setAddressField('positionPostcode', address.postcode);
        setAddressField('positionCountry', address.country);
    };

    const fetchAddress = async (latitude, longitude) => {
        resetAddress('Retrieving address...');

        try {
            const params = new URLSearchParams({
                lat: latitude,
                lon: longitude
            });

            const data = await fetchJson(`/api/location/reverse?${params.toString()}`);

            updateAddress(data.address);

        } catch (error) {
            console.error('Reverse geocoding error:', error);

            resetAddress('Unable to retrieve address.');
        }
    };

    const updatePosition = async (position) => {
        const coords = position.coords;

        const longitude = coords.longitude;
        const latitude = coords.latitude;

        const projected = fromLonLat([longitude, latitude]);

        positionFeature.setGeometry(new Point(projected));

        accuracySource.clear();

        if (Number.isFinite(coords.accuracy) && coords.accuracy > 0) {
            const accuracyCircle = new Circle(
                projected,
                coords.accuracy
            );

            accuracySource.addFeature(
                new Feature(fromCircle(accuracyCircle, 64))
            );
        }

        map.getView().animate({
            center: projected,
            zoom: 17,
            duration: 700
        });

        document.getElementById('positionLatitude').textContent =
            formatValue(latitude);

        document.getElementById('positionLongitude').textContent =
            formatValue(longitude);

        document.getElementById('positionAccuracy').textContent =
            `${formatValue(coords.accuracy, 1)} m`;

        document.getElementById('positionAltitude').textContent =
            coords.altitude !== null
                ? `${formatValue(coords.altitude, 1)} m`
                : 'Unavailable';

        document.getElementById('positionSpeed').textContent =
            coords.speed !== null
                ? `${formatValue(coords.speed * 3.6, 1)} km/h`
                : 'Unavailable';

        document.getElementById('positionHeading').textContent =
            coords.heading !== null
                ? `${formatValue(coords.heading, 0)}°`
                : 'Unavailable';

        document.getElementById('positionUpdated').textContent =
            new Date(position.timestamp).toLocaleString('ro-RO', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            });

        setStatus(
            'Your current position was successfully retrieved.',
            'success'
        );

        await fetchAddress(latitude, longitude);
    };

    const showError = (error) => {
        const messages = {
            1: 'Location permission was denied. Please allow location access.',
            2: 'Your current position is unavailable.',
            3: 'Location request timed out. Please try again.'
        };

        setStatus(
            messages[error.code] || 'An unexpected location error occurred.',
            'error'
        );
    };

    locateButton?.addEventListener('click', () => {
        if (!navigator.geolocation) {
            setStatus(
                'Geolocation is not supported by this browser.',
                'error'
            );
            return;
        }

        if (locating) return;

        locating = true;
        locateButton.disabled = true;

        setStatus('Getting your current GPS position...', 'loading');

        navigator.geolocation.getCurrentPosition(
            async (position) => {
                try {
                    await updatePosition(position);
                } finally {
                    locating = false;
                    locateButton.disabled = false;
                }
            },
            (error) => {
                showError(error);
                locating = false;
                locateButton.disabled = false;
            },
            {
                enableHighAccuracy: true,
                maximumAge: 0,
                timeout: 20000
            }
        );
    });
}
