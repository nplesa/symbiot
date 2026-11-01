
import Map from "ol/Map";
import View from "ol/View";

import TileLayer from "ol/layer/Tile";
import VectorLayer from "ol/layer/Vector";

import XYZ from "ol/source/XYZ";
import VectorSource from "ol/source/Vector";

import Feature from "ol/Feature";
import Point from "ol/geom/Point";
import LineString from "ol/geom/LineString";

import Overlay from "ol/Overlay";

import {
    Style,
    Stroke,
    Circle,
    Fill
} from "ol/style";

import { fromLonLat } from "ol/proj";

/* =========================================================
   ELEMENTS
   ========================================================= */

const trackDay = document.getElementById("trackDay");
const trackSelect = document.getElementById("trackDate");

const playButton = document.getElementById("trackPlay");
const pauseButton = document.getElementById("trackPause");
const resetButton = document.getElementById("trackReset");
const speedSelect = document.getElementById("trackSpeed");

const statusBox = document.getElementById("trackStatus");
const emptyMap = document.getElementById("trackMapEmpty");

const distanceElement = document.getElementById("trackDistance");
const pointCountElement = document.getElementById("trackPointCount");
const durationElement = document.getElementById("trackDuration");

const API = {
    sessions: "/tracking/sessions",
    points: "/tracking"
};

/* =========================================================
   HELPERS
   ========================================================= */

function setStatus(message, type = "") {
    if (!statusBox) return;

    statusBox.className = "tracking-status";

    if (type) {
        statusBox.classList.add(type);
    }

    const icon = statusBox.querySelector("i");
    const text = statusBox.querySelector("span");

    if (icon) {
        icon.className = type === "error"
            ? "fa-solid fa-circle-exclamation"
            : type === "success"
                ? "fa-solid fa-circle-check"
                : type === "loading"
                    ? "fa-solid fa-spinner fa-spin"
                    : "fa-solid fa-circle-info";
    }

    if (text) {
        text.textContent = message;
    }
}

function setPlayerEnabled(enabled) {
    [
        playButton,
        pauseButton,
        resetButton,
        speedSelect
    ].forEach(element => {
        if (element) {
            element.disabled = !enabled;
        }
    });
}

