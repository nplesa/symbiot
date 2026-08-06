/**
 * =====================================================
 * TRACKS.JS
 * GPS TRACK VIEWER
 * =====================================================
 */

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

import {
    fromLonLat
} from "ol/proj";

// =====================================================
// CONFIG
// =====================================================


const CONFIG =
{

    map:
    {
        center:
        [
            25.6012,
            45.6579
        ],
        zoom: 12
    },
    api:
    {
        sessions: "/tracking/sessions",
        points:   "/tracking"
    }
};

// =====================================================
// DOM
// =====================================================


const trackDay =
    document.getElementById(
        "trackDay"
    );


const trackSelect =
    document.getElementById(
        "trackDate"
    );


const statsBox =
    document.getElementById(
        "trackStats"
    );


const playButton =
    document.getElementById(
        "trackPlay"
    );


const pauseButton =
    document.getElementById(
        "trackPause"
    );


const resetButton =
    document.getElementById(
        "trackReset"
    );


const speedSelect =
    document.getElementById(
        "trackSpeed"
    );


// =====================================================
// MAP CLASS
// =====================================================


/**
 * Gestionează harta OpenLayers.
 */
class TrackMap
{
    constructor()
    {
        this.trackSource    = new VectorSource();
        this.markerSource   = new VectorSource();
        this.playerSource   = new VectorSource();

        this.trackLayer =
            new VectorLayer({
                source:
                    this.trackSource
            });

        this.markerLayer =
            new VectorLayer({
                source:
                    this.markerSource
            });

        this.playerLayer =
            new VectorLayer({
                source:
                    this.playerSource
            });

        this.map =
            new Map({
                target:
                    "track_map",
                layers:
                [
                    new TileLayer({
                        source:
                            new XYZ({
                                url:
                                    "/map/tiles/{z}/{x}/{y}"
                            })
                    }),
                    this.trackLayer,
                    this.markerLayer,
                    this.playerLayer
                ],
                view:
                    new View({
                        center:
                            fromLonLat(
                                CONFIG.map.center
                            ),
                        zoom:
                            CONFIG.map.zoom
                    }
                )
            }
        );
    }

    /**
     * Centrează harta pe traseu.
     *
     * @param {LineString} geometry
     */
    fit(geometry)
    {
        this.map
            .getView()
            .fit(
                geometry,
                {
                    padding:
                    [
                        60,
                        60,
                        60,
                        60
                    ],
                    duration: 700,
                    maxZoom: 18
                }
            );
    }


    /**
     * Curăță traseul și markerii.
     */
    clear()
    {
        this.trackSource.clear();
        this.markerSource.clear();
    }
}


// =====================================================
// LOADER CLASS
// =====================================================


/**
 * Încarcă date GPS din backend.
 */
class TrackLoader
{

    constructor()
    {
       this.controller = null;
    }

    /**
     * Încarcă sesiunile unei zile.
     *
     * @param {string} date
     *
     * @returns {Promise<Array>}
     */
    async sessions(date)
    {
        const response =
            await fetch(
                `${CONFIG.api.sessions}?date=${date}`
            );
        if(!response.ok)
        {
            throw new Error(
                "Sessions error"
            );
        }
        return await response.json();

    }

    /**
     * Încarcă punctele unui traseu.
     *
     * @param {number|string} id
     *
     * @returns {Promise<Array>}
     */
    async points(id)
    {
        if(this.controller)
        {
            this.controller.abort();
        }

        this.controller = new AbortController();
        const response =
            await fetch(
                `${CONFIG.api.points}/${id}/points`,
                {
                    signal:
                        this.controller.signal
                }
            );

        if(!response.ok)
        {
            throw new Error(
                "Track error"
            );
        }

        const data = await response.json();
        return this.normalize(data);
    }

    /**
     * Normalizează date GPS.
     *
     * @param {Array} points
     *
     * @returns {Array}
     */
    normalize(points)
    {
        return points
            .filter(point =>
                point.latitude !== null &&
                point.longitude !== null
            )
            .map(point =>
            ({
                latitude:
                    Number(
                        point.latitude
                    ),
                longitude:
                    Number(
                        point.longitude
                    ),
                speed:
                    Number(
                        point.speed || 0
                    ),
                altitude:
                    Number(
                        point.altitude || 0
                    ),
                timestamp:
                    new Date(
                        point.timestamp
                    )
            }
        ));
    }
}

// =====================================================
// RENDERER CLASS
// =====================================================


/**
 * Desenează traseul GPS pe hartă.
 */
