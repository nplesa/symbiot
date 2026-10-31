
/* =========================================================
   SYMBIOT GPS TRACKING
   ========================================================= */

const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.content;

const trackingSwitch = document.getElementById('trackingmyself');

let watchId = null;
let lastPosition = null;
let lastSavedTime = null;
let trackingStarted = false;

const options = {
    enableHighAccuracy: true,
    maximumAge: 5000,
    timeout: 10000
};


/* =========================================================
   GPS ERRORS
   ========================================================= */

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


/* =========================================================
   DISTANCE
   ========================================================= */

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

    return R * (
        2 * Math.atan2(
            Math.sqrt(a),
            Math.sqrt(1 - a)
        )
    );

}


/* =========================================================
   HTTP POST
   ========================================================= */

async function post(url, body = {}) {

    const response = await fetch(url, {

        method: 'POST',

        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json'
        },

        body: JSON.stringify(body)

    });

    if (!response.ok) {
        throw new Error(`HTTP error: ${response.status}`);
    }

    return response;

}


/* =========================================================
   SAVE POSITION
   ========================================================= */

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


/* =========================================================
   POSITION UPDATE
   ========================================================= */

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


/* =========================================================
   TRACKING INDICATOR
   ========================================================= */

function setTrackingIndicator(active) {

    const icon = document.getElementById('i_tracking');

    if (!icon) return;

    icon.classList.toggle('rotate3d-y', active);

}


/* =========================================================
   START TRACKING
   ========================================================= */

async function startTracking() {

    if (watchId !== null) {
        return;
    }

    if (!navigator.geolocation) {

        alert("Geolocation nu este suportată.");

        if (trackingSwitch) {
            trackingSwitch.checked = false;
        }

        return;
    }

    watchId = navigator.geolocation.watchPosition(

        async position => {

            try {

                if (!trackingStarted) {

                    await post('/tracking/start');

                    trackingStarted = true;

                    setTrackingIndicator(true);

                    await savePosition(position.coords);

                    lastPosition = position.coords;
                    lastSavedTime = Date.now();

                    return;
                }

                await onPosition(position);

            } catch (error) {

                console.error('Tracking error:', error);

            }

        },

        error => {

            showError(error);

            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }

        },

        options

    );

}


/* =========================================================
   STOP TRACKING
   ========================================================= */

async function stopTracking() {

    if (watchId !== null) {

        navigator.geolocation.clearWatch(watchId);

        watchId = null;

    }

    if (trackingStarted) {

        try {

            await post('/tracking/stop');

        } catch (error) {

            console.error('Stop tracking error:', error);

        }

        trackingStarted = false;

        setTrackingIndicator(false);

    }

    lastPosition = null;
    lastSavedTime = null;

}


/* =========================================================
   TRACKING SWITCH
   ========================================================= */

trackingSwitch?.addEventListener('change', async function () {

    if (!navigator.geolocation) {

        alert("Geolocation nu este suportată.");

        this.checked = false;

        return;
    }

    try {

        await post('/tracking/toggle', {
            trackingmyself: this.checked
        });

        if (this.checked) {

            await startTracking();

        } else {

            await stopTracking();

        }

    } catch (error) {

        console.error('Tracking toggle error:', error);

        this.checked = !this.checked;

        alert('Nu s-a putut modifica starea trackingului.');

    }

});


/* =========================================================
   INITIAL TRACKING
   ========================================================= */

document.addEventListener('DOMContentLoaded', async () => {

    if (trackingSwitch?.checked) {
        await startTracking();
    }

});


/* =========================================================
   SYMBIOT NAVIGATION & THEME
   ========================================================= */

