/**
 * Track viewer module
 *
 * Responsabilități:
 * - inițializează harta OpenLayers;
 * - încarcă sesiunile disponibile;
 * - încarcă punctele GPS ale unei sesiuni;
 * - pregătește datele pentru afișare.
 *
 * Date așteptate de la API:
 *
 * TrackPoint:
 * {
 *    latitude: number,
 *    longitude: number,
 *    timestamp: string,
 *    speed: number,
 *    altitude: number
 * }
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

import {
    Stroke,
    Style,
    Circle,
    Fill
} from "ol/style";

import { fromLonLat } from "ol/proj";


// =====================================================
// TIPURI DE DATE
// =====================================================


/**
 * Punct GPS primit de la server.
 *
 * @typedef {Object} TrackPoint
 * @property {number} latitude
 * @property {number} longitude
 * @property {string} timestamp
 * @property {number} speed
 * @property {number} altitude
 */


/**
 * Statistici calculate pentru un traseu.
 *
 * @typedef {Object} TrackStats
 * @property {string} distance
 * @property {string} duration
 * @property {string} avgSpeed
 * @property {string} maxSpeed
 * @property {string} elevation
 */


// =====================================================
// ELEMENTE DOM
// =====================================================


/**
 * Selector pentru ziua de tracking.
 *
 * Exemplu:
 * <select id="trackDay">
 */
const trackDay =
    document.getElementById("trackDay");


/**
 * Selector pentru lista de trasee.
 *
 * Exemplu:
 * <select id="trackDate">
 */
const trackSelect =
    document.getElementById("trackDate");



/**
 * Container pentru statistici.
 *
 * Exemplu:
 * <div id="trackStats"></div>
 */
const statsBox =
    document.getElementById("trackStats");



// =====================================================
// OPENLAYERS SOURCES
// =====================================================


/**
 * Sursa pentru traseul GPS.
 *
 * Conține segmentele LineString.
 */
const trackSource =
    new VectorSource();



/**
 * Sursa pentru markeri.
 *
 * Conține:
 * - punct start;
 * - punct final.
 */
const markerSource =
    new VectorSource();



// =====================================================
// OPENLAYERS LAYERS
// =====================================================


/**
 * Layer pentru traseu.
 */
const trackLayer =
    new VectorLayer({

        source:
            trackSource

    });



/**
 * Layer pentru markeri.
 */
const markerLayer =
    new VectorLayer({

        source:
            markerSource

    });




// =====================================================
// INITIALIZARE HARTĂ
// =====================================================


/**
 * Inițializează harta OpenLayers.
 *
 * Straturi:
 * - tile map;
 * - traseu GPS;
 * - markeri start/final.
 */
const map =
    new Map({

        target:
            "track_map",


        layers:[

            new TileLayer({

                source:
                    new XYZ({

                        url:
                            "/map/tiles/{z}/{x}/{y}"

                    })

            }),


            trackLayer,

            markerLayer

        ],


        view:
            new View({

                center:
                    fromLonLat([

                        25.6012,
                        45.6579

                    ]),


                zoom:
                    12

            })

    });



// =====================================================
// CONTROL REQUEST
// =====================================================


/**
 * Controller pentru anularea request-urilor vechi.
 *
 * Exemplu:
 * user schimbă rapid traseul A -> B.
 * Request-ul pentru A este anulat.
 */
let controller = null;



// =====================================================
// LOAD SESSIONS
// =====================================================


/**
 * Încarcă sesiunile de tracking pentru o anumită zi.
 *
 * Endpoint:
 * GET /tracking/sessions?date=YYYY-MM-DD
 *
 * @async
 *
 * @param {string} date
 * Data selectată.
 *
 * @returns {Promise<void>}
 */
async function loadSessions(date)
{

    trackSelect.innerHTML =
        `
        <option value="">
            Loading...
        </option>
        `;


    try {


        const response =
            await fetch(
                `/tracking/sessions?date=${date}`
            );


        if(!response.ok)
        {
            throw new Error(
                "Unable to load sessions"
            );
        }


        const sessions =
            await response.json();



        trackSelect.innerHTML =
            `
            <option value="">
                Select Track
            </option>
            `;



        if(!sessions.length)
        {

            trackSelect.innerHTML =
                `
                <option value="">
                    No tracks found
                </option>
                `;

            return;

        }



        sessions.forEach(session => {


            const option =
                document.createElement(
                    "option"
                );


            option.value =
                session.id;



            option.textContent =
                `${formatDate(session.started_at)}
                 -
                 ${formatTime(session.ended_at)}`;



            trackSelect.appendChild(
                option
            );


        });



    }
    catch(error)
    {

        console.error(error);


        trackSelect.innerHTML =
            `
            <option value="">
                Error loading tracks
            </option>
            `;

    }

}



