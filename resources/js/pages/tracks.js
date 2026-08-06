/**
 * GPS Track Viewer
 *
 * Funcționalități:
 * - afișare hartă OpenLayers;
 * - încărcare sesiuni de tracking;
 * - afișare traseu GPS;
 * - colorare traseu după viteză;
 * - markere start/final;
 * - statistici traseu;
 * - popup informații punct GPS;
 * - pregătire pentru playback.
 */


// =====================================================
// IMPORTURI OPENLAYERS
// =====================================================

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
 *
 * @property {number} latitude
 * @property {number} longitude
 * @property {string} timestamp
 * @property {number} speed
 * @property {number} altitude
 */


/**
 * Statistici traseu.
 *
 * @typedef {Object} TrackStats
 *
 * @property {string} distance
 * @property {string} duration
 * @property {string} avgSpeed
 * @property {string} maxSpeed
 * @property {string} elevation
 */



// =====================================================
// ELEMENTE HTML
// =====================================================


/**
 * Selector pentru ziua selectată.
 */
const trackDay =
    document.getElementById(
        "trackDay"
    );



/**
 * Selector pentru sesiunea GPS.
 */
const trackSelect =
    document.getElementById(
        "trackDate"
    );



/**
 * Container statistici.
 */
const statsBox =
    document.getElementById(
        "trackStats"
    );




// =====================================================
// SURSE VECTOR
// =====================================================


/**
 * Sursă OpenLayers pentru segmentele traseului.
 */
const trackSource =
    new VectorSource();



/**
 * Sursă OpenLayers pentru markere.
 */
const markerSource =
    new VectorSource();





// =====================================================
// LAYERE
// =====================================================


/**
 * Layer traseu GPS.
 */
const trackLayer =
    new VectorLayer({

        source:
            trackSource

    });



/**
 * Layer markere start/final.
 */
const markerLayer =
    new VectorLayer({

        source:
            markerSource

    });




// =====================================================
// HARTA
// =====================================================


/**
 * Instanța principală OpenLayers.
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
// VARIABILE GLOBALE TRACKING
// =====================================================


/**
 * Controller pentru anularea requesturilor.
 *
 * Evită afișarea unui traseu vechi
 * când utilizatorul schimbă rapid selecția.
 */
let controller = null;



/**
 * Ultimul traseu încărcat.
 *
 * Folosit pentru:
 * - popup;
 * - playback.
 *
 * @type {TrackPoint[]}
 */
let currentTrackPoints = [];



/**
 * Popup OpenLayers.
 */
let popupOverlay = null;



// =====================================================
// LOAD SESIUNI
// =====================================================


/**
 * Încarcă sesiunile disponibile pentru o zi.
 *
 * Endpoint:
 *
 * GET /tracking/sessions?date=YYYY-MM-DD
 *
 * @async
 *
 * @param {string} date
 *
 * @returns {Promise<void>}
 */
async function loadSessions(date)
{

    trackSelect.innerHTML =
        `
        <option>
            Loading...
        </option>
        `;


    try
    {

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
                <option>
                    No tracks found
                </option>
                `;

            return;

        }




        sessions.forEach(session =>
        {

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
            <option>
                Error loading tracks
            </option>
            `;

    }

}




// =====================================================
// LOAD TRACK
// =====================================================