document.addEventListener('DOMContentLoaded', () => {

    const shell = document.getElementById('symbiotShell');

    if (!shell) return;

    const sidebar = document.getElementById('symbiotSidebar');

    const mobileButton = document.getElementById(
        'sidebarMobileToggle'
    );

    const mobileClose = document.getElementById(
        'sidebarMobileClose'
    );

    const overlay = document.getElementById(
        'sidebarOverlay'
    );

    const collapseButton = document.getElementById(
        'sidebarCollapse'
    );

    const themeButton = document.getElementById(
        'themeToggle'
    );


    /* =====================================================
       THEME
       ===================================================== */

    function applyTheme(theme) {

        shell.dataset.theme = theme;

        const icon = document.getElementById('themeIcon');

        if (icon) {

            icon.className = theme === 'dark'
                ? 'fa-solid fa-sun'
                : 'fa-solid fa-moon';

        }

    }

    const savedTheme = localStorage.getItem('symbiot-theme');

    const systemDark = window.matchMedia(
        '(prefers-color-scheme: dark)'
    ).matches;

    applyTheme(
        savedTheme || (systemDark ? 'dark' : 'light')
    );

    themeButton?.addEventListener('click', () => {

        const nextTheme =
            shell.dataset.theme === 'dark'
                ? 'light'
                : 'dark';

        applyTheme(nextTheme);

        localStorage.setItem(
            'symbiot-theme',
            nextTheme
        );

    });


    /* =====================================================
       DEVICE DETECTION
       ===================================================== */

    const isMobile = () => window.innerWidth < 768;

    const isTablet = () =>
        window.innerWidth >= 768 &&
        window.innerWidth < 1200;


    /* =====================================================
       MOBILE MENU
       ===================================================== */

    function openMobileMenu() {

        if (!isMobile()) return;

        shell.classList.add('mobile-menu-open');

        document.body.classList.add('symbiot-menu-active');

        mobileButton?.setAttribute(
            'aria-expanded',
            'true'
        );

        overlay?.setAttribute(
            'aria-hidden',
            'false'
        );

    }

    function closeMobileMenu() {

        shell.classList.remove('mobile-menu-open');

        document.body.classList.remove('symbiot-menu-active');

        mobileButton?.setAttribute(
            'aria-expanded',
            'false'
        );

        overlay?.setAttribute(
            'aria-hidden',
            'true'
        );

    }


    /* =====================================================
       MOBILE EVENTS
       ===================================================== */

    mobileButton?.addEventListener(
        'click',
        openMobileMenu
    );

    mobileClose?.addEventListener(
        'click',
        closeMobileMenu
    );

    overlay?.addEventListener(
        'click',
        closeMobileMenu
    );


    /* Close menu after navigation */

    sidebar?.querySelectorAll('a').forEach(link => {

        link.addEventListener('click', () => {

            if (isMobile()) {
                closeMobileMenu();
            }

        });

    });


    /* =====================================================
       DESKTOP / TABLET COLLAPSE
       ===================================================== */

    collapseButton?.addEventListener('click', () => {

        if (isMobile()) return;

        if (isTablet()) {

            shell.classList.toggle('sidebar-expanded');

            return;
        }

        const collapsed = shell.classList.toggle(
            'sidebar-collapsed'
        );

        localStorage.setItem(
            'symbiot-sidebar-collapsed',
            String(collapsed)
        );

    });


    /* =====================================================
       RESTORE SIDEBAR STATE
       ===================================================== */

    function restoreSidebar() {

        if (isMobile()) {

            shell.classList.remove(
                'sidebar-collapsed',
                'sidebar-expanded',
                'mobile-menu-open'
            );

            document.body.classList.remove(
                'symbiot-menu-active'
            );

            mobileButton?.setAttribute(
                'aria-expanded',
                'false'
            );

            return;
        }

        closeMobileMenu();

        if (isTablet()) {

            shell.classList.remove(
                'sidebar-collapsed',
                'sidebar-expanded'
            );

            return;
        }

        const collapsed =
            localStorage.getItem(
                'symbiot-sidebar-collapsed'
            ) === 'true';

        shell.classList.toggle(
            'sidebar-collapsed',
            collapsed
        );

        shell.classList.remove('sidebar-expanded');

    }

    restoreSidebar();


    /* =====================================================
       WINDOW RESIZE
       ===================================================== */

    let resizeTimeout;

    window.addEventListener('resize', () => {

        clearTimeout(resizeTimeout);

        resizeTimeout = setTimeout(() => {

            restoreSidebar();

        }, 100);

    });


    /* =====================================================
       ESCAPE KEY
       ===================================================== */

    document.addEventListener('keydown', event => {

        if (event.key === 'Escape') {

            closeMobileMenu();

            shell.classList.remove('sidebar-expanded');

        }

    });

});