// =====================================================
// LOAD + DRAW TRACK
// =====================================================


/**
 * Încarcă punctele GPS ale unei sesiuni.
 *
 * Endpoint:
 * GET /tracking/{id}/points
 *
 * După încărcare:
 * - validează punctele;
 * - curăță harta;
 * - desenează traseul;
 * - adaugă markerii;
 * - calculează statisticile.
 *
 * @async
 *
 * @param {number|string} sessionId
 *
 * @returns {Promise<void>}
 */
async function drawTrack(sessionId)
{


    if(controller)
    {
        controller.abort();
    }



    controller =
        new AbortController();



    try
    {


        const response =
            await fetch(

                `/tracking/${sessionId}/points`,

                {
                    signal:
                        controller.signal
                }

            );



        if(!response.ok)
        {
            throw new Error(
                "Unable to load track"
            );
        }



        /** @type {TrackPoint[]} */
        const points =
            await response.json();



        const validPoints =
            points.filter(point =>

                point.latitude !== null &&
                point.longitude !== null

            );



        if(!validPoints.length)
        {
            return;
        }



        clearMap();


        drawSpeedTrack(
            validPoints
        );


        drawMarkers(
            validPoints
        );


        showStats(
            calculateStats(validPoints)
        );



        const coordinates =
            validPoints.map(point =>

                fromLonLat([

                    Number(point.longitude),

                    Number(point.latitude)

                ])

            );



        map.getView().fit(

            new LineString(
                coordinates
            ),

            {

                padding:[
                    60,
                    60,
                    60,
                    60
                ],

                duration:
                    700,

                maxZoom:
                    18

            }

        );


    }
    catch(error)
    {

        if(error.name !== "AbortError")
        {
            console.error(error);
        }

    }

}

// =====================================================
// DESENARE TRASEU DUPĂ VITEZĂ
// =====================================================


/**
 * Desenează traseul segment cu segment.
 *
 * Fiecare segment primește o culoare
 * în funcție de viteza din punctul final.
 *
 * Reguli:
 * - < 5 km/h  -> roșu
 * - 5-20 km/h -> galben
 * - > 20 km/h -> verde
 *
 * @param {TrackPoint[]} points
 *
 * @returns {void}
 */
function drawSpeedTrack(points)
{

    for(let i = 1; i < points.length; i++)
    {

        const start =
            fromLonLat([

                Number(points[i - 1].longitude),
                Number(points[i - 1].latitude)

            ]);


        const end =
            fromLonLat([

                Number(points[i].longitude),
                Number(points[i].latitude)

            ]);



        const feature =
            new Feature({

                geometry:
                    new LineString([

                        start,
                        end

                    ])

            });



        feature.setStyle(

            speedStyle(
                points[i].speed
            )

        );



        trackSource.addFeature(
            feature
        );

    }

}




// =====================================================
// STYLE TRASEU
// =====================================================


/**
 * Creează stilul unui segment
 * în funcție de viteză.
 *
 * @param {number} speed
 *
 * @returns {Style}
 */
function speedStyle(speed)
{

    speed =
        Number(speed || 0);



    let color =
        "#dc3545";


    if(speed > 20)
    {
        color =
            "#198754";
    }
    else if(speed > 5)
    {
        color =
            "#ffc107";
    }



    return new Style({

        stroke:
            new Stroke({

                color,

                width:
                    4

            })

    });

}



// =====================================================
// MARKERI START / FINAL
// =====================================================


/**
 * Adaugă marker pentru începutul
 * și sfârșitul traseului.
 *
 * @param {TrackPoint[]} points
 *
 * @returns {void}
 */
function drawMarkers(points)
{

    const start =
        points[0];


    const end =
        points[points.length - 1];



    markerSource.addFeature(

        createMarker(
            start,
            "#198754"
        )

    );



    markerSource.addFeature(

        createMarker(
            end,
            "#dc3545"
        )

    );

}




/**
 * Creează un marker OpenLayers
 * pentru un punct GPS.
 *
 * @param {TrackPoint} point
 *
 * @param {string} color
 *
 * @returns {Feature}
 */
