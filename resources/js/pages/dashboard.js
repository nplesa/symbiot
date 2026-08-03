const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.content;

const trackingSwitch = document.getElementById('trackingmyself');

let watchId = null;
let lastPosition = null;
let lastSavedTime = null;
let trackingStarted = false;

let options = {
    enableHighAccuracy: true,
    maximumAge: 5000,
    timeout: 10000
};

//----------------------------------------------------

function showError(error) {

    switch (error.code) {

        case error.PERMISSION_DENIED:
            alert("Permisiunea pentru localizare a fost refuzată.");
            break;

        case error.POSITION_UNAVAILABLE:
            alert("Poziția nu este disponibilă.");
            break;

        case error.TIMEOUT:
            alert("Obținerea poziției a expirat.");
            break;

        default:
            alert("Eroare necunoscută.");
    }
}

//----------------------------------------------------

function distanceInMeters(lat1, lon1, lat2, lon2) {

    const R = 6371000;

    const toRad = value => value * Math.PI / 180;

    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);

    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(lat1)) *
        Math.cos(toRad(lat2)) *
        Math.sin(dLon / 2) ** 2;

    return R * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
}

//----------------------------------------------------

async function post(url, body = {}) {

    return fetch(url, {

        method: 'POST',

        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
        },

        body: JSON.stringify(body)

    });

}

//----------------------------------------------------

async function savePosition(coords) {

    await post('/tracking/point', {

        latitude: coords.latitude,
        longitude: coords.longitude,
        accuracy: coords.accuracy,
        speed: coords.speed,
        heading: coords.heading,
        altitude: coords.altitude,
        tracked_at: new Date().toISOString()

    });

}

//----------------------------------------------------

async function onPosition(position) {

    const coords = position.coords;

    if (coords.accuracy > 30) {
        return;
    }

    const now = Date.now();

    if (!lastPosition) {

        await savePosition(coords);

        lastPosition = coords;
        lastSavedTime = now;

        return;
    }

    const meters = distanceInMeters(

        lastPosition.latitude,
        lastPosition.longitude,

        coords.latitude,
        coords.longitude

    );

    const seconds = (now - lastSavedTime) / 1000;

    if (meters < 15 && seconds < 15) {
        return;
    }

    await savePosition(coords);

    lastPosition = coords;
    lastSavedTime = now;
}

//----------------------------------------------------

async function startTracking() {

    watchId = navigator.geolocation.watchPosition(

        async (position) => {

            if (!trackingStarted) {

                await post('/tracking/start');

                trackingStarted = true;
                document.getElementById("i_tracking").classList.add("rotate3d-y");

                // primul punct se salvează întotdeauna
                await savePosition(position.coords);

                lastPosition = position.coords;
                lastSavedTime = Date.now();

                return;
            }

            await onPosition(position);

        },

        showError,

        options
    );

}

//----------------------------------------------------

async function stopTracking() {

    if (watchId !== null) {

        navigator.geolocation.clearWatch(watchId);

        watchId = null;
    }

    if (trackingStarted) {

        await post('/tracking/stop');
        document.getElementById("i_tracking").classList.remove("rotate3d-y");
        trackingStarted = false;
    }

    lastPosition = null;
    lastSavedTime = null;
}

//----------------------------------------------------

trackingSwitch?.addEventListener('change', async function () {

    if (!navigator.geolocation) {

        alert("Geolocation nu este suportată.");
        this.checked = false;
        return;
    }

    // salvează preferința utilizatorului
    await post('/tracking/toggle', {
        trackingmyself: this.checked
    });

    if (this.checked) {
        await startTracking();
    } else {
        await stopTracking();
    }

});

document.addEventListener('DOMContentLoaded', async () => {

    if (trackingSwitch?.checked) {
        await startTracking();
    }

});