class TrackRenderer
{
    /**
     * @param {TrackMap} map
     */
    constructor(map)
    {
        this.map = map;
    }

    /**
     * Desenează traseul segment cu segment.
     *
     * Fiecare segment primește culoare
     * în funcție de viteză.
     *
     * @param {Array} points
     */
    draw(points)
    {
        for(
            let i = 1;
            i < points.length;
            i++
        )
        {
            const previous  = points[i - 1];
            const current   = points[i];
            const geometry =
                new LineString([
                    fromLonLat([
                        previous.longitude,
                        previous.latitude
                    ]),
                    fromLonLat([
                        current.longitude,
                        current.latitude
                    ])
                ]);

            const feature =
                new Feature({
                    geometry
                });

            feature.setStyle(
                this.style(
                    current.speed
                )
            );

            this.map.trackSource.addFeature(
                feature
            );
        }






        const fullLine =
            new LineString(
                points.map(point =>
                    fromLonLat([
                        point.longitude,
                        point.latitude
                    ])
                )
            );

        this.map.fit(
            fullLine
        );
    }

    /**
     * Stil traseu după viteză.
     *
     * @param {number} speed
     *
     * @returns {Style}
     */
    style(speed)
    {


        let color = "#dc3545";
        if(speed > 20)
        {
            color = "#198754";
        }
        else if(speed > 5)
        {
            color = "#ffc107";
        }

        return new Style({
            stroke:
                new Stroke({
                    color,
                    width: 4
                }
            )
        });
    }
}

// =====================================================
// MARKERS CLASS
// =====================================================


/**
 * Gestionează markerii start/final.
 */
class TrackMarkers
{


    /**
     * @param {TrackMap} map
     */
    constructor(map)
    {

        this.map =
            map;

    }






    /**
     * Desenează markerii.
     *
     * @param {Array} points
     */
    draw(points)
    {


        if(!points.length)
        {
            return;
        }






        this.map.markerSource.addFeature(

            this.create(

                points[0],

                "#198754"

            )

        );






        this.map.markerSource.addFeature(

            this.create(

                points.at(-1),

                "#dc3545"

            )

        );


    }






    /**
     * Creează marker.
     *
     * @param {Object} point
     *
     * @param {string} color
     *
     * @returns {Feature}
     */
    create(point,color)
    {


        const feature =

            new Feature({

                geometry:

                    new Point(

                        fromLonLat([

                            point.longitude,

                            point.latitude

                        ])

                    )

            });






        feature.setStyle(

            new Style({

                image:

                    new Circle({

                        radius:
                            8,


                        fill:

                            new Fill({

                                color

                            })

                    })

            })

        );






        return feature;

    }


}









// =====================================================
// STATISTICS CLASS
// =====================================================


/**
 * Calculează statisticile traseului.
 */
class TrackStatistics
{


    /**
     * Calculează statistici.
     *
     * @param {Array} points
     *
     * @returns {Object}
     */
    calculate(points)
    {


        let distance =
            0;


        let elevation =
            0;


        let maxSpeed =
            0;






        for(
            let i = 1;
            i < points.length;
            i++
        )
        {


            distance +=

                this.haversine(

                    points[i - 1],

                    points[i]

                );





            maxSpeed =

                Math.max(

                    maxSpeed,

                    points[i].speed

                );






            const diff =

                points[i].altitude -

                points[i-1].altitude;





            if(diff > 0)
            {
                elevation +=
                    diff;
            }


        }






        const seconds =

            (

                points.at(-1).timestamp

                -

                points[0].timestamp

            )

            /

            1000;







        return {

            distance:

                (

                    distance / 1000

                )

                .toFixed(2),




            duration:

                this.formatDuration(

                    seconds

                ),




            avgSpeed:

                (

                    (

                        distance / 1000

                    )

                    /

                    (

                        seconds / 3600

                    )

                )

                .toFixed(1),




            maxSpeed:

                maxSpeed.toFixed(1),




            elevation:

                elevation.toFixed(0)

        };


    }








    /**
     * Formula Haversine.
     *
     * @returns {number}
     */
    haversine(a,b)
    {


        const R =
            6371000;



        const lat1 =

            a.latitude *

            Math.PI /

            180;



        const lat2 =

            b.latitude *

            Math.PI /

            180;




        const dLat =

            (

                b.latitude -

                a.latitude

            )

            *

            Math.PI /

            180;





        const dLon =

            (

                b.longitude -

                a.longitude

            )

            *

            Math.PI /

            180;






        const x =

            Math.sin(dLat/2) ** 2

            +

            Math.cos(lat1)

            *

            Math.cos(lat2)

            *

            Math.sin(dLon/2) ** 2;







        return

            R *

            2 *

            Math.atan2(

                Math.sqrt(x),

                Math.sqrt(1-x)

            );


    }