function formatDuration(points) {
    if (points.length < 2) return "--";

    const first = points[0].timestamp.getTime();
    const last = points[points.length - 1].timestamp.getTime();

    const totalMinutes = Math.max(
        0,
        Math.floor((last - first) / 60000)
    );

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes} min`;
}

function resetStatistics() {
    if (distanceElement) distanceElement.textContent = "--";
    if (pointCountElement) pointCountElement.textContent = "--";
    if (durationElement) durationElement.textContent = "--";
}

function updateStatistics(points, statistics) {
    const result = statistics.calc(points);

    if (distanceElement) {
        distanceElement.textContent = `${result.distance} km`;
    }

    if (pointCountElement) {
        pointCountElement.textContent = result.points.toLocaleString();
    }

    if (durationElement) {
        durationElement.textContent = formatDuration(points);
    }
}

/* =========================================================
   MAP
   ========================================================= */

class TrackMap {
    constructor() {
        this.trackSource = new VectorSource();
        this.markerSource = new VectorSource();
        this.playerSource = new VectorSource();

        this.map = new Map({
            target: "track_map",

            layers: [
                new TileLayer({
                    source: new XYZ({
                        url: "/map/tiles/{z}/{x}/{y}"
                    })
                }),

                new VectorLayer({
                    source: this.trackSource
                }),

                new VectorLayer({
                    source: this.markerSource
                }),

                new VectorLayer({
                    source: this.playerSource
                })
            ],

            view: new View({
                center: fromLonLat([25.6012, 45.6579]),
                zoom: 12
            })
        });
    }

    fit(line) {
        this.map.getView().fit(line, {
            padding: [50, 50, 50, 50],
            duration: 700,
            maxZoom: 18
        });
    }

    clear() {
        this.trackSource.clear();
        this.markerSource.clear();
        this.playerSource.clear();
    }
}

/* =========================================================
   DATA LOADER
   ========================================================= */

class TrackLoader {
    async sessions(date) {
        const response = await fetch(
            `${API.sessions}?date=${encodeURIComponent(date)}`,
            {
                headers: {
                    Accept: "application/json"
                }
            }
        );

        if (!response.ok) {
            throw new Error("Could not load available tracks.");
        }

        return response.json();
    }

    async points(id) {
        const response = await fetch(
            `${API.points}/${encodeURIComponent(id)}/points`,
            {
                headers: {
                    Accept: "application/json"
                }
            }
        );

        if (!response.ok) {
            throw new Error("Could not load track points.");
        }

        const data = await response.json();

        if (!Array.isArray(data)) {
            throw new Error("Invalid track data received from the server.");
        }

        return this.normalize(data);
    }

    normalize(data) {
        return data
            .map(point => ({
                latitude: Number(point.latitude),
                longitude: Number(point.longitude),
                speed: Number(point.speed || 0),
                altitude: Number(point.altitude || 0),

                timestamp: new Date(
                    point.timestamp ??
                    point.created_at ??
                    Date.now()
                )
            }))
            .filter(point =>
                Number.isFinite(point.latitude) &&
                Number.isFinite(point.longitude) &&
                point.latitude >= -90 &&
                point.latitude <= 90 &&
                point.longitude >= -180 &&
                point.longitude <= 180
            )
            .sort((a, b) =>
                a.timestamp.getTime() - b.timestamp.getTime()
            );
    }
}

/* =========================================================
   ROUTE RENDERER
   ========================================================= */

class TrackRenderer {
    constructor(map) {
        this.map = map;
    }

    draw(points) {
        if (!points.length) return;

        const coordinates = points.map(point =>
            fromLonLat([
                point.longitude,
                point.latitude
            ])
        );

        if (coordinates.length === 1) {
            this.map.fit(
                new Point(coordinates[0])
            );
            return;
        }

        const line = new LineString(coordinates);

        const feature = new Feature({
            geometry: line
        });

        feature.setStyle(
            new Style({
                stroke: new Stroke({
                    color: "#4388e8",
                    width: 5,
                    lineCap: "round",
                    lineJoin: "round"
                })
            })
        );

        this.map.trackSource.addFeature(feature);
        this.map.fit(line);
    }
}

/* =========================================================
   START / FINISH MARKERS
   ========================================================= */

class TrackMarkers {
    constructor(map) {
        this.map = map;
    }

    draw(points) {
        if (!points.length) return;

        const markers = [
            {
                point: points[0],
                color: "#16a36a"
            },
            {
                point: points[points.length - 1],
                color: "#dc4545"
            }
        ];

        markers.forEach(({ point, color }) => {
            const feature = new Feature({
                geometry: new Point(
                    fromLonLat([
                        point.longitude,
                        point.latitude
                    ])
                )
            });

            feature.setStyle(
                new Style({
                    image: new Circle({
                        radius: 8,
                        fill: new Fill({
                            color
                        }),
                        stroke: new Stroke({
                            color: "#ffffff",
                            width: 2
                        })
                    })
                })
            );

            this.map.markerSource.addFeature(feature);
        });
    }
}

/* =========================================================
   STATISTICS
   ========================================================= */

class TrackStatistics {
    calc(points) {
        let distance = 0;

        for (let index = 1; index < points.length; index++) {
            distance += this.dist(
                points[index - 1],
                points[index]
            );
        }

        return {
            distance: (distance / 1000).toFixed(2),
            points: points.length
        };
    }

    dist(a, b) {
        const earthRadius = 6371000;

        const dLat =
            (b.latitude - a.latitude) * Math.PI / 180;

        const dLon =
            (b.longitude - a.longitude) * Math.PI / 180;

        const x =
            Math.sin(dLat / 2) ** 2 +
            Math.cos(a.latitude * Math.PI / 180) *
            Math.cos(b.latitude * Math.PI / 180) *
            Math.sin(dLon / 2) ** 2;

        return earthRadius *
            2 *
            Math.atan2(
                Math.sqrt(x),
                Math.sqrt(1 - x)
            );
    }
}

/* =========================================================
   MAP POPUP
   ========================================================= */

class TrackPopup {
    constructor(map) {
        const element = document.createElement("div");

        element.className = "track-popup";

        this.overlay = new Overlay({
            element,
            offset: [0, -15],
            positioning: "bottom-center",
            stopEvent: false
        });

        map.map.addOverlay(this.overlay);

        this.element = element;
    }

    show(point, position) {
        this.element.innerHTML = `
            <strong>GPS point</strong>
            <div><span>Speed:</span> ${point.speed} km/h</div>
            <div><span>Altitude:</span> ${point.altitude} m</div>
            <div><span>Time:</span> ${point.timestamp.toLocaleString()}</div>
        `;

        this.overlay.setPosition(position);
    }

    hide() {
        this.overlay.setPosition(undefined);
    }
}

/* =========================================================
   TRACK PLAYER
   ========================================================= */

class TrackPlayer {
    constructor(map) {
        this.map = map;
        this.points = [];
        this.index = 0;
        this.speed = 1;
        this.running = false;
        this.marker = null;
        this.animationFrame = null;
        this.segmentStart = null;
    }

    load(points) {
        this.pause();

        this.points = points;
        this.index = 0;

        this.map.playerSource.clear();
        this.marker = null;

        if (!points.length) return;

        this.marker = new Feature({
            geometry: new Point(
                fromLonLat([
                    points[0].longitude,
                    points[0].latitude
                ])
            )
        });

        this.marker.setStyle(
            new Style({
                image: new Circle({
                    radius: 10,
                    fill: new Fill({
                        color: "#4388e8"
                    }),
                    stroke: new Stroke({
                        color: "#ffffff",
                        width: 3
                    })
                })
            })
        );

        this.map.playerSource.addFeature(this.marker);
    }

    play() {
        if (this.running || this.points.length < 2) return;

        if (this.index >= this.points.length - 1) {
            this.reset();
        }

        this.running = true;
        this.segmentStart = null;

        this.animate();
    }

    pause() {
        this.running = false;

        if (this.animationFrame !== null) {
            cancelAnimationFrame(this.animationFrame);
            this.animationFrame = null;
        }

        this.segmentStart = null;
    }

    reset() {
        this.pause();

        this.index = 0;

        if (this.points.length) {
            this.move(this.points[0]);
        }
    }

    animate() {
        if (!this.running) return;

        if (this.index >= this.points.length - 1) {
            this.pause();
            return;
        }

        const startPoint = this.points[this.index];
        const endPoint = this.points[this.index + 1];

        const segmentDuration = 1000 / this.speed;

        const step = now => {
            if (!this.running) return;

            if (this.segmentStart === null) {
                this.segmentStart = now;
            }

            const elapsed = now - this.segmentStart;

            const progress = Math.min(
                elapsed / segmentDuration,
                1
            );

            this.move({
                latitude:
                    startPoint.latitude +
                    (endPoint.latitude - startPoint.latitude) * progress,

                longitude:
                    startPoint.longitude +
                    (endPoint.longitude - startPoint.longitude) * progress
            });

            if (progress < 1) {
                this.animationFrame = requestAnimationFrame(step);
                return;
            }

            this.index++;
            this.segmentStart = null;

            if (this.index >= this.points.length - 1) {
                this.pause();
                return;
            }

            this.animationFrame = requestAnimationFrame(() => {
                this.animate();
            });
        };

        this.animationFrame = requestAnimationFrame(step);
    }

    move(point) {
        if (!this.marker) return;

        this.marker.getGeometry().setCoordinates(
            fromLonLat([
                point.longitude,
                point.latitude
            ])
        );
    }
}

/* =========================================================
   INITIALIZATION
   ========================================================= */

if (trackDay && trackSelect && document.getElementById("track_map")) {
    const trackMap = new TrackMap();
    const loader = new TrackLoader();
    const renderer = new TrackRenderer(trackMap);
    const markers = new TrackMarkers(trackMap);
    const statistics = new TrackStatistics();
    const popup = new TrackPopup(trackMap);
    const player = new TrackPlayer(trackMap);

    let currentPoints = [];

    function clearTrack() {
        player.pause();
        player.load([]);

        trackMap.clear();
        popup.hide();

        currentPoints = [];

        resetStatistics();
        setPlayerEnabled(false);

        if (emptyMap) {
            emptyMap.classList.remove("hidden");
        }
    }

    async function loadSessions(date) {
        clearTrack();

        trackSelect.innerHTML = '<option value="">Loading tracks...</option>';
        trackSelect.disabled = true;

        setStatus("Loading available tracks...", "loading");

        try {
            const sessions = await loader.sessions(date);

            trackSelect.innerHTML = "";

            if (!Array.isArray(sessions) || sessions.length === 0) {
                trackSelect.innerHTML =
                    '<option value="">No tracks available</option>';

                setStatus("No tracks were found for the selected date.");
                return;
            }

            trackSelect.appendChild(
                new Option("Select a track", "")
            );

            sessions.forEach(session => {
                const option = document.createElement("option");

                option.value = session.id;

                const dateLabel = session.started_at
                    ? new Date(session.started_at).toLocaleString()
                    : `Track #${session.id}`;

                option.textContent = dateLabel;

                trackSelect.appendChild(option);
            });

            trackSelect.disabled = false;

            setStatus(
                `${sessions.length} track(s) available. Select one to display its route.`,
                "success"
            );
        } catch (error) {
            console.error(error);

            trackSelect.innerHTML =
                '<option value="">Unable to load tracks</option>';

            setStatus(
                error.message || "An error occurred while loading tracks.",
                "error"
            );
        }
    }

    async function loadTrack(id) {
        if (!id) {
            clearTrack();
            setStatus("Select a track to display its route.");
            return;
        }

        clearTrack();

        setStatus("Loading GPS points...", "loading");

        try {
            const points = await loader.points(id);

            if (!points.length) {
                setStatus(
                    "This track does not contain any valid GPS points.",
                    "error"
                );
                return;
            }

            currentPoints = points;

            renderer.draw(currentPoints);
            markers.draw(currentPoints);
            player.load(currentPoints);

            updateStatistics(currentPoints, statistics);
            setPlayerEnabled(currentPoints.length > 0);

            if (emptyMap) {
                emptyMap.classList.add("hidden");
            }

            setStatus(
                `Track loaded successfully. ${currentPoints.length} GPS point(s) found.`,
                "success"
            );

            trackMap.map.updateSize();
        } catch (error) {
            console.error(error);

            clearTrack();

            setStatus(
                error.message || "An error occurred while loading the track.",
                "error"
            );
        }
    }

    /* DATE CHANGE */

    trackDay.addEventListener("change", event => {
        const date = event.target.value;

        if (!date) {
            clearTrack();

            trackSelect.innerHTML =
                '<option value="">Select a date first</option>';

            trackSelect.disabled = true;

            setStatus("Select a date to view your available tracks.");
            return;
        }

        loadSessions(date);
    });

    /* TRACK CHANGE */

    trackSelect.addEventListener("change", event => {
        loadTrack(event.target.value);
    });

    /* PLAYER CONTROLS */

    playButton?.addEventListener("click", () => {
        player.play();
    });

    pauseButton?.addEventListener("click", () => {
        player.pause();
    });

    resetButton?.addEventListener("click", () => {
        player.reset();
    });

    speedSelect?.addEventListener("change", event => {
        player.speed = Number(event.target.value) || 1;
    });

    /* MAP CLICK */

    trackMap.map.on("click", event => {
        if (!currentPoints.length) return;

        let nearestPoint = null;
        let nearestDistance = Infinity;

        currentPoints.forEach(point => {
            const coordinate = fromLonLat([
                point.longitude,
                point.latitude
            ]);

            const distance = Math.hypot(
                coordinate[0] - event.coordinate[0],
                coordinate[1] - event.coordinate[1]
            );

            if (distance < nearestDistance) {
                nearestDistance = distance;
                nearestPoint = point;
            }
        });

        if (nearestPoint) {
            popup.show(
                nearestPoint,
                fromLonLat([
                    nearestPoint.longitude,
                    nearestPoint.latitude
                ])
            );
        }
    });

    /* INITIAL STATE */

    setPlayerEnabled(false);
    resetStatistics();
    trackSelect.disabled = true;
}