/**
 * Încarcă punctele GPS ale unei sesiuni.
 *
 * Endpoint:
 *
 * GET /tracking/{sessionId}/points
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



        /**
         * @type {TrackPoint[]}
         */
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




        currentTrackPoints =
            validPoints;



        clearMap();



        drawSpeedTrack(
            validPoints
        );


        drawMarkers(
            validPoints
        );


        showStats(
            calculateStats(
                validPoints
            )
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
 * Desenează traseul GPS segment cu segment.
 *
 * Fiecare segment primește o culoare
 * în funcție de viteza de deplasare.
 *
 * Culori:
 * - roșu    = staționare / viteză mică
 * - galben   = deplasare medie
 * - verde    = deplasare rapidă
 *
 * @param {TrackPoint[]} points
 *
 * @returns {void}
 */
function drawSpeedTrack(points)
{

    for(let i = 1; i < points.length; i++)
    {


        const previous =
            points[i - 1];


        const current =
            points[i];



        const start =
            fromLonLat([

                Number(previous.longitude),

                Number(previous.latitude)

            ]);



        const end =
            fromLonLat([

                Number(current.longitude),

                Number(current.latitude)

            ]);




        const segment =
            new Feature({

                geometry:

                    new LineString([

                        start,

                        end

                    ])

            });




        segment.setStyle(

            speedStyle(
                current.speed
            )

        );



        trackSource.addFeature(
            segment
        );

    }

}




// =====================================================
// STYLE SEGMENT
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
// MARKERE START / FINAL
// =====================================================


/**
 * Creează markerii de început
 * și sfârșit ai traseului.
 *
 * @param {TrackPoint[]} points
 *
 * @returns {void}
 */
function drawMarkers(points)
{

    const start =
        points[0];



    const finish =
        points[
            points.length - 1
        ];




    markerSource.addFeature(

        createMarker(

            start,

            "#198754"

        )

    );



    markerSource.addFeature(

        createMarker(

            finish,

            "#dc3545"

        )

    );

}





/**
 * Creează un marker OpenLayers.
 *
 * @param {TrackPoint} point
 *
 * @param {string} color
 *
 * @returns {Feature}
 */
function createMarker(point, color)
{


    const marker =
        new Feature({

            geometry:

                new Point(

                    fromLonLat([

                        Number(point.longitude),

                        Number(point.latitude)

                    ])

                )

        });





    marker.setStyle(

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




    return marker;

}




// =====================================================
// STATISTICI TRACK
// =====================================================


/**
 * Calculează informațiile principale
 * despre traseu.
 *
 * Calcule:
 * - distanță totală;
 * - durată;
 * - viteză medie;
 * - viteză maximă;
 * - urcare acumulată.
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






        const altitudeDifference =

            Number(
                points[i].altitude || 0
            )

            -

            Number(
                points[i - 1].altitude || 0
            );




        if(altitudeDifference > 0)
        {

            elevation +=
                altitudeDifference;

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
// DISTANȚĂ GPS - HAVERSINE
// =====================================================


/**
 * Calculează distanța dintre două puncte GPS.
 *
 * Rezultat:
 * metri.
 *
 * @param {TrackPoint} a
 *
 * @param {TrackPoint} b
 *
 * @returns {number}
 */
function haversine(a, b)
{


    const earthRadius =
        6371000;




    const lat1 =

        a.latitude *

        Math.PI /

        180;




    const lat2 =

        b.latitude *

        Math.PI /

        180;





    const deltaLat =

        (

            b.latitude -

            a.latitude

        )

        *

        Math.PI /

        180;





    const deltaLon =

        (

            b.longitude -

            a.longitude

        )

        *

        Math.PI /

        180;





    const value =


        Math.sin(
            deltaLat / 2
        )

        ** 2



        +



        Math.cos(lat1)

        *

        Math.cos(lat2)

        *

        Math.sin(
            deltaLon / 2
        )

        ** 2;





    return


        earthRadius

        *

        2

        *

        Math.atan2(

            Math.sqrt(value),

            Math.sqrt(1 - value)

        );

}

// =====================================================
// POPUP GPS
// =====================================================


/**
 * Creează popup-ul OpenLayers.
 *
 * Popup-ul este folosit pentru afișarea
 * informațiilor unui punct GPS.
 *
 * @returns {void}
 */
function createPopup()
{

    const element =
        document.createElement(
            "div"
        );


    element.className =
        "track-popup";



    popupOverlay =
        new Overlay({

            element,

            positioning:
                "bottom-center",

            offset:[
                0,
                -15
            ],

            stopEvent:
                false

        });



    map.addOverlay(
        popupOverlay
    );

}




// Inițializare popup după crearea hărții
createPopup();





// =====================================================
// CLICK PE HARTĂ
// =====================================================


/**
 * Eveniment click pe hartă.
 *
 * Caută punctul GPS cel mai apropiat
 * de poziția selectată și afișează
 * informațiile lui.
 */
map.on(
    "click",
    event =>
    {


        if(!currentTrackPoints.length)
        {
            return;
        }




        const point =
            findNearestPoint(
                event.coordinate
            );





        if(!point)
        {
            return;
        }





        showPointInfo(

            point,

            event.coordinate

        );


    }
);





// =====================================================
// IDENTIFICARE PUNCT APROPIAT
// =====================================================


/**
 * Găsește punctul GPS cel mai apropiat
 * de coordonata selectată pe hartă.
 *
 * @param {number[]} coordinate
 *
 * @returns {TrackPoint|null}
 */
function findNearestPoint(coordinate)
{


    let nearest =
        null;



    let minimumDistance =
        Infinity;





    currentTrackPoints.forEach(point =>
    {


        const pointCoordinate =

            fromLonLat([

                Number(point.longitude),

                Number(point.latitude)

            ]);





        const distance =

            Math.sqrt(

                Math.pow(

                    coordinate[0] -
                    pointCoordinate[0],

                    2

                )

                +

                Math.pow(

                    coordinate[1] -
                    pointCoordinate[1],

                    2

                )

            );






        if(distance < minimumDistance)
        {

            minimumDistance =
                distance;


            nearest =
                point;

        }


    });





    return nearest;

}





// =====================================================
// AFIȘARE INFORMAȚII PUNCT
// =====================================================


/**
 * Afișează datele punctului selectat.
 *
 * @param {TrackPoint} point
 *
 * @param {number[]} coordinate
 *
 * @returns {void}
 */
function showPointInfo(point, coordinate)
{


    const element =
        popupOverlay.getElement();




    element.innerHTML = `

        <div>

            <strong>
                GPS Point
            </strong>

            <br><br>


            🕒
            ${formatDate(point.timestamp)}


            <br>


            🚗
            ${Number(point.speed || 0)
                .toFixed(1)}
            km/h


            <br>


            ⛰
            ${Number(point.altitude || 0)}
            m


            <br>


            📍
            ${Number(point.latitude)
                .toFixed(6)},

            ${Number(point.longitude)
                .toFixed(6)}

        </div>

    `;




    popupOverlay.setPosition(
        coordinate
    );

}





// =====================================================
// HELPERS
// =====================================================


/**
 * Curăță traseul și markerii.
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
            📏
            ${stats.distance} km
        </div>


        <div>
            ⏱
            ${stats.duration}
        </div>


        <div>
            🚗
            ${stats.avgSpeed} km/h
        </div>


        <div>
            ⚡
            ${stats.maxSpeed} km/h
        </div>


        <div>
            ⛰
            ${stats.elevation} m
        </div>


    `;

}




/**
 * Formatează data.
 *
 * @param {string|Date} value
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
 * @param {string|Date} value
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
 * Formatează durata.
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
// EVENIMENTE UI
// =====================================================


/**
 * Schimbare zi.
 *
 * Încarcă sesiunile disponibile.
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
 * Schimbare sesiune.
 *
 * Desenează traseul selectat.
 */
trackSelect?.addEventListener(
    "change",
    event =>
    {


        if(!event.target.value)
        {

            clearMap();

            currentTrackPoints = [];

            return;

        }




        drawTrack(
            event.target.value
        );


    }
);





// =====================================================
// PLAYBACK - PLACEHOLDER
// =====================================================


/**
 * Punct de extensie pentru playback.
 *
 * Datele necesare există deja:
 *
 * currentTrackPoints
 *
 * Următorul pas:
 *
 * - marker animat;
 * - sincronizare timestamp;
 * - butoane Play/Pause.
 *
 * @returns {void}
 */
function startPlayback()
{

    console.log(
        "Playback ready"
    );

}