function createMarker(point, color)
{

    const feature =
        new Feature({

            geometry:
                new Point(

                    fromLonLat([

                        Number(point.longitude),

                        Number(point.latitude)

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



// =====================================================
// STATISTICI TRACK
// =====================================================


/**
 * Calculează statisticile traseului.
 *
 * Calculează:
 * - distanță totală;
 * - viteză maximă;
 * - viteză medie;
 * - durata;
 * - diferența pozitivă de altitudine.
 *
 * @param {TrackPoint[]} points
 *
 * @returns {TrackStats}
 */
function calculateStats(points)
{

    let distance =
        0;


    let maxSpeed =
        0;


    let elevation =
        0;



    for(let i = 1; i < points.length; i++)
    {


        distance +=
            haversine(

                points[i - 1],

                points[i]

            );



        maxSpeed =
            Math.max(

                maxSpeed,

                Number(
                    points[i].speed || 0
                )

            );



        const altitudeDiff =

            Number(points[i].altitude || 0)

            -

            Number(points[i - 1].altitude || 0);



        if(altitudeDiff > 0)
        {
            elevation +=
                altitudeDiff;
        }


    }



    const duration =

        (

            new Date(
                points.at(-1).timestamp
            )

            -

            new Date(
                points[0].timestamp
            )

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

            formatDuration(
                duration
            ),



        avgSpeed:

            (

                (

                    distance / 1000

                )

                /

                (

                    duration / 3600

                )

            )

            .toFixed(1),



        maxSpeed:

            maxSpeed.toFixed(1),



        elevation:

            elevation.toFixed(0)

    };

}




// =====================================================
// DISTANȚĂ GPS
// =====================================================


/**
 * Calculează distanța dintre două puncte GPS
 * folosind formula Haversine.
 *
 * @param {TrackPoint} a
 *
 * @param {TrackPoint} b
 *
 * @returns {number}
 * Distanța în metri.
 */
function haversine(a, b)
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



    const value =

        Math.sin(dLat / 2) ** 2

        +

        Math.cos(lat1)

        *

        Math.cos(lat2)

        *

        Math.sin(dLon / 2) ** 2;



    return (

        R *

        2 *

        Math.atan2(

            Math.sqrt(value),

            Math.sqrt(1 - value)

        )

    );

}



// =====================================================
// UI HELPERS
// =====================================================


/**
 * Curăță toate elementele desenate pe hartă.
 *
 * @returns {void}
 */
function clearMap()
{

    trackSource.clear();

    markerSource.clear();

}



/**
 * Afișează statisticile traseului.
 *
 * @param {TrackStats} stats
 *
 * @returns {void}
 */
function showStats(stats)
{

    if(!statsBox)
    {
        return;
    }



    statsBox.innerHTML = `

        <div>
            Distance:
            ${stats.distance} km
        </div>

        <div>
            Duration:
            ${stats.duration}
        </div>

        <div>
            Average speed:
            ${stats.avgSpeed} km/h
        </div>

        <div>
            Maximum speed:
            ${stats.maxSpeed} km/h
        </div>

        <div>
            Elevation gain:
            ${stats.elevation} m
        </div>

    `;

}




/**
 * Formatează data sesiunii.
 *
 * @param {string} value
 *
 * @returns {string}
 */
function formatDate(value)
{

    return new Date(value)
        .toLocaleString();

}




/**
 * Formatează ora.
 *
 * @param {string} value
 *
 * @returns {string}
 */
function formatTime(value)
{

    if(!value)
    {
        return "...";
    }



    return new Date(value)
        .toLocaleTimeString([], {

            hour:
                "2-digit",

            minute:
                "2-digit"

        });

}




/**
 * Transformă secunde în format lizibil.
 *
 * @param {number} seconds
 *
 * @returns {string}
 */
function formatDuration(seconds)
{

    const hours =
        Math.floor(
            seconds / 3600
        );


    const minutes =
        Math.floor(

            (

                seconds % 3600

            )

            /

            60

        );



    return `${hours}h ${minutes}m`;

}



// =====================================================
// EVENTS
// =====================================================


/**
 * Schimbare zi:
 * încarcă traseele disponibile.
 */
trackDay?.addEventListener(
    "change",
    event =>
    {

        if(!event.target.value)
        {
            return;
        }


        loadSessions(
            event.target.value
        );

    }
);




/**
 * Schimbare traseu:
 * afișează traseul selectat.
 */
trackSelect?.addEventListener(
    "change",
    event =>
    {

        if(!event.target.value)
        {

            clearMap();

            return;

        }



        drawTrack(
            event.target.value
        );

    }
);