    /**
     * Formatare durată.
     *
     * @param {number} seconds
     *
     * @returns {string}
     */
    formatDuration(seconds)
    {


        const h =

            Math.floor(

                seconds / 3600

            );



        const m =

            Math.floor(

                seconds % 3600 / 60

            );





        return `${h}h ${m}m`;

    }


}








// =====================================================
// POPUP CLASS
// =====================================================


/**
 * Popup pentru punct GPS.
 */
class TrackPopup
{


    /**
     * @param {TrackMap} map
     */
    constructor(map)
    {


        this.map =
            map;


        this.overlay =
            null;



        this.init();

    }






    /**
     * Inițializează popup.
     */
    init()
    {


        const element =

            document.createElement(
                "div"
            );



        element.className =
            "track-popup";





        this.overlay =

            new Overlay({

                element,

                positioning:
                    "bottom-center",


                offset:
                [
                    0,
                    -15
                ]

            });





        this.map.map.addOverlay(

            this.overlay

        );


    }






    /**
     * Afișează punct.
     *
     * @param {Object} point
     *
     * @param {Array} coordinate
     */
    show(point,coordinate)
    {


        const element =

            this.overlay.getElement();





        element.innerHTML = `

            <strong>
                GPS Point
            </strong>

            <br>

            🕒
            ${point.timestamp.toLocaleString()}

            <br>

            🚗
            ${point.speed.toFixed(1)}
            km/h

            <br>

            ⛰
            ${point.altitude}
            m

        `;





        this.overlay.setPosition(

            coordinate

        );


    }


}

// =====================================================
// PLAYER CLASS
// =====================================================


/**
 * Motor playback traseu GPS.
 *
 * Folosește requestAnimationFrame
 * pentru mișcare fluidă.
 */
class TrackPlayer
{


    /**
     * @param {TrackMap} map
     */
    constructor(map)
    {


        this.map =
            map;


        this.points =
            [];


        this.marker =
            null;


        this.index =
            0;


        this.running =
            false;


        this.frame =
            null;


        this.speed =
            1;


    }







    /**
     * Încarcă traseul.
     *
     * @param {Array} points
     */
    load(points)
    {


        this.points =
            points;


        this.index =
            0;


        this.createMarker();



        if(points.length)
        {
            this.moveTo(
                points[0]
            );
        }


    }







    /**
     * Creează marker playback.
     */
    createMarker()
    {


        if(this.marker)
        {
            return;
        }





        this.marker =

            new Feature({

                geometry:

                    new Point(

                        fromLonLat([

                            0,

                            0

                        ])

                    )

            });






        this.marker.setStyle(

            new Style({

                image:

                    new Circle({

                        radius:
                            10,


                        fill:

                            new Fill({

                                color:
                                    "#0d6efd"

                            })

                    })

            })

        );






        this.map.playerSource.addFeature(

            this.marker

        );


    }








    /**
     * Start playback.
     */
    play()
    {


        console.log("PLAY pressed");
        console.log("points:", this.points.length);
        console.log("index:", this.index);

        if(!this.points.length)
        {
            console.warn("No points loaded");
            return;
        }

        this.running =
            true;



        this.animate();


    }






    /**
     * Stop playback.
     */
    pause()
    {


        this.running =
            false;




        if(this.frame)
        {

            cancelAnimationFrame(

                this.frame

            );

        }

    }






    /**
     * Reset playback.
     */
    reset()
    {


        this.pause();


        this.index =
            0;



        if(this.points.length)
        {

            this.moveTo(

                this.points[0]

            );

        }


    }






    /**
     * Animația principală.
     */
    animate()
    {


        if(!this.running)
        {
            return;
        }





        if(
            this.index >=
            this.points.length - 1
        )
        {

            this.pause();

            return;

        }






        const start =

            this.points[
                this.index
            ];



        const end =

            this.points[
                this.index + 1
            ];







        const duration =

            Math.max(

                (

                    end.timestamp -

                    start.timestamp

                )

                /

                this.speed,


                500

            );





        const begin =
            performance.now();







        const step =

            now =>
            {


                const progress =

                    Math.min(

                        (

                            now -

                            begin

                        )

                        /

                        duration,


                        1

                    );





                const position =

                    this.interpolate(

                        start,

                        end,

                        progress

                    );






                this.moveTo(

                    position

                );






                if(progress < 1)
                {

                    this.frame =

                        requestAnimationFrame(

                            step

                        );

                }
                else
                {

                    this.index++;


                    this.animate();

                }


            };






        this.frame =

            requestAnimationFrame(

                step

            );


    }







