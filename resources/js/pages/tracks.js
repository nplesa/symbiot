import Map from "ol/Map";
import View from "ol/View";

import TileLayer from "ol/layer/Tile";
import VectorLayer from "ol/layer/Vector";

import XYZ from "ol/source/XYZ";
import VectorSource from "ol/source/Vector";

import Feature from "ol/Feature";
import LineString from "ol/geom/LineString";

import { Stroke, Style } from "ol/style";
import { fromLonLat } from "ol/proj";

const trackDay = document.getElementById("trackDay");
const trackSelect = document.getElementById("trackDate");

const vectorSource = new VectorSource();

const vectorLayer = new VectorLayer({
    source: vectorSource,
    style: new Style({
        stroke: new Stroke({
            color: "#0d6efd",
            width: 4,
        }),
    }),
});


const map = new Map({
    target: "track_map",

    layers: [
        new TileLayer({
            source: new XYZ({
                url: '/map/tiles/{z}/{x}/{y}',
            }),
        }),
        vectorLayer,
    ],

    view: new View({
        center: fromLonLat([25.6012, 45.6579]),
        zoom: 12,
    }),
});

/**
 * Încarcă sesiunile disponibile pentru data selectată.
 */
async function loadSessions(date) {
    trackSelect.innerHTML =
        '<option value="">Loading...</option>';

    try {
        const response = await fetch(`/tracking/sessions?date=${date}`);

        if (!response.ok) {
            throw new Error("Unable to load sessions.");
        }

        const sessions = await response.json();

        trackSelect.innerHTML =
            '<option value="">Select Track</option>';

        if (!sessions.length) {
            trackSelect.innerHTML =
                '<option value="">No tracks found</option>';
            return;
        }

        sessions.forEach((session) => {
            const option = document.createElement("option");

            option.value = session.id;

            option.textContent =
                `${formatDate(session.started_at)} - ${formatTime(session.ended_at)}`;

            trackSelect.appendChild(option);
        });
    } catch (error) {
        console.error(error);

        trackSelect.innerHTML =
            '<option value="">Error loading tracks</option>';
    }
}

/**
 * Desenează traseul.
 */
async function drawTrack(sessionId) {
    vectorSource.clear();

    try {
        const response =  await fetch(`/tracking/${sessionId}/points`);

        if (!response.ok) {
            throw new Error("Unable to load track.");
        }

        const points = await response.json();

        if (points.length === 0) {
            return;
        }

        const coordinates = points.map((point) =>
            fromLonLat([
                Number(point.longitude),
                Number(point.latitude),
            ])
        );

        const feature = new Feature({
            geometry: new LineString(coordinates),
        });

        vectorSource.addFeature(feature);

        map.getView().fit(feature.getGeometry(), {
            padding: [60, 60, 60, 60],
            duration: 700,
            maxZoom: 18,
        });
    } catch (error) {
        console.error(error);
    }
}

function formatDate(value) {
    const date = new Date(value);

    return (
        date.toLocaleDateString() +
        " " +
        date.toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
        })
    );
}

function formatTime(value) {
    if (!value) {
        return "...";
    }

    const date = new Date(value);

    return date.toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });
}

trackDay?.addEventListener("change", (event) => {
    if (!event.target.value) {
        return;
    }

    loadSessions(event.target.value);
});

trackSelect?.addEventListener("change", (event) => {
    if (!event.target.value) {
        vectorSource.clear();
        return;
    }

    drawTrack(event.target.value);
});