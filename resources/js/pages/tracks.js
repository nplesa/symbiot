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


// DOM

const trackDay =
    document.getElementById("trackDay");

const trackSelect =
    document.getElementById("trackDate");

const playButton =
    document.getElementById("trackPlay");

const pauseButton =
    document.getElementById("trackPause");

const resetButton =
    document.getElementById("trackReset");

const speedSelect =
    document.getElementById("trackSpeed");

const statsBox =
    document.getElementById("trackStats");


// CONFIG

const API = {
    sessions: "/tracking/sessions",
    points: "/tracking"
};


// MAP

class TrackMap {

    constructor() {

        this.trackSource =
            new VectorSource();

        this.markerSource =
            new VectorSource();

        this.playerSource =
            new VectorSource();

        this.map = new Map({
            target: "track_map",
            layers: [
                new TileLayer({
                    source: new XYZ({
                        url:
                        "/map/tiles/{z}/{x}/{y}"
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
                center:
                    fromLonLat([
                        25.6012,
                        45.6579
                    ]),
                zoom: 12
            })
        });
    }

    fit(line) {
        this.map
            .getView()
            .fit(line, {
                padding:[
                    50,
                    50,
                    50,
                    50
                ],
                duration:700,
                maxZoom:18
            });
    }

    clear() {
        this.trackSource.clear();
        this.markerSource.clear();
    }
}

// LOADER

class TrackLoader {

    async sessions(date) {
        const r =
            await fetch(
                `${API.sessions}?date=${date}`
            );

        if(!r.ok)
            throw Error("sessions");

        return r.json();
    }

    async points(id) {
        const r =
            await fetch(
                `${API.points}/${id}/points`
            );

        if(!r.ok)
            throw Error("points");

        return this.normalize(
            await r.json()
        );
    }

    normalize(data) {

        return data
            .map(p => ({

                latitude:
                    Number(p.latitude),

                longitude:
                    Number(p.longitude),

                speed:
                    Number(p.speed || 0),

                altitude:
                    Number(p.altitude || 0),

                timestamp:
                    new Date(
                        p.timestamp ??
                        p.created_at ??
                        Date.now()
                    )

            }))
            .filter(p =>
                !isNaN(p.latitude) &&
                !isNaN(p.longitude)
            );
    }
}

// RENDER

class TrackRenderer {

    constructor(map) {
        this.map = map;
    }

    draw(points) {
        const line =
            new LineString(

                points.map(p =>
                    fromLonLat([
                        p.longitude,
                        p.latitude
                    ])
                )
            );

        const feature =
            new Feature({
                geometry: line
            });

        feature.setStyle(
            new Style({
                stroke: new Stroke({
                    color:"#0d6efd",
                    width:4
                })
            })
        );
        this.map.trackSource
            .addFeature(feature);

        this.map.fit(line);
    }
}

// MARKERS

class TrackMarkers {

    constructor(map) {
        this.map = map;
    }

    draw(points) {

        if(!points.length)
            return;

        [
            [points[0],"#198754"],
            [points.at(-1),"#dc3545"]

        ].forEach(([p,color]) => {


            const f =
                new Feature({
                    geometry:
                        new Point(
                            fromLonLat([
                                p.longitude,
                                p.latitude
                            ])
                        )
                });

            f.setStyle(
                new Style({
                    image:
                        new Circle({
                            radius:8,
                            fill:
                                new Fill({
                                    color
                                })
                        })
                })
            );

            this.map.markerSource
                .addFeature(f);
        });
    }
}

// STATISTICS

class TrackStatistics {

    calc(points) {
        let distance = 0;

        for(let i=1;i<points.length;i++)
        {
            distance += this.dist(
                points[i-1],
                points[i]
            );
        }

        return {

            distance:
                (distance/1000)
                .toFixed(2),

            points:
                points.length
        };
    }

    dist(a,b) {

        const R = 6371000;

        const dLat =
            (b.latitude-a.latitude)
            *
            Math.PI/180;

        const dLon =
            (b.longitude-a.longitude)
            *
            Math.PI/180;

        const x =
            Math.sin(dLat/2)**2 +
            Math.cos(
                a.latitude*Math.PI/180
            )
            *
            Math.cos(
                b.latitude*Math.PI/180
            )
            *
            Math.sin(dLon/2)**2;

        return R *
            2 *
            Math.atan2(
                Math.sqrt(x),
                Math.sqrt(1-x)
            );
    }
}

// POPUP

class TrackPopup {

    constructor(map) {
        const el =
            document.createElement("div");

        el.className =
            "track-popup";

        this.overlay =
            new Overlay({

                element:el,

                offset:[
                    0,
                    -15
                ]

            });

        map.map.addOverlay(
            this.overlay
        );

        this.el = el;
    }

    show(point,pos) {

        this.el.innerHTML = `

            <b>GPS</b><br>

            Speed:
            ${point.speed} km/h

            <br>

            Alt:
            ${point.altitude} m
        `;

        this.overlay
            .setPosition(pos);
    }
}

// PLAYER

class TrackPlayer {

    constructor(map) {
        this.map = map;
        this.points=[];
        this.index=0;
        this.speed=1;
        this.running=false;
        this.marker=null;
    }

    load(points) {
        this.pause();
        this.points=points;
        this.index=0;

        if(this.marker)
        {
            this.map.playerSource
                .removeFeature(this.marker);
        }

        this.marker =
            new Feature({

                geometry:

                    new Point(
                        fromLonLat([
                            points[0].longitude,
                            points[0].latitude
                        ])
                    )
            });

        this.marker.setStyle(
            new Style({
                image:
                    new Circle({
                        radius:10,
                        fill:
                            new Fill({
                                color:"#0d6efd"
                            })
                    })
            })
        );
        this.map.playerSource
            .addFeature(this.marker);
    }

    play() {
        if(!this.points.length)
            return;

        this.running=true;
        this.animate();
    }

    pause() {
        this.running=false;
    }

    reset() {
        this.pause();
        this.index=0;
        if(this.points.length)
            this.move(this.points[0]);
    }

    animate() {

        if(!this.running)
            return;

        if(this.index >= this.points.length-1)
        {
            this.pause();
            return;
        }

        const a =
            this.points[this.index];

        const b =
            this.points[this.index+1];

        let start =
            performance.now();

        const step = now => {

            if(!this.running)
                return;

            let t =
                (now-start)
                /
                (1000/this.speed);

            if(t>1)
                t=1;

            this.move({
                latitude:  a.latitude + (b.latitude-a.latitude) * t,
                longitude: a.longitude + (b.longitude-a.longitude) * t
            });

            if(t<1)
            {
                requestAnimationFrame(step);
            }
            else
            {
                this.index++;
                this.animate();
            }
        };
        requestAnimationFrame(step);
    }

    move(p) {
        this.marker
            .getGeometry()
            .setCoordinates(

                fromLonLat([
                    p.longitude,
                    p.latitude
                ])
            );
    }
}

// INIT

const trackMap =
    new TrackMap();

const loader =
    new TrackLoader();

const renderer =
    new TrackRenderer(trackMap);

const markers =
    new TrackMarkers(trackMap);

const stats =
    new TrackStatistics();

const popup =
    new TrackPopup(trackMap);

const player =
    new TrackPlayer(trackMap);

let currentPoints=[];

// EVENTS

trackDay?.addEventListener(
"change",
async e => {
    const sessions =
        await loader.sessions(
            e.target.value
        );

    trackSelect.innerHTML =
        '<option value="">Select</option>';

    sessions.forEach(s=>{
        const o =
            document.createElement("option");
        o.value=s.id;

        o.textContent =
            new Date(
                s.started_at
            )
            .toLocaleString();
        trackSelect.appendChild(o);
    });
});

trackSelect?.addEventListener(
"change",
async e=>{

    currentPoints =
        await loader.points(
            e.target.value
        );

    trackMap.clear();

    renderer.draw(
        currentPoints
    );

    markers.draw(
        currentPoints
    );

    player.load(
        currentPoints
    );

    if(statsBox)
    {
        statsBox.innerHTML =
        `
        Distance:
        ${stats.calc(currentPoints).distance}
        km
        `;
    }
});

playButton?.addEventListener(
"click",
()=>player.play()
);

pauseButton?.addEventListener(
"click",
()=>player.pause()
);

resetButton?.addEventListener(
"click",
()=>player.reset()
);

speedSelect?.addEventListener(
"change",
e =>
player.speed =
Number(e.target.value)
);

// CLICK GPS

trackMap.map.on(
"click",
e=>{

    if(!currentPoints.length)
        return;

    popup.show(
        currentPoints[0],
        e.coordinate
    );
});