    /**
     * Interpolare coordonate.
     *
     * @returns {Object}
     */
    interpolate(a,b,t)
    {


        return {

            latitude:

                a.latitude +

                (

                    b.latitude -

                    a.latitude

                )

                *

                t,



            longitude:

                a.longitude +

                (

                    b.longitude -

                    a.longitude

                )

                *

                t


        };


    }







    /**
     * Mută marker.
     *
     * @param {Object} point
     */
    moveTo(point)
    {


        if(!this.marker)
        {
            return;
        }



        this.marker

            .getGeometry()

            .setCoordinates(

                fromLonLat([

                    point.longitude,

                    point.latitude

                ])

            );


    }


}







// =====================================================
// INITIALIZARE UNICĂ
// =====================================================


const trackMap =
    new TrackMap();


const loader =
    new TrackLoader();


const renderer =
    new TrackRenderer(
        trackMap
    );


const markers =
    new TrackMarkers(
        trackMap
    );


const statistics =
    new TrackStatistics();


const popup =
    new TrackPopup(
        trackMap
    );


const player =
    new TrackPlayer(
        trackMap
    );





let currentPoints = [];







// =====================================================
// HELPERS UI
// =====================================================


/**
 * Actualizează statisticile.
 *
 * @param {Object} data
 */
function showStats(data)
{


    if(!statsBox)
    {
        return;
    }




    statsBox.innerHTML = `

        <div>
            📏 ${data.distance} km
        </div>

        <div>
            ⏱ ${data.duration}
        </div>

        <div>
            🚗 ${data.avgSpeed} km/h
        </div>

        <div>
            ⚡ ${data.maxSpeed} km/h
        </div>

        <div>
            ⛰ ${data.elevation} m
        </div>

    `;

}






/**
 * Caută punct GPS apropiat.
 */
function findNearestPoint(coordinate)
{


    let nearest =
        null;


    let min =
        Infinity;





    currentPoints.forEach(point =>
    {


        const gps =

            fromLonLat([

                point.longitude,

                point.latitude

            ]);





        const distance =

            Math.sqrt(

                Math.pow(

                    coordinate[0] -
                    gps[0],

                    2

                )

                +

                Math.pow(

                    coordinate[1] -
                    gps[1],

                    2

                )

            );





        if(distance < min)
        {

            min =
                distance;


            nearest =
                point;

        }


    });





    return nearest;

}








// =====================================================
// EVENTS
// =====================================================


trackDay?.addEventListener(

    "change",

    async event =>
    {


        const date =
            event.target.value;



        if(!date)
        {
            return;
        }



        const sessions =

            await loader.sessions(

                date

            );




        trackSelect.innerHTML =

            `
            <option value="">
                Select Track
            </option>
            `;





        sessions.forEach(session =>
        {


            const option =

                document.createElement(
                    "option"
                );



            option.value =
                session.id;



            option.textContent =

                new Date(

                    session.started_at

                )

                .toLocaleString();




            trackSelect.appendChild(
                option
            );


        });


    }

);


trackSelect?.addEventListener(

    "change",

    async event =>
    {


        const id =
            event.target.value;



        if(!id)
        {
            return;
        }

        currentPoints =

            await loader.points(

                id

            );

        trackMap.clear();

        renderer.draw(

            currentPoints

        );

        markers.draw(

            currentPoints

        );

        showStats(

            statistics.calculate(

                currentPoints

            )

        );

        player.load(

            currentPoints

        );
    }
);

trackMap.map.on(

    "click",

    event =>
    {


        const point =

            findNearestPoint(

                event.coordinate

            );

        if(point)
        {

            popup.show(

                point,

                event.coordinate

            );
        }
    }
);

playButton?.addEventListener(

    "click",

    () =>
    {

        player.play();

    }
);

pauseButton?.addEventListener(

    "click",

    () =>
    {

        player.pause();

    }
);

resetButton?.addEventListener(

    "click",

    () =>
    {

        player.reset();

    }
);

speedSelect?.addEventListener(

    "change",

    event =>
    {

        player.speed =

            Number(

                event.target.value

            );

    }
);